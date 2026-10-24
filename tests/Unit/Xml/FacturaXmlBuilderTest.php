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
use Stea\FacturaElectronica\Dtos\InformacionReferenciaDto;
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

    // ── InformacionReferencia (nota 9 / nota 10) ────────────────────────────

    private function dtoConReferencia(InformacionReferenciaDto $ref): FacturaDto
    {
        $base = $this->dto();

        return new FacturaDto(
            consecutivo: $base->consecutivo,
            fechaEmision: $base->fechaEmision,
            proveedorSistemas: $base->proveedorSistemas,
            codigoActividadEmisor: $base->codigoActividadEmisor,
            emisor: $base->emisor,
            receptor: $base->receptor,
            lineas: $base->lineas,
            codigoActividadReceptor: $base->codigoActividadReceptor,
            condicionVenta: $base->condicionVenta,
            medioPago: $base->medioPago,
            moneda: $base->moneda,
            tipoCambio: $base->tipoCambio,
            informacionReferencia: $ref,
        );
    }

    /** A factura replacing one Hacienda rejected: TipoDocIR 10, codigo 16. */
    public function test_fe_with_informacion_referencia_passes_xsd_and_emits_the_block(): void
    {
        $fechaEmision = new DateTimeImmutable('2026-01-01T10:00:00-06:00');
        $clave = (new ClaveGenerator)->generate(
            cedula: '3101000000',
            fecha: $fechaEmision,
            consecutivo: '00100001010000000001',
            situacion: Situacion::Normal,
            codigoSeguridad: '00000001',
        );

        $dto = $this->dtoConReferencia(new InformacionReferenciaDto(
            tipoDocIR: '10',
            numero: '50601011226310100000000100001010000000001199999999',
            fechaEmisionIR: new DateTimeImmutable('2025-12-20T09:00:00-06:00'),
            codigo: '16',
            razon: 'Sustituye comprobante electrónico rechazado',
        ));

        $builder = new FacturaXmlBuilder;
        $doc = $builder->build($dto, $clave);
        $xml = (string) $doc->saveXML();

        $this->assertStringContainsString('<InformacionReferencia>', $xml);
        $this->assertStringContainsString('<Codigo>16</Codigo>', $xml);
        $this->assertStringContainsString('<TipoDocIR>10</TipoDocIR>', $xml);

        $signed = (new XadesEpesSigner)->sign($doc, $this->cert());
        $wire = new DOMDocument;
        $wire->loadXML((string) $signed->saveXML());

        $validator = new XsdValidator;
        $this->assertTrue(
            $validator->validate($wire, $builder->xsdPath()),
            'FE with InformacionReferencia must pass XSD: '.implode('; ', $validator->errors()),
        );
    }

    /** The block sits after ResumenFactura, which the v4.4 sequence requires. */
    public function test_informacion_referencia_follows_resumen_factura(): void
    {
        $clave = (new ClaveGenerator)->generate(
            cedula: '3101000000',
            fecha: new DateTimeImmutable('2026-01-01T10:00:00-06:00'),
            consecutivo: '00100001010000000001',
            situacion: Situacion::Normal,
            codigoSeguridad: '00000001',
        );

        $dto = $this->dtoConReferencia(new InformacionReferenciaDto(
            tipoDocIR: '01',
            numero: '50601011226310100000000100001010000000001199999999',
            fechaEmisionIR: new DateTimeImmutable('2025-12-20T09:00:00-06:00'),
            codigo: '15',
            razon: 'Sustituye comprobante electrónico por error material',
        ));

        $xml = (string) (new FacturaXmlBuilder)->build($dto, $clave)->saveXML();

        $this->assertLessThan(
            strpos($xml, '<InformacionReferencia>'),
            strpos($xml, '<ResumenFactura>'),
        );
    }

    /** Without a reference the block must not appear: it is optional (minOccurs 0). */
    public function test_fe_without_referencia_omits_the_block(): void
    {
        $clave = (new ClaveGenerator)->generate(
            cedula: '3101000000',
            fecha: new DateTimeImmutable('2026-01-01T10:00:00-06:00'),
            consecutivo: '00100001010000000001',
            situacion: Situacion::Normal,
            codigoSeguridad: '00000001',
        );

        $xml = (string) (new FacturaXmlBuilder)->build($this->dto(), $clave)->saveXML();

        $this->assertStringNotContainsString('<InformacionReferencia>', $xml);
    }

    // ── ResumenFactura: goods vs services categorisation ────────────────────

    /** @param LineaDetalleDto[] $lineas */
    private function dtoConLineas(array $lineas): FacturaDto
    {
        $base = $this->dto();

        return new FacturaDto(
            consecutivo: $base->consecutivo,
            fechaEmision: $base->fechaEmision,
            proveedorSistemas: $base->proveedorSistemas,
            codigoActividadEmisor: $base->codigoActividadEmisor,
            emisor: $base->emisor,
            receptor: $base->receptor,
            lineas: $lineas,
            codigoActividadReceptor: $base->codigoActividadReceptor,
            condicionVenta: $base->condicionVenta,
            medioPago: $base->medioPago,
            moneda: $base->moneda,
            tipoCambio: $base->tipoCambio,
        );
    }

    private function linea(int $n, string $cabys, float $monto, float $tarifa, string $codigoTarifa = ''): LineaDetalleDto
    {
        $impuesto = round($monto * ($tarifa / 100), 5);
        // '10' is exento; '01' is the no-sujeto tariff this builder keys on.
        $codigoTarifa = $codigoTarifa !== '' ? $codigoTarifa : ($tarifa > 0.0 ? '08' : '10');

        return new LineaDetalleDto(
            numeroLinea: $n,
            codigoCabys: $cabys,
            cantidad: 1.0,
            unidadMedida: 'Unid',
            detalle: 'Linea '.$n,
            precioUnitario: $monto,
            montoTotal: $monto,
            subTotal: $monto,
            montoTotalLinea: $monto + $impuesto,
            impuestos: [new ImpuestoDto(codigo: '01', codigoTarifa: $codigoTarifa, tarifa: $tarifa, monto: $impuesto)],
            baseImponible: $monto,
        );
    }

    /**
     * @return array<string, string> every ResumenFactura child keyed by tag.
     *
     * Note FacturaXmlBuilder::money() trims trailing zeros, so amounts read back
     * as '100' rather than '100.00000'.
     */
    private function resumen(FacturaDto $dto): array
    {
        $doc = (new FacturaXmlBuilder)->build($dto, str_repeat('5', 50));
        $resumen = $doc->getElementsByTagName('ResumenFactura')->item(0);
        self::assertNotNull($resumen);

        $out = [];
        foreach ($resumen->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->childNodes->length === 1 && $child->firstChild instanceof \DOMText) {
                $out[$child->nodeName] = trim($child->textContent);
            }
        }

        return $out;
    }

    /**
     * Regression: the mercancías totals were hardcoded to zero, so a goods line
     * was reported as a service no matter its CABYS code.
     */
    public function test_a_taxed_goods_line_lands_in_mercancias_gravadas(): void
    {
        $r = $this->resumen($this->dtoConLineas([$this->linea(1, '2321100000000', 100.0, 13.0)]));

        self::assertSame('100', $r['TotalMercanciasGravadas']);
        self::assertSame('0', $r['TotalServGravados']);
        self::assertSame('100', $r['TotalGravado']);
    }

    public function test_an_exempt_goods_line_lands_in_mercancias_exentas(): void
    {
        $r = $this->resumen($this->dtoConLineas([$this->linea(1, '2321100000000', 100.0, 0.0)]));

        self::assertSame('100', $r['TotalMercanciasExentas']);
        self::assertSame('0', $r['TotalServExentos']);
        self::assertSame('100', $r['TotalExento']);
    }

    public function test_services_lines_are_unchanged(): void
    {
        $r = $this->resumen($this->dtoConLineas([$this->linea(1, '8415000000000', 10.0, 13.0)]));

        self::assertSame('10', $r['TotalServGravados']);
        self::assertSame('0', $r['TotalMercanciasGravadas']);
        self::assertSame('10', $r['TotalGravado']);
    }

    public function test_a_mixed_invoice_splits_across_all_four_buckets(): void
    {
        $r = $this->resumen($this->dtoConLineas([
            $this->linea(1, '2321100000000', 100.0, 13.0),
            $this->linea(2, '3461000000000', 50.0, 0.0),
            $this->linea(3, '8415000000000', 20.0, 13.0),
            $this->linea(4, '9231000000000', 5.0, 0.0),
        ]));

        self::assertSame('100', $r['TotalMercanciasGravadas']);
        self::assertSame('50', $r['TotalMercanciasExentas']);
        self::assertSame('20', $r['TotalServGravados']);
        self::assertSame('5', $r['TotalServExentos']);
        self::assertSame('120', $r['TotalGravado']);
        self::assertSame('55', $r['TotalExento']);
        self::assertSame('175', $r['TotalVenta']);
    }

    /** A no-sujeto goods line belongs in TotalMercNoSujeta, not the services one. */
    public function test_a_no_sujeto_goods_line_lands_in_merc_no_sujeta(): void
    {
        $r = $this->resumen($this->dtoConLineas([$this->linea(1, '2321100000000', 40.0, 0.0, '01')]));

        self::assertSame('40', $r['TotalMercNoSujeta']);
        self::assertSame('0', $r['TotalServNoSujeto']);
        self::assertSame('40', $r['TotalNoSujeto']);
    }

    public function test_the_mixed_invoice_still_passes_the_xsd(): void
    {
        $dto = $this->dtoConLineas([
            $this->linea(1, '2321100000000', 100.0, 13.0),
            $this->linea(2, '8415000000000', 20.0, 13.0),
        ]);

        $builder = new FacturaXmlBuilder;
        $doc = $builder->build($dto, str_repeat('5', 50));
        $signed = (new XadesEpesSigner)->sign($doc, $this->cert());

        $wire = new DOMDocument;
        $wire->loadXML((string) $signed->saveXML());

        $validator = new XsdValidator;
        self::assertTrue(
            $validator->validate($wire, $builder->xsdPath()),
            'mixed goods/services FE must pass XSD: '.implode('; ', $validator->errors()),
        );
    }
}
