<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Xml\Concerns;

use DOMDocument;
use DOMElement;
use ReflectionClass;
use Stea\FacturaElectronica\Dtos\DescuentoDto;
use Stea\FacturaElectronica\Dtos\EmisorDto;
use Stea\FacturaElectronica\Dtos\ExoneracionDto;
use Stea\FacturaElectronica\Dtos\FacturaDto;
use Stea\FacturaElectronica\Dtos\ImpuestoDto;
use Stea\FacturaElectronica\Dtos\InformacionReferenciaDto;
use Stea\FacturaElectronica\Dtos\LineaDetalleDto;
use Stea\FacturaElectronica\Dtos\MedioPagoDto;
use Stea\FacturaElectronica\Dtos\NotaCreditoDto;
use Stea\FacturaElectronica\Dtos\NotaDebitoDto;
use Stea\FacturaElectronica\Dtos\ReceptorDto;
use Stea\FacturaElectronica\Dtos\ResumenDto;
use Stea\FacturaElectronica\Dtos\TiqueteDto;
use Stea\FacturaElectronica\Dtos\UbicacionDto;
use Stea\FacturaElectronica\Exceptions\XmlBuildException;
use Stea\FacturaElectronica\Support\Decimal;
use Stea\FacturaElectronica\Support\ResumenCalculator;

/**
 * Everything the four sales documents (FE, TE, NC, ND) have in common. Their XSDs differ only in the root element and in a few
 * conditional nodes, so one implementation builds all of them; each builder declares the root name, the namespace and which
 * optional nodes its schema has.
 */
trait BuildsComprobanteVenta
{
    /** Credit conditions (nota 5): no MedioPago, and 02/10 need PlazoCredito. */
    private const CONDICIONES_CREDITO = ['02', '08', '10'];

    abstract protected function raiz(): string;

    abstract protected function namespace(): string;

    /** @return class-string */
    abstract protected function dtoClass(): string;

    /** Does this schema have CodigoActividadReceptor? (TE does not.) */
    protected function llevaActividadReceptor(): bool
    {
        return true;
    }

    /** Does this schema have LineaDetalle/TipoTransaccion? (TE does not.) */
    protected function llevaTipoTransaccion(): bool
    {
        return true;
    }

    public function build(object $dto, string $clave): DOMDocument
    {
        $esperado = $this->dtoClass();

        if (! $dto instanceof $esperado || ! ($dto instanceof FacturaDto || $dto instanceof TiqueteDto || $dto instanceof NotaCreditoDto || $dto instanceof NotaDebitoDto)) {
            throw new XmlBuildException((new ReflectionClass($this))->getShortName().' requires a '.(new ReflectionClass($esperado))->getShortName().'.');
        }

        $this->validarCondicionYPago($dto);

        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->formatOutput = false;

        $ns = $this->namespace();
        $root = $doc->createElementNS($ns, $this->raiz());
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ds', 'http://www.w3.org/2000/09/xmldsig#');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $root->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'xsi:schemaLocation', $ns.' '.$ns.'.xsd');
        $doc->appendChild($root);

        $this->el($doc, $root, 'Clave', $clave);
        $this->el($doc, $root, 'ProveedorSistemas', $dto->proveedorSistemas);
        $this->el($doc, $root, 'CodigoActividadEmisor', $dto->codigoActividadEmisor);

        if ($this->llevaActividadReceptor() && ! $dto instanceof TiqueteDto && $dto->codigoActividadReceptor !== null) {
            $this->el($doc, $root, 'CodigoActividadReceptor', $dto->codigoActividadReceptor);
        }

        $this->el($doc, $root, 'NumeroConsecutivo', $dto->consecutivo);
        $this->el($doc, $root, 'FechaEmision', $dto->fechaEmision->format('Y-m-d\TH:i:sP'));

        $this->emisor($doc, $root, $dto->emisor);

        if ($dto->receptor !== null) {
            $this->receptor($doc, $root, $dto->receptor);
        }

        $this->el($doc, $root, 'CondicionVenta', $dto->condicionVenta);

        if ($dto->condicionVentaOtros !== null) {
            $this->el($doc, $root, 'CondicionVentaOtros', $dto->condicionVentaOtros);
        }

        if ($dto->plazoCredito !== null) {
            $this->el($doc, $root, 'PlazoCredito', (string) $dto->plazoCredito);
        }

        $detalle = $this->el($doc, $root, 'DetalleServicio');
        foreach ($dto->lineas as $linea) {
            $this->linea($doc, $detalle, $linea);
        }

        $resumen = $dto->resumen ?? ResumenCalculator::desdeLineas($dto->lineas);
        $this->resumen($doc, $root, $dto, $resumen);

        foreach ($this->referencias($dto) as $ref) {
            $this->referencia($doc, $root, $ref);
        }

        return $doc;
    }

