<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Tests\Unit\Xml;

use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stea\FacturaElectronica\Credentials\SigningCertificate;
use Stea\FacturaElectronica\Dtos\DescuentoDto;
use Stea\FacturaElectronica\Dtos\EmisorDto;
use Stea\FacturaElectronica\Dtos\ExoneracionDto;
use Stea\FacturaElectronica\Dtos\FacturaDto;
use Stea\FacturaElectronica\Dtos\IdentificacionDto;
use Stea\FacturaElectronica\Dtos\ImpuestoDto;
use Stea\FacturaElectronica\Dtos\InformacionReferenciaDto;
use Stea\FacturaElectronica\Dtos\LineaDetalleDto;
use Stea\FacturaElectronica\Dtos\MedioPagoDto;
use Stea\FacturaElectronica\Dtos\NotaCreditoDto;
use Stea\FacturaElectronica\Dtos\NotaDebitoDto;
use Stea\FacturaElectronica\Dtos\ReceptorDto;
use Stea\FacturaElectronica\Dtos\ResumenDto;
use Stea\FacturaElectronica\Dtos\TelefonoDto;
use Stea\FacturaElectronica\Dtos\TiqueteDto;
use Stea\FacturaElectronica\Dtos\UbicacionDto;
use Stea\FacturaElectronica\Enums\Situacion;
use Stea\FacturaElectronica\Exceptions\XmlBuildException;
use Stea\FacturaElectronica\Signing\XadesEpesSigner;
use Stea\FacturaElectronica\Xml\DocumentBuilder;
use Stea\FacturaElectronica\Xml\FacturaXmlBuilder;
use Stea\FacturaElectronica\Xml\NotaCreditoXmlBuilder;
use Stea\FacturaElectronica\Xml\NotaDebitoXmlBuilder;
use Stea\FacturaElectronica\Xml\TiqueteXmlBuilder;
use Stea\FacturaElectronica\Xml\XsdValidator;

/**
 * The four sales documents share one implementation; these cases (discount, exoneration, credit, several payment methods,
 * references) must validate against each one's XSD and carry the totals Hacienda recalculates.
 */
final class ComprobanteVentaTest extends TestCase
{
    private const CLAVE = '50601012600310100000000100001010000000001100000001';

    private DateTimeImmutable $fecha;

    protected function setUp(): void
    {
        $this->fecha = new DateTimeImmutable('2026-01-01T10:00:00-06:00');
    }

    /** @return array<string, array{string}> */
    public static function tipos(): array
    {
        return ['FE' => ['FE'], 'TE' => ['TE'], 'NC' => ['NC'], 'ND' => ['ND']];
    }

    /** @param list<LineaDetalleDto> $lineas */
    private function dto(string $tipo, array $lineas, array $extra = []): FacturaDto|TiqueteDto|NotaCreditoDto|NotaDebitoDto
    {
        $emisor = new EmisorDto(
            nombre: 'EMISOR DE PRUEBA S.A.',
            identificacion: new IdentificacionDto('02', '3101000000'),
            ubicacion: new UbicacionDto('1', '01', '01', null, 'Frente al parque, 100 m norte'),
            correoElectronico: 'emisor@example.com',
            telefono: new TelefonoDto('506', '22000000'),
        );
        $receptor = new ReceptorDto('RECEPTOR DE PRUEBA S.A.', new IdentificacionDto('02', '3101999999'), correoElectronico: 'r@example.com');
        $ref = new InformacionReferenciaDto('01', '50601012600310100000000100001010000000009100000001', $this->fecha, '02', 'Corrige monto');
        $condicion = (string) ($extra['condicionVenta'] ?? '01');
        $plazo = $extra['plazoCredito'] ?? null;
        $medio = array_key_exists('medioPago', $extra) ? $extra['medioPago'] : '01';
        $medios = $extra['mediosPago'] ?? [];
        $resumen = $extra['resumen'] ?? null;
        $referencias = $extra['referencias'] ?? [];

        if ($tipo === 'TE') {
            return new TiqueteDto('00100001010000000001', $this->fecha, '3101000000', '452001', $emisor, null, $lineas, $condicion, $medio, 'CRC', 1.0, Situacion::Normal, $medios, $plazo, null, $resumen, $referencias);
        }

        if ($tipo === 'FE') {
            return new FacturaDto('00100001010000000001', $this->fecha, '3101000000', '452001', $emisor, $receptor, $lineas, null, $condicion, $medio, 'CRC', 1.0, Situacion::Normal, $medios, $plazo, null, $resumen, $referencias);
        }

        if ($tipo === 'NC') {
            return new NotaCreditoDto('00100001010000000001', $this->fecha, '3101000000', '452001', $emisor, $receptor, $lineas, $ref, null, $condicion, $medio, 'CRC', 1.0, Situacion::Normal, $medios, $plazo, null, $resumen, $referencias);
        }

        return new NotaDebitoDto('00100001010000000001', $this->fecha, '3101000000', '452001', $emisor, $receptor, $lineas, $ref, null, $condicion, $medio, 'CRC', 1.0, Situacion::Normal, $medios, $plazo, null, $resumen, $referencias);
    }

