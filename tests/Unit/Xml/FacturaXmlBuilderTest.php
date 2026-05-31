<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Tests\Unit\Xml;

use DateTimeImmutable;
use DOMDocument;
use PHPUnit\Framework\TestCase;
use Stea\FacturaElectronica\Clave\ClaveGenerator;
use Stea\FacturaElectronica\Credentials\SigningCertificate;
use Stea\FacturaElectronica\Dtos\EmisorDto;
use Stea\FacturaElectronica\Dtos\FacturaDto;
use Stea\FacturaElectronica\Dtos\IdentificacionDto;
use Stea\FacturaElectronica\Dtos\ImpuestoDto;
use Stea\FacturaElectronica\Dtos\LineaDetalleDto;
use Stea\FacturaElectronica\Dtos\ReceptorDto;
use Stea\FacturaElectronica\Dtos\TelefonoDto;
use Stea\FacturaElectronica\Dtos\UbicacionDto;
use Stea\FacturaElectronica\Enums\Situacion;
use Stea\FacturaElectronica\Signing\XadesEpesSigner;
use Stea\FacturaElectronica\Xml\FacturaXmlBuilder;
use Stea\FacturaElectronica\Xml\XsdValidator;

/**
 * Schema-valid only — uses fully synthetic data (no real cédulas, names, or addresses).
 */
final class FacturaXmlBuilderTest extends TestCase
{
    private function dto(): FacturaDto
    {
        return new FacturaDto(
            consecutivo: '00100001010000000001',
            fechaEmision: new DateTimeImmutable('2026-01-01T10:00:00-06:00'),
            proveedorSistemas: '2100042005',
            codigoActividadEmisor: '6201.0',
            codigoActividadReceptor: '6201.0',
            emisor: new EmisorDto(
                nombre: 'EMISOR DE PRUEBA S.A.',
                identificacion: new IdentificacionDto('02', '3101000000'),
                ubicacion: new UbicacionDto(
                    provincia: '1',
                    canton: '01',
                    distrito: '01',
                    otrasSenas: '123 Calle Principal, San Jose',
                ),
                correoElectronico: 'emisor@example.com',
                nombreComercial: 'EMISOR DE PRUEBA',
                telefono: new TelefonoDto('506', '22000000'),
            ),
            receptor: new ReceptorDto(
                nombre: 'RECEPTOR DE PRUEBA S.A.',
                identificacion: new IdentificacionDto('02', '3101999999'),
                correoElectronico: 'receptor@example.com',
                ubicacion: new UbicacionDto(
                    provincia: '1',
                    canton: '01',
                    distrito: '01',
                    otrasSenas: '456 Avenida Central, San Jose',
                ),
            ),
            lineas: [
                new LineaDetalleDto(
                    numeroLinea: 1,
                    codigoCabys: '8314100000400',
                    cantidad: 1.0,
                    unidadMedida: 'h',
                    detalle: 'Servicio de consultoría prueba',
                    precioUnitario: 100.0,
                    montoTotal: 100.0,
                    subTotal: 100.0,
                    montoTotalLinea: 113.0,
                    impuestos: [
                        new ImpuestoDto(codigo: '01', codigoTarifa: '08', tarifa: 13.0, monto: 13.0),
                    ],
                    codigoComercialTipo: '01',
                    codigoComercial: 'SVC-001',
                    baseImponible: 100.0,
                ),
            ],
            condicionVenta: '01',
            medioPago: '04',
            moneda: 'CRC',
            tipoCambio: 1.0,
        );
    }

    private function cert(): SigningCertificate
    {
        return SigningCertificate::fromPath(__DIR__.'/../../fixtures/cert/test.p12', '1234');
    }

    public function test_signed_fe_passes_xsd(): void
    {
        $fechaEmision = new DateTimeImmutable('2026-01-01T10:00:00-06:00');
        $clave = (new ClaveGenerator)->generate(
            cedula: '3101000000',
            fecha: $fechaEmision,
            consecutivo: '00100001010000000001',
            situacion: Situacion::Normal,
            codigoSeguridad: '00000001',
        );

        $builder = new FacturaXmlBuilder;
        $doc = $builder->build($this->dto(), $clave);
        $signed = (new XadesEpesSigner)->sign($doc, $this->cert());

        $wire = new DOMDocument;
        $wire->loadXML((string) $signed->saveXML());

        $validator = new XsdValidator;
        $passes = $validator->validate($wire, $builder->xsdPath());

        $this->assertTrue($passes, 'Signed FE must pass XSD: '.implode('; ', $validator->errors()));
    }

    public function test_fe_contains_expected_root_and_clave(): void
    {
        $fechaEmision = new DateTimeImmutable('2026-01-01T10:00:00-06:00');
        $clave = (new ClaveGenerator)->generate(
            cedula: '3101000000',
            fecha: $fechaEmision,
            consecutivo: '00100001010000000001',
            situacion: Situacion::Normal,
            codigoSeguridad: '00000001',
        );

        $builder = new FacturaXmlBuilder;
        $xml = $builder->build($this->dto(), $clave)->saveXML();

        $this->assertStringContainsString('<FacturaElectronica', $xml);
        $this->assertStringContainsString($clave, $xml);
        $this->assertSame(50, strlen($clave));
    }
}