    /** @return list<InformacionReferenciaDto> */
    private function referencias(FacturaDto|TiqueteDto|NotaCreditoDto|NotaDebitoDto $dto): array
    {
        $refs = [];

        if ($dto instanceof NotaCreditoDto || $dto instanceof NotaDebitoDto) {
            $refs[] = $dto->informacionReferencia;
        } elseif ($dto instanceof FacturaDto && $dto->informacionReferencia !== null) {
            $refs[] = $dto->informacionReferencia;
        }

        foreach ($dto->referencias as $r) {
            $refs[] = $r;
        }

        if (count($refs) > 10) {
            throw new XmlBuildException('A comprobante admits at most 10 InformacionReferencia nodes.');
        }

        return $refs;
    }

    private function validarCondicionYPago(FacturaDto|TiqueteDto|NotaCreditoDto|NotaDebitoDto $dto): void
    {
        $credito = in_array($dto->condicionVenta, self::CONDICIONES_CREDITO, true);

        if (in_array($dto->condicionVenta, ['02', '10'], true) && ($dto->plazoCredito === null || $dto->plazoCredito <= 0)) {
            throw new XmlBuildException('PlazoCredito (days, > 0) is required for credit sales.');
        }

        if ($dto->condicionVenta === '99' && ($dto->condicionVentaOtros === null || mb_strlen($dto->condicionVentaOtros) < 5)) {
            throw new XmlBuildException('CondicionVentaOtros (min 5 characters) is required with condition 99.');
        }

        if (! $credito && $dto->mediosPago === [] && $dto->medioPago === null) {
            throw new XmlBuildException('A medio de pago is required unless the sale is on credit.');
        }

        if (count($dto->mediosPago) > 4) {
            throw new XmlBuildException('A comprobante admits at most 4 medios de pago.');
        }

        if (count($dto->mediosPago) > 1) {
            foreach ($dto->mediosPago as $m) {
                if ($m->total === null || Decimal::cmp($m->total, 0) <= 0) {
                    throw new XmlBuildException('With several medios de pago each one needs a total > 0.');
                }
            }
        }
    }

    private function emisor(DOMDocument $doc, DOMElement $root, EmisorDto $emisor): void
    {
        $node = $this->el($doc, $root, 'Emisor');
        $this->el($doc, $node, 'Nombre', $emisor->nombre);

        $ident = $this->el($doc, $node, 'Identificacion');
        $this->el($doc, $ident, 'Tipo', $emisor->identificacion->tipo);
        $this->el($doc, $ident, 'Numero', $emisor->identificacion->numero);

        if ($emisor->nombreComercial !== null) {
            $this->el($doc, $node, 'NombreComercial', $emisor->nombreComercial);
        }

        $this->ubicacion($doc, $node, $emisor->ubicacion);

        if ($emisor->telefono !== null) {
            $tel = $this->el($doc, $node, 'Telefono');
            $this->el($doc, $tel, 'CodigoPais', $emisor->telefono->codigoPais);
            $this->el($doc, $tel, 'NumTelefono', $emisor->telefono->numTelefono);
        }

        $correos = array_values(array_filter([$emisor->correoElectronico, ...$emisor->correos], static fn ($c): bool => is_string($c) && $c !== ''));

        foreach (array_slice($correos, 0, 4) as $correo) {
            $this->el($doc, $node, 'CorreoElectronico', $correo);
        }
    }