    private function builder(string $tipo): DocumentBuilder
    {
        return match ($tipo) {
            'FE' => new FacturaXmlBuilder,
            'TE' => new TiqueteXmlBuilder,
            'NC' => new NotaCreditoXmlBuilder,
            default => new NotaDebitoXmlBuilder,
        };
    }

    /** @param list<ImpuestoDto> $impuestos */
    private function linea(array $impuestos, string $total = '100', string $sub = '100', string $linea = '113', array $descuentos = [], string $cabys = '4311502000000'): LineaDetalleDto
    {
        return new LineaDetalleDto(1, $cabys, '1', 'Unid', 'Filtro de aceite', $total, $total, $sub, $linea, $impuestos, descuentos: $descuentos);
    }

    private function iva(string $monto = '13', ?ExoneracionDto $exo = null): ImpuestoDto
    {
        return new ImpuestoDto('01', '08', '13', $monto, $exo);
    }

    private function exoneracion(string $tarifa, string $monto): ExoneracionDto
    {
        return new ExoneracionDto('04', 'AL-00012345-23', '01', $this->fecha, $tarifa, $monto);
    }

    /** @return array{0: DOMDocument, 1: DOMXPath} el XML firmado, releído, y un XPath para consultarlo */
    private function construir(string $tipo, FacturaDto|TiqueteDto|NotaCreditoDto|NotaDebitoDto $dto): array
    {
        $builder = $this->builder($tipo);
        $firmado = (new XadesEpesSigner)->sign($builder->build($dto, self::CLAVE), SigningCertificate::fromPath(__DIR__.'/../../fixtures/cert/test.p12', '1234'));

        $leido = new DOMDocument;
        $leido->loadXML((string) $firmado->saveXML());

        $validador = new XsdValidator;
        $this->assertTrue($validador->validate($leido, $builder->xsdPath()), $tipo.' debe validar contra el XSD: '.implode('; ', $validador->errors()));

        return [$leido, new DOMXPath($leido)];
    }

    private function valor(DOMXPath $x, string $nombre): string
    {
        $n = $x->query('//*[local-name()="'.$nombre.'"]')->item(0);
        $this->assertNotNull($n, "Falta el nodo {$nombre}");

        return $n->textContent;
    }

    #[DataProvider('tipos')]
    public function test_un_descuento_baja_el_subtotal_y_el_iva(string $tipo): void
    {
        [, $x] = $this->construir($tipo, $this->dto($tipo, [
            $this->linea([$this->iva('11.7')], '100', '90', '101.7', [new DescuentoDto('10')]),
        ]));

        $this->assertSame('10', $this->valor($x, 'MontoDescuento'));
        $this->assertSame('07', $this->valor($x, 'CodigoDescuento'));
        $this->assertSame('90', $this->valor($x, 'SubTotal'));
        $this->assertSame('10', $this->valor($x, 'TotalDescuentos'));
        $this->assertSame('100', $this->valor($x, 'TotalVenta'));
        $this->assertSame('90', $this->valor($x, 'TotalVentaNeta'));
        $this->assertSame('11.7', $this->valor($x, 'TotalImpuesto'));
        $this->assertSame('101.7', $this->valor($x, 'TotalComprobante'));
    }

    #[DataProvider('tipos')]
    public function test_una_exoneracion_total_deja_el_impuesto_neto_en_cero(string $tipo): void
    {
        [, $x] = $this->construir($tipo, $this->dto($tipo, [
            $this->linea([$this->iva('13', $this->exoneracion('13', '13'))], '100', '100', '100'),
        ]));

        $this->assertSame('AL-00012345-23', $this->valor($x, 'NumeroDocumento'));
        $this->assertSame('13.00', $this->valor($x, 'TarifaExonerada'));
        $this->assertSame('13', $this->valor($x, 'MontoExoneracion'));
        $this->assertSame('0', $this->valor($x, 'ImpuestoNeto'));
        $this->assertSame('100', $this->valor($x, 'TotalMercExonerada'));
        $this->assertSame('0', $this->valor($x, 'TotalMercanciasGravadas'));
        $this->assertSame('100', $this->valor($x, 'TotalExonerado'));
        $this->assertSame('0', $this->valor($x, 'TotalImpuesto'));
        $this->assertSame('100', $this->valor($x, 'TotalComprobante'));
    }

