<?php

declare(strict_types=1);

/**
 * GATED SANDBOX EMISSION TEST — FEC (Factura Electrónica de Compra, tipo 08)
 *
 * This test is intentionally manual/gated. It does NOT mock HTTP — it fires
 * a real FEC against Hacienda's api-stag environment and verifies acceptance.
 *
 * To run:
 *   FE_SANDBOX=1 \
 *   FE_USERNAME=your@email.cr \
 *   FE_PASSWORD=yourPassword \
 *   FE_PIN=1234 \
 *   FE_P12_PATH=/absolute/path/to/cert.p12 \
 *   vendor/bin/phpunit tests/Feature/SandboxFecTest.php
 *
 * Without FE_SANDBOX=1 the test is silently skipped — CI stays green.
 */

namespace Stea\FacturaElectronica\Tests\Feature;

use DateTimeImmutable;
use Orchestra\Testbench\TestCase;
use Stea\FacturaElectronica\Dtos\EmisorDto;
use Stea\FacturaElectronica\Dtos\FacturaCompraDto;
use Stea\FacturaElectronica\Dtos\IdentificacionDto;
use Stea\FacturaElectronica\Dtos\ImpuestoDto;
use Stea\FacturaElectronica\Dtos\InformacionReferenciaDto;
use Stea\FacturaElectronica\Dtos\LineaDetalleDto;
use Stea\FacturaElectronica\Dtos\ReceptorDto;
use Stea\FacturaElectronica\Dtos\UbicacionDto;
use Stea\FacturaElectronica\Enums\EstadoComprobante;
use Stea\FacturaElectronica\Enums\TipoDocumento;
use Stea\FacturaElectronica\Exceptions\HaciendaRejectedException;
use Stea\FacturaElectronica\FacturaElectronicaManager;
use Stea\FacturaElectronica\FacturaElectronicaServiceProvider;