    private function receptor(DOMDocument $doc, DOMElement $root, ReceptorDto $receptor): void
    {
        $node = $this->el($doc, $root, 'Receptor');
        $this->el($doc, $node, 'Nombre', $receptor->nombre);

        if ($receptor->identificacion !== null) {
            $ident = $this->el($doc, $node, 'Identificacion');
            $this->el($doc, $ident, 'Tipo', $receptor->identificacion->tipo);
            $this->el($doc, $ident, 'Numero', $receptor->identificacion->numero);
        }

        $this->ubicacion($doc, $node, $receptor->ubicacion);

        if ($receptor->otrasSenasExtranjero !== null) {
            $this->el($doc, $node, 'OtrasSenasExtranjero', $receptor->otrasSenasExtranjero);
        }

        if ($receptor->telefono !== null) {
            $tel = $this->el($doc, $node, 'Telefono');
            $this->el($doc, $tel, 'CodigoPais', $receptor->telefono->codigoPais);
            $this->el($doc, $tel, 'NumTelefono', $receptor->telefono->numTelefono);
        }

        if ($receptor->correoElectronico !== null) {
            $this->el($doc, $node, 'CorreoElectronico', $receptor->correoElectronico);
        }
    }

    private function ubicacion(DOMDocument $doc, DOMElement $parent, ?UbicacionDto $u): void
    {
        if ($u === null) {
            return;
        }

        $ubic = $this->el($doc, $parent, 'Ubicacion');
        $this->el($doc, $ubic, 'Provincia', $u->provincia);
        $this->el($doc, $ubic, 'Canton', $u->canton);
        $this->el($doc, $ubic, 'Distrito', $u->distrito);

        if ($u->barrio !== null) {
            $this->el($doc, $ubic, 'Barrio', $u->barrio);
        }

        if ($u->otrasSenas !== null) {
            $this->el($doc, $ubic, 'OtrasSenas', $u->otrasSenas);
        }
    }

    private function linea(DOMDocument $doc, DOMElement $detalle, LineaDetalleDto $linea): void
    {
        $node = $this->el($doc, $detalle, 'LineaDetalle');
        $this->el($doc, $node, 'NumeroLinea', (string) $linea->numeroLinea);
        $this->el($doc, $node, 'CodigoCABYS', $linea->codigoCabys);

        if ($linea->codigoComercial !== null) {
            $cod = $this->el($doc, $node, 'CodigoComercial');
            $this->el($doc, $cod, 'Tipo', $linea->codigoComercialTipo ?? '01');
            $this->el($doc, $cod, 'Codigo', $linea->codigoComercial);
        }

        $this->el($doc, $node, 'Cantidad', Decimal::fixed($linea->cantidad, 3));
        $this->el($doc, $node, 'UnidadMedida', $linea->unidadMedida);

        if ($this->llevaTipoTransaccion() && $linea->tipoTransaccion !== '01') {
            $this->el($doc, $node, 'TipoTransaccion', $linea->tipoTransaccion);
        }

        $this->el($doc, $node, 'Detalle', $linea->detalle);
        $this->el($doc, $node, 'PrecioUnitario', Decimal::money($linea->precioUnitario));
        $this->el($doc, $node, 'MontoTotal', Decimal::money($linea->montoTotal));

        if (count($linea->descuentos) > 5) {
            throw new XmlBuildException('A line admits at most 5 descuentos.');
        }

        foreach ($linea->descuentos as $d) {
            $this->descuento($doc, $node, $d);
        }

        $this->el($doc, $node, 'SubTotal', Decimal::money($linea->subTotal));
        $this->el($doc, $node, 'BaseImponible', Decimal::money($linea->baseImponible ?? $linea->subTotal));

        $impuestoTotal = '0';
        $exonerado = '0';

        foreach ($linea->impuestos as $impuesto) {
            $this->impuesto($doc, $node, $impuesto);
            $impuestoTotal = Decimal::add($impuestoTotal, $impuesto->monto);
            $exonerado = Decimal::add($exonerado, $impuesto->exoneracion !== null ? $impuesto->exoneracion->montoExoneracion : 0);
        }

        $this->el($doc, $node, 'ImpuestoAsumidoEmisorFabrica', Decimal::money($linea->impuestoAsumidoEmisorFabrica));
        $this->el($doc, $node, 'ImpuestoNeto', Decimal::money($linea->impuestoNeto ?? Decimal::sub($impuestoTotal, $exonerado)));
        $this->el($doc, $node, 'MontoTotalLinea', Decimal::money($linea->montoTotalLinea));
    }

