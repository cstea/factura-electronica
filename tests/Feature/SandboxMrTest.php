<?php

declare(strict_types=1);

/**
 * GATED SANDBOX EMISSION TEST — MR (Mensaje Receptor, tipo 05)
 *
 * This test is intentionally manual/gated. It does NOT mock HTTP: it issues a
 * real Factura Electrónica to the certificate's own taxpayer on api-stag, waits
 * for Hacienda to accept it, then acknowledges it with a Mensaje Receptor and
 * waits for that to be accepted too. An MR must reference a comprobante that
 * exists in the sandbox and was addressed to the signer, so it cannot be tested
 * against a synthetic clave.
 *
 * To run:
 *   FE_SANDBOX=1 \
 *   FE_USERNAME=your@email.cr \
 *   FE_PASSWORD=yourPassword \
 *   FE_PIN=1234 \
 *   FE_P12_PATH=/absolute/path/to/cert.p12 \
 *   vendor/bin/phpunit tests/Feature/SandboxMrTest.php
 *
 * Without FE_SANDBOX=1 the test is silently skipped — CI stays green.
 */

namespace Stea\FacturaElectronica\Tests\Feature;

use DateTimeImmutable;
use Orchestra\Testbench\TestCase;
use Stea\FacturaElectronica\Dtos\EmisorDto;
use Stea\FacturaElectronica\Dtos\FacturaDto;
use Stea\FacturaElectronica\Dtos\IdentificacionDto;
use Stea\FacturaElectronica\Dtos\ImpuestoDto;
use Stea\FacturaElectronica\Dtos\LineaDetalleDto;
use Stea\FacturaElectronica\Dtos\MensajeReceptorDto;
use Stea\FacturaElectronica\Dtos\ReceptorDto;
use Stea\FacturaElectronica\Dtos\UbicacionDto;
use Stea\FacturaElectronica\Enums\EstadoComprobante;
use Stea\FacturaElectronica\Enums\TipoDocumento;
use Stea\FacturaElectronica\Exceptions\HaciendaRejectedException;
use Stea\FacturaElectronica\FacturaElectronicaManager;
use Stea\FacturaElectronica\FacturaElectronicaServiceProvider;
use Stea\FacturaElectronica\Tests\Support\SandboxIdentity;

