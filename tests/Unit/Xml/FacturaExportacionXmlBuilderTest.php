<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Tests\Unit\Xml;

use DateTimeImmutable;
use DOMDocument;
use PHPUnit\Framework\TestCase;
use Stea\FacturaElectronica\Clave\ClaveGenerator;
use Stea\FacturaElectronica\Credentials\SigningCertificate;
use Stea\FacturaElectronica\Dtos\EmisorDto;
use Stea\FacturaElectronica\Dtos\FacturaExportacionDto;
use Stea\FacturaElectronica\Dtos\IdentificacionDto;
use Stea\FacturaElectronica\Dtos\ImpuestoDto;
use Stea\FacturaElectronica\Dtos\LineaDetalleDto;
use Stea\FacturaElectronica\Dtos\ReceptorDto;
use Stea\FacturaElectronica\Dtos\UbicacionDto;
use Stea\FacturaElectronica\Enums\Situacion;
use Stea\FacturaElectronica\Signing\XadesEpesSigner;
use Stea\FacturaElectronica\Xml\FacturaExportacionXmlBuilder;
use Stea\FacturaElectronica\Xml\XsdValidator;

/**
 * Schema-valid only — uses fully synthetic data (no real cédulas, names, or addresses).
 */
final class FacturaExportacionXmlBuilderTest extends TestCase
{
    private function dto(): FacturaExportacionDto
    {
        return new FacturaExportacionDto(
            consecutivo: '00100001090000000001',
            fechaEmision: new DateTimeImmutable('2026-01-01T10:00:00-06:00'),
            proveedorSistemas: '2100042005',
            codigoActividadEmisor: '6201.0',
            emisor: new EmisorDto(
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
            receptor: new ReceptorDto(
                nombre: 'FOREIGN CLIENT, INC',
                identificacion: new IdentificacionDto('05', '00-0000000'),
                otrasSenasExtranjero: '100 Example Street, Anytown, USA',
            ),
            lineas: [
                new LineaDetalleDto(
                    numeroLinea: 1,
                    codigoCabys: '8314100000400',
                    cantidad: 10.0,
                    unidadMedida: 'h',
                    detalle: 'Servicios de desarrollo de software',
                    precioUnitario: 50.0,
                    montoTotal: 500.0,
                    subTotal: 500.0,
                    montoTotalLinea: 500.0,
                    impuestos: [
                        new ImpuestoDto(codigo: '01', codigoTarifa: '10', tarifa: 0.0, monto: 0.0),
                    ],
                ),
            ],
            condicionVenta: '01',
            medioPago: '04',
            moneda: 'USD',
            tipoCambio: 500.0,
        );
    }

    private function cert(): SigningCertificate
    {
        return SigningCertificate::fromPath(__DIR__.'/../../fixtures/cert/test.p12', '1234');
    }

    public function test_signed_fee_passes_xsd(): void
    {
        $fechaEmision = new DateTimeImmutable('2026-01-01T10:00:00-06:00');
        $clave = (new ClaveGenerator)->generate(
            cedula: '3101000000',
            fecha: $fechaEmision,
            consecutivo: '00100001090000000001',
            situacion: Situacion::Normal,
            codigoSeguridad: '00000001',
        );

        $builder = new FacturaExportacionXmlBuilder;
        $doc = $builder->build($this->dto(), $clave);
        $signed = (new XadesEpesSigner)->sign($doc, $this->cert());

        $wire = new DOMDocument;
        $wire->loadXML((string) $signed->saveXML());

        $validator = new XsdValidator;
        $passes = $validator->validate($wire, $builder->xsdPath());

        $this->assertTrue($passes, 'Signed FEE must pass XSD: '.implode('; ', $validator->errors()));
    }

    public function test_fee_contains_expected_root_and_clave(): void
    {
        $fechaEmision = new DateTimeImmutable('2026-01-01T10:00:00-06:00');
        $clave = (new ClaveGenerator)->generate(
            cedula: '3101000000',
            fecha: $fechaEmision,
            consecutivo: '00100001090000000001',
            situacion: Situacion::Normal,
            codigoSeguridad: '00000001',
        );

        $builder = new FacturaExportacionXmlBuilder;
        $xml = $builder->build($this->dto(), $clave)->saveXML();

        $this->assertStringContainsString('<FacturaElectronicaExportacion', $xml);
        $this->assertStringContainsString($clave, $xml);
        $this->assertSame(50, strlen($clave));
    }
}