final class SandboxFecTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Testbench wiring
    // -------------------------------------------------------------------------

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [FacturaElectronicaServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Real sandbox environment — credentials come from env vars supplied by
        // the operator; they are never committed to the repository.
        $app['config']->set('factura-electronica.environment', 'sandbox');
        $app['config']->set('factura-electronica.credentials.username', (string) getenv('FE_USERNAME'));
        $app['config']->set('factura-electronica.credentials.password', (string) getenv('FE_PASSWORD'));
        $app['config']->set('factura-electronica.certificate.path', (string) getenv('FE_P12_PATH'));
        $app['config']->set('factura-electronica.certificate.pin', (string) getenv('FE_PIN'));
    }

    // -------------------------------------------------------------------------
    // Gate: real FEC against api-stag
    // -------------------------------------------------------------------------

    public function test_emits_fec_to_sandbox_and_receives_aceptado(): void
    {
        // ── Gate ──────────────────────────────────────────────────────────────
        // Skip unless the operator has explicitly opted in to real-network calls.
        if (getenv('FE_SANDBOX') !== '1') {
            $this->markTestSkipped('Set FE_SANDBOX=1 + FE_USERNAME/FE_PASSWORD/FE_PIN/FE_P12_PATH to run.');
        }

        // ── Build a fresh, collision-safe consecutivo ─────────────────────────
        // Format: sucursal(001) + terminal(00001) + tipo(08) + secuencia(10 digits)
        // We use a high random sequence (9 000 000 000–9 999 999 999) to avoid
        // colliding with any real submitted invoices.
        $secuencia = str_pad((string) random_int(9_000_000_000, 9_999_999_999), 10, '0', STR_PAD_LEFT);
        $consecutivo = '001'.'00001'.'08'.$secuencia; // 20 digits total

        $this->assertSame(20, strlen($consecutivo), 'Consecutivo must be exactly 20 digits.');

        // ── Seed from FacturaCompraXmlBuilderTest values ──────────────────────
        // Foreign supplier emisor (idType 05), local receptor 3101000000,
        // codigoActividadReceptor 6201.0, one line, moneda USD.
        $dto = new FacturaCompraDto(
            consecutivo: $consecutivo,
            fechaEmision: new DateTimeImmutable('now'),   // fresh timestamp for sandbox
            proveedorSistemas: '2100042005',
            codigoActividadEmisor: '0000.2',
            codigoActividadReceptor: '6201.0',
            // Emisor = foreign supplier (idType 05 + OtrasSenasExtranjero)
            emisor: new EmisorDto(
                nombre: 'ACME SERVICES LLC',
                identificacion: new IdentificacionDto('05', '000000000'),
                correoElectronico: 'supplier@example.com',
                otrasSenasExtranjero: '100 Business Ave, Anytown, CA 90000',
            ),
            // Receptor = local buyer with Ubicacion
            receptor: new ReceptorDto(
                nombre: 'MI EMPRESA S.A.',
                identificacion: new IdentificacionDto('02', '3101000000'),
                ubicacion: new UbicacionDto(
                    provincia: '1',
                    canton: '01',
                    distrito: '01',
                    otrasSenas: '123 Calle Principal, San Jose',
                ),
                correoElectronico: 'emisor@example.com',
            ),
            lineas: [
                new LineaDetalleDto(
                    numeroLinea: 1,
                    codigoCabys: '8315900000200',
                    cantidad: 1.0,
                    unidadMedida: 'Unid',
                    detalle: 'Servicio de almacenamiento de datos',
                    precioUnitario: 9.99,
                    montoTotal: 9.99,
                    subTotal: 9.99,
                    montoTotalLinea: 9.99,
                    impuestos: [
                        new ImpuestoDto(codigo: '01', codigoTarifa: '10', tarifa: 0.0, monto: 0.0),
                    ],
                    baseImponible: 9.99,
                ),
            ],
            // InformacionReferencia is REQUIRED by the FEC schema (v4.4).
            // TipoDocIR 16 = otro; Codigo 11 = anula documento de referencia externo.
            informacionReferencia: new InformacionReferenciaDto(
                tipoDocIR: '16',
                numero: 'TEST'.random_int(100000, 999999),
                fechaEmisionIR: new DateTimeImmutable('now'),
                codigo: '11',
                razon: 'PRUEBA',
            ),
            condicionVenta: '01',
            medioPago: '02',
            moneda: 'USD',
            tipoCambio: 457.93,
        );

        // ── Emit ──────────────────────────────────────────────────────────────
        /** @var FacturaElectronicaManager $manager */
        $manager = $this->app->make(FacturaElectronicaManager::class);

        $result = $manager->emitir(TipoDocumento::FacturaCompra, $dto);

        $this->assertSame(EstadoComprobante::Enviado, $result->estado, 'emitir() must return estado=enviado after a 202 from Hacienda.');
        $this->assertSame(50, strlen($result->clave), 'Clave must be exactly 50 digits.');

        // ── Poll for final estado ─────────────────────────────────────────────
        // Hacienda's api-stag processes asynchronously; poll until it moves out
        // of the pending states or until the attempt limit is reached.
        $maxAttempts = 10;
        $pollIntervalSeconds = 3;
        $finalEstado = null;
        $finalRespuestaXml = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            sleep($pollIntervalSeconds);

            try {
                $consulta = $manager->consultar($result->clave);
                $finalEstado = $consulta->estado;
                $finalRespuestaXml = $consulta->respuestaXml;
            } catch (HaciendaRejectedException $e) {
                // consultar() throws when estado=rechazado — capture and fail with detail.
                $this->fail(
                    "Hacienda RECHAZADO on attempt {$attempt} for clave {$result->clave}.\n".
                    "respuestaXml:\n".$e->respuestaXml,
                );
            }

            // Break as soon as we have a terminal estado.
            if (! in_array($finalEstado, [EstadoComprobante::Procesando, EstadoComprobante::Recibido], true)) {
                break;
            }
        }

        // ── Assert final outcome ──────────────────────────────────────────────
        $this->assertSame(
            EstadoComprobante::Aceptado,
            $finalEstado,
            "Expected estado=aceptado after polling {$maxAttempts} times for clave {$result->clave}. ".
            "Got: {$finalEstado->value}.\nrespuestaXml:\n{$finalRespuestaXml}",
        );
    }
}