    private function descuento(DOMDocument $doc, DOMElement $linea, DescuentoDto $d): void
    {
        if ($d->codigo === '99' && ($d->codigoOtro === null || $d->naturaleza === null)) {
            throw new XmlBuildException('Descuento 99 (otros) needs codigoOtro and naturaleza.');
        }

        $node = $this->el($doc, $linea, 'Descuento');
        $this->el($doc, $node, 'MontoDescuento', Decimal::money($d->monto));
        $this->el($doc, $node, 'CodigoDescuento', $d->codigo);

        if ($d->codigoOtro !== null) {
            $this->el($doc, $node, 'CodigoDescuentoOTRO', $d->codigoOtro);
        }

        if ($d->naturaleza !== null) {
            $this->el($doc, $node, 'NaturalezaDescuento', $d->naturaleza);
        }
    }

    private function impuesto(DOMDocument $doc, DOMElement $linea, ImpuestoDto $impuesto): void
    {
        $imp = $this->el($doc, $linea, 'Impuesto');
        $this->el($doc, $imp, 'Codigo', $impuesto->codigo);
        $this->el($doc, $imp, 'CodigoTarifaIVA', $impuesto->codigoTarifa);
        $this->el($doc, $imp, 'Tarifa', Decimal::fixed($impuesto->tarifa, 2));
        $this->el($doc, $imp, 'Monto', Decimal::money($impuesto->monto));

        if ($impuesto->exoneracion !== null) {
            $this->exoneracion($doc, $imp, $impuesto->exoneracion);
        }
    }

    private function exoneracion(DOMDocument $doc, DOMElement $impuesto, ExoneracionDto $e): void
    {
        if ($e->tipoDocumento === '99' && $e->tipoDocumentoOtro === null) {
            throw new XmlBuildException('Exoneracion type 99 needs tipoDocumentoOtro.');
        }

        if ($e->nombreInstitucion === '99' && $e->nombreInstitucionOtros === null) {
            throw new XmlBuildException('Exoneracion institution 99 needs nombreInstitucionOtros.');
        }

        $node = $this->el($doc, $impuesto, 'Exoneracion');
        $this->el($doc, $node, 'TipoDocumentoEX1', $e->tipoDocumento);

        if ($e->tipoDocumentoOtro !== null) {
            $this->el($doc, $node, 'TipoDocumentoOTRO', $e->tipoDocumentoOtro);
        }

        $this->el($doc, $node, 'NumeroDocumento', $e->numeroDocumento);

        if ($e->articulo !== null) {
            $this->el($doc, $node, 'Articulo', (string) $e->articulo);
        }

        if ($e->inciso !== null) {
            $this->el($doc, $node, 'Inciso', (string) $e->inciso);
        }

        $this->el($doc, $node, 'NombreInstitucion', $e->nombreInstitucion);

        if ($e->nombreInstitucionOtros !== null) {
            $this->el($doc, $node, 'NombreInstitucionOtros', $e->nombreInstitucionOtros);
        }

        $this->el($doc, $node, 'FechaEmisionEX', $e->fechaEmision->format('Y-m-d\TH:i:sP'));
        $this->el($doc, $node, 'TarifaExonerada', Decimal::fixed($e->tarifaExonerada, 2));
        $this->el($doc, $node, 'MontoExoneracion', Decimal::money($e->montoExoneracion));
    }

