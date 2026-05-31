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
use Stea\FacturaElectronica\Dtos\NotaDebitoDto;
use Stea\FacturaElectronica\Dtos\ReceptorDto;
use Stea\FacturaElectronica\Dtos\UbicacionDto;
use Stea\FacturaElectronica\Enums\Situacion;
use Stea\FacturaElectronica\Signing\XadesEpesSigner;
use Stea\FacturaElectronica\Xml\NotaDebitoXmlBuilder;
use Stea\FacturaElectronica\Xml\XsdValidator;

/**
 * Schema-valid only; not byte-verified against a real accepted ND (no sample available).
 */
final class NotaDebitoXmlBuilderTest extends TestCase
{
    private function dto(): NotaDebitoDto
    {
        return new NotaDebitoDto(
            consecutivo: '00100001020000000001',
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
                    detalle: 'Cargo adicional sobre servicio facturado',
                    precioUnitario: 50.0,
                    montoTotal: 50.0,
                    subTotal: 50.0,
                    montoTotalLinea: 56.5,
                    baseImponible: 50.0,
                    impuestos: [
                        new ImpuestoDto(codigo: '01', codigoTarifa: '08', tarifa: 13.0, monto: 6.5),
                    ],
                ),
            ],
            informacionReferencia: new InformacionReferenciaDto(
                tipoDocIR: '01',
                numero: '50601012600310100000000100001010000000001100000001',
                fechaEmisionIR: new DateTimeImmutable('2026-01-03T10:00:00-06:00'),
                codigo: '04',
                razon: 'Referencia a factura electronica original',
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

    public function test_signed_nd_passes_xsd(): void
    {
        $fechaEmision = new DateTimeImmutable('2026-05-29T10:00:00-06:00');
        $clave = (new ClaveGenerator)->generate(
            cedula: '3101000000',
            fecha: $fechaEmision,
            consecutivo: '00100001020000000001',
            situacion: Situacion::Normal,
            codigoSeguridad: '00000001',
        );

        $builder = new NotaDebitoXmlBuilder;
        $doc = $builder->build($this->dto(), $clave);
        $signed = (new XadesEpesSigner)->sign($doc, $this->cert());

        $wire = new DOMDocument;
        $wire->loadXML((string) $signed->saveXML());

        $validator = new XsdValidator;
        $passes = $validator->validate($wire, $builder->xsdPath());

        $this->assertTrue($passes, 'Signed ND must pass XSD: '.implode('; ', $validator->errors()));
    }
}
