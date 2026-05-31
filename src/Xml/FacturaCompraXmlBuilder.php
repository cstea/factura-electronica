<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Xml;

use DOMDocument;
use DOMElement;
use Stea\FacturaElectronica\Dtos\EmisorDto;
use Stea\FacturaElectronica\Dtos\FacturaCompraDto;
use Stea\FacturaElectronica\Dtos\InformacionReferenciaDto;
use Stea\FacturaElectronica\Dtos\LineaDetalleDto;
use Stea\FacturaElectronica\Dtos\ReceptorDto;
use Stea\FacturaElectronica\Exceptions\XmlBuildException;
use Stea\FacturaElectronica\Xml\Concerns\BuildsDomElements;

/**
 * Builds the (unsigned) body of a Factura Electrónica de Compra (FEC, tipo 08).
 *
 * Emisor/receptor are inverted vs. a sale: <Emisor> is the foreign supplier and
 * <Receptor> is STEA (the clave owner) with a required <CodigoActividadReceptor>.
 */
final class FacturaCompraXmlBuilder implements DocumentBuilder
{
    use BuildsDomElements;

    private const NS = 'https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/facturaElectronicaCompra';

    public function build(object $dto, string $clave): DOMDocument
    {
        if (! $dto instanceof FacturaCompraDto) {
            throw new XmlBuildException('FacturaCompraXmlBuilder requires a FacturaCompraDto.');
        }

        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->formatOutput = false;

        $root = $doc->createElementNS(self::NS, 'FacturaElectronicaCompra');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsd', 'http://www.w3.org/2001/XMLSchema');
        $root->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'xsi:schemaLocation', self::NS.' '.self::NS.'.xsd');
        $doc->appendChild($root);

        $this->el($doc, $root, 'Clave', $clave);
        $this->el($doc, $root, 'ProveedorSistemas', $dto->proveedorSistemas);
        $this->el($doc, $root, 'CodigoActividadEmisor', $dto->codigoActividadEmisor);
        $this->el($doc, $root, 'CodigoActividadReceptor', $dto->codigoActividadReceptor);
        $this->el($doc, $root, 'NumeroConsecutivo', $dto->consecutivo);
        $this->el($doc, $root, 'FechaEmision', $dto->fechaEmision->format('Y-m-d\TH:i:s.v'));

        $this->buildEmisor($doc, $root, $dto->emisor);
        $this->buildReceptor($doc, $root, $dto->receptor);
        $this->el($doc, $root, 'CondicionVenta', $dto->condicionVenta);

        $detalle = $this->el($doc, $root, 'DetalleServicio');
        foreach ($dto->lineas as $linea) {
            $this->buildLineaDetalle($doc, $detalle, $linea);
        }

        $this->buildResumenFactura($doc, $root, $dto);

        // The FEC schema (v4.4) requires InformacionReferencia — fail fast rather than
        // silently emitting an XML that Hacienda will reject with a schema error.
        if ($dto->informacionReferencia === null) {
            throw new XmlBuildException('FEC requires InformacionReferencia (TipoDocIR/Numero/Codigo/Razon).');
        }

        $this->buildInformacionReferencia($doc, $root, $dto->informacionReferencia);