    public function test_una_exoneracion_parcial_reparte_gravado_y_exonerado(): void
    {
        [, $x] = $this->construir('FE', $this->dto('FE', [
            $this->linea([$this->iva('13', $this->exoneracion('4', '4'))], '100', '100', '109'),
        ]));

        $this->assertSame('9', $this->valor($x, 'ImpuestoNeto'));
        $this->assertSame('30.76923', $this->valor($x, 'TotalMercExonerada'));
        $this->assertSame('69.23077', $this->valor($x, 'TotalMercanciasGravadas'));
        $this->assertSame('9', $this->valor($x, 'TotalImpuesto'));
        $this->assertSame('109', $this->valor($x, 'TotalComprobante'));
    }

    #[DataProvider('tipos')]
    public function test_los_servicios_se_totalizan_aparte_de_las_mercancias(string $tipo): void
    {
        [, $x] = $this->construir($tipo, $this->dto($tipo, [
            $this->linea([$this->iva()], '100', '100', '113', [], '8713100000000'),
        ]));

        $this->assertSame('100', $this->valor($x, 'TotalServGravados'));
        $this->assertSame('0', $this->valor($x, 'TotalMercanciasGravadas'));
    }

    public function test_una_venta_a_credito_no_lleva_medio_de_pago_y_exige_plazo(): void
    {
        [, $x] = $this->construir('FE', $this->dto('FE', [$this->linea([$this->iva()])], ['condicionVenta' => '02', 'plazoCredito' => 30, 'medioPago' => null]));

        $this->assertSame('30', $this->valor($x, 'PlazoCredito'));
        $this->assertSame(0, $x->query('//*[local-name()="MedioPago"]')->length);

        $this->expectException(XmlBuildException::class);
        $this->builder('FE')->build($this->dto('FE', [$this->linea([$this->iva()])], ['condicionVenta' => '02', 'medioPago' => null]), self::CLAVE);
    }

    public function test_varios_medios_de_pago_deben_sumar_el_total(): void
    {
        [, $x] = $this->construir('FE', $this->dto('FE', [$this->linea([$this->iva()], '100', '100', '113')], [
            'medioPago' => null,
            'mediosPago' => [new MedioPagoDto('01', '60'), new MedioPagoDto('06', '53')],
        ]));

        $this->assertSame(2, $x->query('//*[local-name()="MedioPago"]')->length);

        $this->expectException(XmlBuildException::class);
        $this->builder('FE')->build($this->dto('FE', [$this->linea([$this->iva()])], [
            'medioPago' => null,
            'mediosPago' => [new MedioPagoDto('01', '60'), new MedioPagoDto('06', '50')],
        ]), self::CLAVE);
    }

    public function test_sin_medio_de_pago_y_sin_credito_no_se_emite(): void
    {
        $this->expectException(XmlBuildException::class);
        $this->builder('TE')->build($this->dto('TE', [$this->linea([$this->iva()])], ['medioPago' => null]), self::CLAVE);
    }

    public function test_una_factura_puede_referenciar_el_comprobante_rechazado_que_sustituye(): void
    {
        $ref = new InformacionReferenciaDto('10', '50601012600310100000000100001010000000009100000001', $this->fecha, '16', 'Sustituye comprobante rechazado');
        [, $x] = $this->construir('FE', $this->dto('FE', [$this->linea([$this->iva()])], ['referencias' => [$ref]]));

        $this->assertSame('10', $this->valor($x, 'TipoDocIR'));
        $this->assertSame('16', $x->query('//*[local-name()="InformacionReferencia"]/*[local-name()="Codigo"]')->item(0)?->textContent);
    }

    public function test_el_resumen_explicito_se_emite_tal_cual(): void
    {
        $resumen = new ResumenDto(
            totalMercanciasGravadas: '100', totalGravado: '100', totalVenta: '100', totalVentaNeta: '100',
            desglose: [['codigo' => '01', 'codigoTarifa' => '08', 'monto' => '13']], totalImpuesto: '13', totalComprobante: '113',
        );
        [, $x] = $this->construir('FE', $this->dto('FE', [$this->linea([$this->iva()])], ['resumen' => $resumen]));

        $this->assertSame('113', $this->valor($x, 'TotalComprobante'));
        $this->assertSame('13', $this->valor($x, 'TotalMontoImpuesto'));
    }
}