final class SandboxMrTest extends TestCase
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
    // Gate: real FE + MR against api-stag
    // -------------------------------------------------------------------------

    public function test_acknowledges_a_real_sandbox_fe_and_receives_aceptado(): void
    {
        if (getenv('FE_SANDBOX') !== '1') {
            $this->markTestSkipped('Set FE_SANDBOX=1 + FE_USERNAME/FE_PASSWORD/FE_PIN/FE_P12_PATH to run.');
        }

        // The MR signer is the FE's receptor, and the FE's signer its emisor:
        // with one certificate both are the same taxpayer.
        $id = SandboxIdentity::fromEnv();

        /** @var FacturaElectronicaManager $manager */
        $manager = $this->app->make(FacturaElectronicaManager::class);

        // ── 1. A real FE for the MR to acknowledge ─────────────────────────────
        $fechaFe = new DateTimeImmutable('now');
        $fe = $manager->emitir(TipoDocumento::Factura, $this->facturaFor($id, $fechaFe, $this->consecutivo('01')));
        $this->assertSame(EstadoComprobante::Aceptado, $this->pollUntilFinal($manager, $fe->clave), "FE {$fe->clave} must be accepted before it can be acknowledged.");

        // ── 2. The MR ─────────────────────────────────────────────────────────
        $consecutivoMr = $this->consecutivo('05');
        $mr = $manager->emitirMensajeReceptor(new MensajeReceptorDto(
            claveComprobante: $fe->clave,
            numeroCedulaEmisor: $id->cedula,
            fechaEmisionDoc: $fechaFe,
            mensaje: 1,                          // 1 = aceptado
            montoTotalImpuesto: 13.0,
            codigoActividad: self::ACTIVIDAD,
            condicionImpuesto: '01',
            montoTotalImpuestoAcreditar: 13.0,
            montoTotalDeGastoAplicable: 100.0,
            totalFactura: 113.0,
            numeroCedulaReceptor: $id->cedula,
            numeroConsecutivoReceptor: $consecutivoMr,
        ));
        $this->assertSame(EstadoComprobante::Enviado, $mr->estado);

        // Hacienda keys a Mensaje Receptor as "{clave}-{consecutivoReceptor}":
        // the bare clave answers for the FE, not for this message.
        $estado = $this->pollUntilFinal($manager, "{$fe->clave}-{$consecutivoMr}");
        $this->assertSame(EstadoComprobante::Aceptado, $estado, "MR {$fe->clave}-{$consecutivoMr} must be accepted.");
    }

    /** Economic activity the sandbox taxpayer is registered for (FEE/FEC pass with it). */
    private const ACTIVIDAD = '6201.0';

    /** sucursal 001 + terminal 00001 + tipo + a high random sequence, so runs never collide. */
    private function consecutivo(string $tipo): string
    {
        $consecutivo = '001'.'00001'.$tipo.str_pad((string) random_int(9_000_000_000, 9_999_999_999), 10, '0', STR_PAD_LEFT);
        $this->assertSame(20, strlen($consecutivo));

        return $consecutivo;
    }

    private function facturaFor(SandboxIdentity $id, DateTimeImmutable $fecha, string $consecutivo): FacturaDto
    {
        $ubicacion = new UbicacionDto(provincia: '1', canton: '01', distrito: '01', otrasSenas: 'Prueba de sandbox, San Jose');

        return new FacturaDto(
            consecutivo: $consecutivo,
            fechaEmision: $fecha,
            proveedorSistemas: '2100042005',
            codigoActividadEmisor: self::ACTIVIDAD,
            emisor: new EmisorDto(
                nombre: 'EMISOR DE PRUEBA',
                identificacion: new IdentificacionDto($id->tipo, $id->cedula),
                ubicacion: $ubicacion,
                correoElectronico: 'emisor@example.com',
            ),
            receptor: new ReceptorDto(
                nombre: 'RECEPTOR DE PRUEBA',
                identificacion: new IdentificacionDto($id->tipo, $id->cedula),
                correoElectronico: 'receptor@example.com',
                ubicacion: $ubicacion,
            ),
            lineas: [
                new LineaDetalleDto(
                    numeroLinea: 1,
                    codigoCabys: '8314100000400',
                    cantidad: 1.0,
                    unidadMedida: 'Sp',
                    detalle: 'Servicio de prueba sandbox',
                    precioUnitario: 100.0,
                    montoTotal: 100.0,
                    subTotal: 100.0,
                    montoTotalLinea: 113.0,
                    impuestos: [new ImpuestoDto(codigo: '01', codigoTarifa: '08', tarifa: 13.0, monto: 13.0)],
                    baseImponible: 100.0,
                ),
            ],
            codigoActividadReceptor: self::ACTIVIDAD,
        );
    }

    /**
     * Poll consulta until Hacienda leaves recibido/procesando. A rechazo fails
     * with Hacienda's own message rather than a bare estado.
     */
    private function pollUntilFinal(FacturaElectronicaManager $manager, string $consultaKey): EstadoComprobante
    {
        $estado = EstadoComprobante::Error;

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            sleep(3);

            try {
                $estado = $manager->consultar($consultaKey)->estado;
            } catch (HaciendaRejectedException $e) {
                $this->fail("Hacienda rejected {$consultaKey}:\n{$e->respuestaXml}");
            }

            if (! in_array($estado, [EstadoComprobante::Procesando, EstadoComprobante::Recibido], true)) {
                break;
            }
        }

        return $estado;
    }
}