        return $doc;
    }

    private function buildEmisor(DOMDocument $doc, DOMElement $root, EmisorDto $emisor): void
    {
        $node = $this->el($doc, $root, 'Emisor');
        $this->el($doc, $node, 'Nombre', $emisor->nombre);

        $ident = $this->el($doc, $node, 'Identificacion');
        $this->el($doc, $ident, 'Tipo', $emisor->identificacion->tipo);
        $this->el($doc, $ident, 'Numero', $emisor->identificacion->numero);

        if ($emisor->otrasSenasExtranjero !== null) {
            $this->el($doc, $node, 'OtrasSenasExtranjero', $emisor->otrasSenasExtranjero);
        }

        if ($emisor->correoElectronico !== null) {
            $this->el($doc, $node, 'CorreoElectronico', $emisor->correoElectronico);
        }
    }

    private function buildReceptor(DOMDocument $doc, DOMElement $root, ReceptorDto $receptor): void
    {
        $node = $this->el($doc, $root, 'Receptor');
        $this->el($doc, $node, 'Nombre', $receptor->nombre);

        if ($receptor->identificacion !== null) {
            $ident = $this->el($doc, $node, 'Identificacion');
            $this->el($doc, $ident, 'Tipo', $receptor->identificacion->tipo);
            $this->el($doc, $ident, 'Numero', $receptor->identificacion->numero);
        }

        if ($receptor->ubicacion !== null) {
            $ubic = $this->el($doc, $node, 'Ubicacion');
            $this->el($doc, $ubic, 'Provincia', $receptor->ubicacion->provincia);
            $this->el($doc, $ubic, 'Canton', $receptor->ubicacion->canton);
            $this->el($doc, $ubic, 'Distrito', $receptor->ubicacion->distrito);
            if ($receptor->ubicacion->barrio !== null) {
                $this->el($doc, $ubic, 'Barrio', $receptor->ubicacion->barrio);
            }
            if ($receptor->ubicacion->otrasSenas !== null) {
                $this->el($doc, $ubic, 'OtrasSenas', $receptor->ubicacion->otrasSenas);
            }
        }

        if ($receptor->correoElectronico !== null) {
            $this->el($doc, $node, 'CorreoElectronico', $receptor->correoElectronico);
        }
    }

    private function buildLineaDetalle(DOMDocument $doc, DOMElement $detalle, LineaDetalleDto $linea): void
    {
        $node = $this->el($doc, $detalle, 'LineaDetalle');
        $this->el($doc, $node, 'NumeroLinea', (string) $linea->numeroLinea);
        $this->el($doc, $node, 'CodigoCABYS', $linea->codigoCabys);
        $this->el($doc, $node, 'Cantidad', $this->quantity($linea->cantidad));
        $this->el($doc, $node, 'UnidadMedida', $linea->unidadMedida);
        $this->el($doc, $node, 'Detalle', $linea->detalle);
        $this->el($doc, $node, 'PrecioUnitario', $this->money($linea->precioUnitario));
        $this->el($doc, $node, 'MontoTotal', $this->money($linea->montoTotal));
        $this->el($doc, $node, 'SubTotal', $this->money($linea->subTotal));

        if ($linea->baseImponible !== null) {
            $this->el($doc, $node, 'BaseImponible', $this->money($linea->baseImponible));
        }

        foreach ($linea->impuestos as $impuesto) {
            $imp = $this->el($doc, $node, 'Impuesto');
            $this->el($doc, $imp, 'Codigo', $impuesto->codigo);
            $this->el($doc, $imp, 'CodigoTarifaIVA', $impuesto->codigoTarifa);
            $this->el($doc, $imp, 'Tarifa', $this->rate($impuesto->tarifa));
            $this->el($doc, $imp, 'Monto', $this->money($impuesto->monto));
        }

        $this->el($doc, $node, 'ImpuestoNeto', $this->money($this->impuestoLinea($linea)));
        $this->el($doc, $node, 'MontoTotalLinea', $this->money($linea->montoTotalLinea));
    }

    private function buildResumenFactura(DOMDocument $doc, DOMElement $root, FacturaCompraDto $dto): void
    {
        $node = $this->el($doc, $root, 'ResumenFactura');

        $moneda = $this->el($doc, $node, 'CodigoTipoMoneda');
        $this->el($doc, $moneda, 'CodigoMoneda', $dto->moneda);
        $this->el($doc, $moneda, 'TipoCambio', $this->money($dto->tipoCambio));

        $totalServExentos = 0.0;
        $totalGravado = 0.0;
        $totalExento = 0.0;
        $totalVenta = 0.0;
        $totalImpuesto = 0.0;

        /** @var array<string, array{codigo: string, codigoTarifa: string, monto: float}> $desglose */
        $desglose = [];

        foreach ($dto->lineas as $linea) {
            $totalVenta += $linea->montoTotal;
            $impuestoLinea = $this->impuestoLinea($linea);
            $totalImpuesto += $impuestoLinea;

            foreach ($linea->impuestos as $impuesto) {
                $key = $impuesto->codigo.'|'.$impuesto->codigoTarifa;
                if (! isset($desglose[$key])) {
                    $desglose[$key] = ['codigo' => $impuesto->codigo, 'codigoTarifa' => $impuesto->codigoTarifa, 'monto' => 0.0];
                }
                $desglose[$key]['monto'] += $impuesto->monto;
            }

            if ($impuestoLinea > 0.0) {
                $totalGravado += $linea->subTotal;
            } else {
                $totalExento += $linea->subTotal;
                $totalServExentos += $linea->subTotal;
            }
        }

        $this->el($doc, $node, 'TotalServExentos', $this->money($totalServExentos));
        $this->el($doc, $node, 'TotalGravado', $this->money($totalGravado));
        $this->el($doc, $node, 'TotalExento', $this->money($totalExento));
        $this->el($doc, $node, 'TotalExonerado', $this->money(0.0));
        $this->el($doc, $node, 'TotalNoSujeto', $this->money(0.0));
        $this->el($doc, $node, 'TotalVenta', $this->money($totalVenta));
        $this->el($doc, $node, 'TotalVentaNeta', $this->money($totalVenta));

        foreach ($desglose as $row) {
            $totalDesglose = $this->el($doc, $node, 'TotalDesgloseImpuesto');
            $this->el($doc, $totalDesglose, 'Codigo', $row['codigo']);
            $this->el($doc, $totalDesglose, 'CodigoTarifaIVA', $row['codigoTarifa']);
            $this->el($doc, $totalDesglose, 'TotalMontoImpuesto', $this->money($row['monto']));
        }

        $this->el($doc, $node, 'TotalImpuesto', $this->money($totalImpuesto));

        $totalComprobante = $totalVenta + $totalImpuesto;

        $medioPago = $this->el($doc, $node, 'MedioPago');
        $this->el($doc, $medioPago, 'TipoMedioPago', $dto->medioPago);
        $this->el($doc, $medioPago, 'TotalMedioPago', $this->money($totalComprobante));

        $this->el($doc, $node, 'TotalComprobante', $this->money($totalComprobante));
    }

    private function buildInformacionReferencia(DOMDocument $doc, DOMElement $root, InformacionReferenciaDto $ref): void
    {
        $node = $this->el($doc, $root, 'InformacionReferencia');
        $this->el($doc, $node, 'TipoDocIR', $ref->tipoDocIR);
        $this->el($doc, $node, 'Numero', $ref->numero);
        $this->el($doc, $node, 'FechaEmisionIR', $ref->fechaEmisionIR->format('Y-m-d\TH:i:s.v'));
        $this->el($doc, $node, 'Codigo', $ref->codigo);
        $this->el($doc, $node, 'Razon', $ref->razon);
    }

    private function impuestoLinea(LineaDetalleDto $linea): float
    {
        $total = 0.0;
        foreach ($linea->impuestos as $impuesto) {
            $total += $impuesto->monto;
        }

        return $total;
    }

    /** Format a monetary amount with 5 decimals, matching the FEC fixture's rendering (e.g. 9.99000, 0.00000). */
    private function money(float $value): string
    {
        return number_format($value, 5, '.', '');
    }

    /** Format a quantity with 3 decimals, matching the FEC fixture's Cantidad rendering (e.g. 1.000). */
    private function quantity(float $value): string
    {
        return number_format($value, 3, '.', '');
    }

    /** Format an IVA rate as an integer string (e.g. 0), matching the FEC fixture's Tarifa rendering. */
    private function rate(float $value): string
    {
        return (string) (int) round($value);
    }

    public function xsdPath(): string
    {
        return dirname(__DIR__, 2).'/resources/xsd/v4.4/facturaElectronicaCompra.xsd';
    }
}
