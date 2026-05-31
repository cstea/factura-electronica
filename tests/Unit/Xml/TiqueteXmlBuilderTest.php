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
use Stea\FacturaElectronica\Dtos\LineaDetalleDto;
use Stea\FacturaElectronica\Dtos\ReceptorDto;
use Stea\FacturaElectronica\Dtos\TiqueteDto;
use Stea\FacturaElectronica\Dtos\UbicacionDto;
use Stea\FacturaElectronica\Enums\Situacion;
use Stea\FacturaElectronica\Signing\XadesEpesSigner;
use Stea\FacturaElectronica\Xml\TiqueteXmlBuilder;
use Stea\FacturaElectronica\Xml\XsdValidator;

/**
 * Schema-valid only; not byte-verified against a real accepted TE (no sample available).
 */
final class TiqueteXmlBuilderTest extends TestCase
{
    private function dto(): TiqueteDto
    {
        return new TiqueteDto(
            consecutivo: '00100001040000000001',
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
            // TE: Receptor is optional — omit it to exercise the schema-valid absent-receptor path
            receptor: null,
            lineas: [
                new LineaDetalleDto(
                    numeroLinea: 1,
                    codigoCabys: '8314100000400',
                    cantidad: 1.0,
                    unidadMedida: 'h',
                    detalle: 'Servicio tiquete electronico prueba',
                    precioUnitario: 200.0,
                    montoTotal: 200.0,
                    subTotal: 200.0,
                    montoTotalLinea: 226.0,
                    baseImponible: 200.0,
                    impuestos: [
                        new ImpuestoDto(codigo: '01', codigoTarifa: '08', tarifa: 13.0, monto: 26.0),
                    ],
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

    public function test_signed_te_passes_xsd(): void
    {
        $fechaEmision = new DateTimeImmutable('2026-05-29T10:00:00-06:00');
        $clave = (new ClaveGenerator)->generate(
            cedula: '3101000000',
            fecha: $fechaEmision,
            consecutivo: '00100001040000000001',
            situacion: Situacion::Normal,
            codigoSeguridad: '00000001',
        );

        $builder = new TiqueteXmlBuilder;
        $doc = $builder->build($this->dto(), $clave);
        $signed = (new XadesEpesSigner)->sign($doc, $this->cert());

        $wire = new DOMDocument;
        $wire->loadXML((string) $signed->saveXML());

        $validator = new XsdValidator;
        $passes = $validator->validate($wire, $builder->xsdPath());

        $this->assertTrue($passes, 'Signed TE must pass XSD: '.implode('; ', $validator->errors()));
    }

    public function test_signed_te_with_receptor_passes_xsd(): void
    {
        $fechaEmision = new DateTimeImmutable('2026-05-29T10:00:00-06:00');
        $clave = (new ClaveGenerator)->generate(
            cedula: '3101000000',
            fecha: $fechaEmision,
            consecutivo: '00100001040000000002',
            situacion: Situacion::Normal,
            codigoSeguridad: '00000001',
        );

        $dtoWithReceptor = new TiqueteDto(
            consecutivo: '00100001040000000002',
            fechaEmision: $fechaEmision,
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
                nombre: 'COMPRADOR ANONIMO',
            ),
            lineas: [
                new LineaDetalleDto(
                    numeroLinea: 1,
                    codigoCabys: '8314100000400',
                    cantidad: 1.0,
                    unidadMedida: 'h',
                    detalle: 'Servicio tiquete con receptor',
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
            condicionVenta: '01',
            medioPago: '04',
            moneda: 'CRC',
            tipoCambio: 1.0,
        );

        $builder = new TiqueteXmlBuilder;
        $doc = $builder->build($dtoWithReceptor, $clave);
        $signed = (new XadesEpesSigner)->sign($doc, $this->cert());

        $wire = new DOMDocument;
        $wire->loadXML((string) $signed->saveXML());

        $validator = new XsdValidator;
        $passes = $validator->validate($wire, $builder->xsdPath());

        $this->assertTrue($passes, 'Signed TE with receptor must pass XSD: '.implode('; ', $validator->errors()));
    }
}