    private function resumen(DOMDocument $doc, DOMElement $root, FacturaDto|TiqueteDto|NotaCreditoDto|NotaDebitoDto $dto, ResumenDto $r): void
    {
        $node = $this->el($doc, $root, 'ResumenFactura');

        $moneda = $this->el($doc, $node, 'CodigoTipoMoneda');
        $this->el($doc, $moneda, 'CodigoMoneda', $dto->moneda);
        $this->el($doc, $moneda, 'TipoCambio', Decimal::money($dto->tipoCambio));

        foreach ([
            'TotalServGravados' => $r->totalServGravados,
            'TotalServExentos' => $r->totalServExentos,
            'TotalServExonerado' => $r->totalServExonerado,
            'TotalServNoSujeto' => $r->totalServNoSujeto,
            'TotalMercanciasGravadas' => $r->totalMercanciasGravadas,
            'TotalMercanciasExentas' => $r->totalMercanciasExentas,
            'TotalMercExonerada' => $r->totalMercExonerada,
            'TotalMercNoSujeta' => $r->totalMercNoSujeta,
            'TotalGravado' => $r->totalGravado,
            'TotalExento' => $r->totalExento,
            'TotalExonerado' => $r->totalExonerado,
            'TotalNoSujeto' => $r->totalNoSujeto,
            'TotalVenta' => $r->totalVenta,
            'TotalDescuentos' => $r->totalDescuentos,
            'TotalVentaNeta' => $r->totalVentaNeta,
        ] as $nombre => $valor) {
            $this->el($doc, $node, $nombre, Decimal::money($valor));
        }

        foreach ($r->desglose as $d) {
            $n = $this->el($doc, $node, 'TotalDesgloseImpuesto');
            $this->el($doc, $n, 'Codigo', $d['codigo']);

            if ($d['codigoTarifa'] !== null) {
                $this->el($doc, $n, 'CodigoTarifaIVA', $d['codigoTarifa']);
            }

            $this->el($doc, $n, 'TotalMontoImpuesto', Decimal::money($d['monto']));
        }

        $this->el($doc, $node, 'TotalImpuesto', Decimal::money($r->totalImpuesto));
        $this->el($doc, $node, 'TotalImpAsumEmisorFabrica', Decimal::money($r->totalImpAsumEmisorFabrica));
        $this->el($doc, $node, 'TotalIVADevuelto', Decimal::money(0));

        $this->mediosPago($doc, $node, $dto, $r);

        $this->el($doc, $node, 'TotalComprobante', Decimal::money($r->totalComprobante));
    }

    private function mediosPago(DOMDocument $doc, DOMElement $resumen, FacturaDto|TiqueteDto|NotaCreditoDto|NotaDebitoDto $dto, ResumenDto $r): void
    {
        if (in_array($dto->condicionVenta, self::CONDICIONES_CREDITO, true)) {
            return;
        }

        /** @var list<MedioPagoDto> $medios */
        $medios = $dto->mediosPago !== [] ? $dto->mediosPago : [new MedioPagoDto((string) $dto->medioPago)];
        $suma = '0';

        foreach ($medios as $m) {
            $node = $this->el($doc, $resumen, 'MedioPago');
            $this->el($doc, $node, 'TipoMedioPago', $m->tipo);

            if ($m->otros !== null) {
                $this->el($doc, $node, 'MedioPagoOtros', $m->otros);
            }

            $total = $m->total ?? $r->totalComprobante;
            $suma = Decimal::add($suma, $total);
            $this->el($doc, $node, 'TotalMedioPago', Decimal::money($total));
        }

        if (count($medios) > 1 && Decimal::cmp(Decimal::round($suma), Decimal::round($r->totalComprobante)) !== 0) {
            throw new XmlBuildException("The medios de pago add up to {$suma} but TotalComprobante is {$r->totalComprobante}.");
        }
    }

    private function referencia(DOMDocument $doc, DOMElement $root, InformacionReferenciaDto $ref): void
    {
        $node = $this->el($doc, $root, 'InformacionReferencia');
        $this->el($doc, $node, 'TipoDocIR', $ref->tipoDocIR);
        $this->el($doc, $node, 'Numero', $ref->numero);
        $this->el($doc, $node, 'FechaEmisionIR', $ref->fechaEmisionIR->format('Y-m-d\TH:i:sP'));
        $this->el($doc, $node, 'Codigo', $ref->codigo);
        $this->el($doc, $node, 'Razon', $ref->razon);
    }

    private function el(DOMDocument $doc, DOMElement $parent, string $name, ?string $text = null): DOMElement
    {
        $node = $doc->createElement($name);

        if ($text !== null) {
            $node->appendChild($doc->createTextNode($text));
        }

        $parent->appendChild($node);

        return $node;
    }
}
