<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Tests\Unit\Xml;

use DateTimeImmutable;
use DOMDocument;
use PHPUnit\Framework\TestCase;
use Stea\FacturaElectronica\Clave\ClaveGenerator;
use Stea\FacturaElectronica\Credentials\SigningCertificate;
use Stea\FacturaElectronica\Dtos\EmisorDto;
use Stea\FacturaElectronica\Dtos\IdentificacionDto;
use Stea\FacturaElectronica\Dtos\ImpuestoDto;
use Stea\FacturaElectronica\Dtos\InformacionReferenciaDto;
use Stea\FacturaElectronica\Dtos\LineaDetalleDto;
use Stea\FacturaElectronica\Dtos\NotaCreditoDto;
use Stea\FacturaElectronica\Dtos\ReceptorDto;
use Stea\FacturaElectronica\Dtos\UbicacionDto;
use Stea\FacturaElectronica\Enums\Situacion;
use Stea\FacturaElectronica\Signing\XadesEpesSigner;
use Stea\FacturaElectronica\Xml\NotaCreditoXmlBuilder;
use Stea\FacturaElectronica\Xml\XsdValidator;

/**
 * Schema-valid only; not byte-verified against a real accepted NC (no sample available).
 */
final class NotaCreditoXmlBuilderTest extends TestCase
{
    private function dto(): NotaCreditoDto
    {
        return new NotaCreditoDto(
            consecutivo: '00100001030000000001',
            fechaEmision: new DateTimeImmutable('2026-05-29T10:00:00-06:00'),
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
                nombre: 'RECEPTOR PRUEBA SA',
                identificacion: new IdentificacionDto('02', '3101123456'),
            ),
            lineas: [
                new LineaDetalleDto(
                    numeroLinea: 1,
                    codigoCabys: '8314100000400',
                    cantidad: 1.0,
                    unidadMedida: 'h',
                    detalle: 'Credito por servicio facturado',
                    precioUnitario: 100.0,
                    montoTotal: 100.0,
                    subTotal: 100.0,
                    montoTotalLinea: 113.0,
                    baseImponible: 100.0,
                    impuestos: [
                        new ImpuestoDto(codigo: '01', codigoTarifa: '08', tarifa: 13.0, monto: 13.0),
                    ],
                ),
            ],
            informacionReferencia: new InformacionReferenciaDto(
                tipoDocIR: '01',
                numero: '50601012600310100000000100001010000000001100000001',
                fechaEmisionIR: new DateTimeImmutable('2026-01-03T10:00:00-06:00'),
                codigo: '01',
                razon: 'Anula factura electronica de referencia',
            ),
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

    public function test_signed_nc_passes_xsd(): void
    {
        $fechaEmision = new DateTimeImmutable('2026-05-29T10:00:00-06:00');
        $clave = (new ClaveGenerator)->generate(
            cedula: '3101000000',
            fecha: $fechaEmision,
            consecutivo: '00100001030000000001',
            situacion: Situacion::Normal,
            codigoSeguridad: '00000001',
        );

        $builder = new NotaCreditoXmlBuilder;
        $doc = $builder->build($this->dto(), $clave);
        $signed = (new XadesEpesSigner)->sign($doc, $this->cert());

        $wire = new DOMDocument;
        $wire->loadXML((string) $signed->saveXML());

        $validator = new XsdValidator;
        $passes = $validator->validate($wire, $builder->xsdPath());

        $this->assertTrue($passes, 'Signed NC must pass XSD: '.implode('; ', $validator->errors()));
    }
}
