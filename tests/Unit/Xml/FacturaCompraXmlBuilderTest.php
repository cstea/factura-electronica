<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Tests\Unit\Xml;

use DateTimeImmutable;
use DOMDocument;
use PHPUnit\Framework\TestCase;
use Stea\FacturaElectronica\Clave\ClaveGenerator;
use Stea\FacturaElectronica\Credentials\SigningCertificate;
use Stea\FacturaElectronica\Dtos\EmisorDto;
use Stea\FacturaElectronica\Dtos\FacturaCompraDto;
use Stea\FacturaElectronica\Dtos\IdentificacionDto;
use Stea\FacturaElectronica\Dtos\ImpuestoDto;
use Stea\FacturaElectronica\Dtos\InformacionReferenciaDto;
use Stea\FacturaElectronica\Dtos\LineaDetalleDto;
use Stea\FacturaElectronica\Dtos\ReceptorDto;
use Stea\FacturaElectronica\Dtos\UbicacionDto;
use Stea\FacturaElectronica\Enums\Situacion;
use Stea\FacturaElectronica\Signing\XadesEpesSigner;
use Stea\FacturaElectronica\Xml\FacturaCompraXmlBuilder;
use Stea\FacturaElectronica\Xml\XsdValidator;

/**
 * Schema-valid only — uses fully synthetic data (no real cédulas, names, or addresses).
 */
final class FacturaCompraXmlBuilderTest extends TestCase
{
    private function dto(): FacturaCompraDto
    {
        return new FacturaCompraDto(
            consecutivo: '00100001080000000001',
            fechaEmision: new DateTimeImmutable('2026-01-01T10:00:00-06:00'),
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
                    detalle: 'Servicio de almacenamiento en la nube',
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
            informacionReferencia: new InformacionReferenciaDto(
                tipoDocIR: '16',
                numero: 'INV-TEST-001',
                fechaEmisionIR: new DateTimeImmutable('2026-01-01T00:00:00.000'),
                codigo: '11',
                razon: 'PRUEBA',
            ),
            condicionVenta: '01',
            medioPago: '02',
            moneda: 'USD',
            tipoCambio: 500.0,
        );
    }

    private function cert(): SigningCertificate
    {
        return SigningCertificate::fromPath(__DIR__.'/../../fixtures/cert/test.p12', '1234');
    }

    public function test_signed_fec_passes_xsd(): void
    {
        $fechaEmision = new DateTimeImmutable('2026-01-01T10:00:00-06:00');
        $clave = (new ClaveGenerator)->generate(
            cedula: '3101000000',
            fecha: $fechaEmision,
            consecutivo: '00100001080000000001',
            situacion: Situacion::Normal,
            codigoSeguridad: '00000001',
        );

        $builder = new FacturaCompraXmlBuilder;
        $doc = $builder->build($this->dto(), $clave);
        $signed = (new XadesEpesSigner)->sign($doc, $this->cert());

        $wire = new DOMDocument;
        $wire->loadXML((string) $signed->saveXML());

        $validator = new XsdValidator;
        $passes = $validator->validate($wire, $builder->xsdPath());

        $this->assertTrue($passes, 'Signed FEC must pass XSD: '.implode('; ', $validator->errors()));
    }

    public function test_fec_contains_expected_root_and_clave(): void
    {
        $fechaEmision = new DateTimeImmutable('2026-01-01T10:00:00-06:00');
        $clave = (new ClaveGenerator)->generate(
            cedula: '3101000000',
            fecha: $fechaEmision,
            consecutivo: '00100001080000000001',
            situacion: Situacion::Normal,
            codigoSeguridad: '00000001',
        );

        $builder = new FacturaCompraXmlBuilder;
        $xml = $builder->build($this->dto(), $clave)->saveXML();

        $this->assertStringContainsString('<FacturaElectronicaCompra', $xml);
        $this->assertStringContainsString($clave, $xml);
        $this->assertSame(50, strlen($clave));
    }
}
