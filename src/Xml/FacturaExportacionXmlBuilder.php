<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Xml;

use DOMDocument;
use DOMElement;
use Stea\FacturaElectronica\Dtos\EmisorDto;
use Stea\FacturaElectronica\Dtos\FacturaExportacionDto;
use Stea\FacturaElectronica\Dtos\LineaDetalleDto;
use Stea\FacturaElectronica\Dtos\ReceptorDto;
use Stea\FacturaElectronica\Exceptions\XmlBuildException;
use Stea\FacturaElectronica\Xml\Concerns\BuildsDomElements;

final class FacturaExportacionXmlBuilder implements DocumentBuilder
{
    use BuildsDomElements;

    private const NS = 'https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/facturaElectronicaExportacion';

    public function build(object $dto, string $clave): DOMDocument
    {
        if (! $dto instanceof FacturaExportacionDto) {
            throw new XmlBuildException('FacturaExportacionXmlBuilder requires a FacturaExportacionDto.');
        }

        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->formatOutput = false;

        $root = $doc->createElementNS(self::NS, 'FacturaElectronicaExportacion');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsd', 'http://www.w3.org/2001/XMLSchema');
        $root->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'xsi:schemaLocation', self::NS.' '.self::NS.'.xsd');
        $doc->appendChild($root);

        $this->el($doc, $root, 'Clave', $clave);
        $this->el($doc, $root, 'ProveedorSistemas', $dto->proveedorSistemas);
        $this->el($doc, $root, 'CodigoActividadEmisor', $dto->codigoActividadEmisor);
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

        return $doc;
    }

    private function buildEmisor(DOMDocument $doc, DOMElement $root, EmisorDto $emisor): void
    {
        $node = $this->el($doc, $root, 'Emisor');
        $this->el($doc, $node, 'Nombre', $emisor->nombre);

        $ident = $this->el($doc, $node, 'Identificacion');
        $this->el($doc, $ident, 'Tipo', $emisor->identificacion->tipo);
        $this->el($doc, $ident, 'Numero', $emisor->identificacion->numero);

        if ($emisor->ubicacion !== null) {
            $ubic = $this->el($doc, $node, 'Ubicacion');
            $this->el($doc, $ubic, 'Provincia', $emisor->ubicacion->provincia);
            $this->el($doc, $ubic, 'Canton', $emisor->ubicacion->canton);
            $this->el($doc, $ubic, 'Distrito', $emisor->ubicacion->distrito);
            if ($emisor->ubicacion->barrio !== null) {
                $this->el($doc, $ubic, 'Barrio', $emisor->ubicacion->barrio);
            }
            if ($emisor->ubicacion->otrasSenas !== null) {
                $this->el($doc, $ubic, 'OtrasSenas', $emisor->ubicacion->otrasSenas);
            }
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

        if ($receptor->otrasSenasExtranjero !== null) {
            $this->el($doc, $node, 'OtrasSenasExtranjero', $receptor->otrasSenasExtranjero);
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

        foreach ($linea->impuestos as $impuesto) {
            $imp = $this->el($doc, $node, 'Impuesto');
            $this->el($doc, $imp, 'Codigo', $impuesto->codigo);
            $this->el($doc, $imp, 'CodigoTarifaIVA', $impuesto->codigoTarifa);
            $this->el($doc, $imp, 'Tarifa', $this->rate($impuesto->tarifa));
            $this->el($doc, $imp, 'Monto', $this->money($impuesto->monto));
        }

        $this->el($doc, $node, 'MontoTotalLinea', $this->money($linea->montoTotalLinea));
    }

    private function buildResumenFactura(DOMDocument $doc, DOMElement $root, FacturaExportacionDto $dto): void
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
            $impuestoLinea = 0.0;
            foreach ($linea->impuestos as $impuesto) {
                $impuestoLinea += $impuesto->monto;
                $totalImpuesto += $impuesto->monto;
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

        $totalVentaNeta = $totalVenta;

        $this->el($doc, $node, 'TotalServExentos', $this->money($totalServExentos));
        $this->el($doc, $node, 'TotalGravado', $this->money($totalGravado));
        $this->el($doc, $node, 'TotalExento', $this->money($totalExento));
        $this->el($doc, $node, 'TotalVenta', $this->money($totalVenta));
        $this->el($doc, $node, 'TotalVentaNeta', $this->money($totalVentaNeta));

        foreach ($desglose as $row) {
            $totalDesglose = $this->el($doc, $node, 'TotalDesgloseImpuesto');
            $this->el($doc, $totalDesglose, 'Codigo', $row['codigo']);
            $this->el($doc, $totalDesglose, 'CodigoTarifaIVA', $row['codigoTarifa']);
            $this->el($doc, $totalDesglose, 'TotalMontoImpuesto', $this->money($row['monto']));
        }

        $this->el($doc, $node, 'TotalImpuesto', $this->money($totalImpuesto));

        $medioPago = $this->el($doc, $node, 'MedioPago');
        $this->el($doc, $medioPago, 'TipoMedioPago', $dto->medioPago);
        $this->el($doc, $medioPago, 'TotalMedioPago', $this->money($totalVenta + $totalImpuesto));

        $this->el($doc, $node, 'TotalComprobante', $this->money($totalVenta + $totalImpuesto));
    }

    /** Format a quantity with 3 decimals, matching the fixture's Cantidad rendering. */
    private function quantity(float $value): string
    {
        return number_format($value, 3, '.', '');
    }

    /** Format an IVA rate as an integer string (e.g. 0, 13), matching the fixture's Tarifa rendering. */
    private function rate(float $value): string
    {
        return (string) (int) round($value);
    }

    public function xsdPath(): string
    {
        return dirname(__DIR__, 2).'/resources/xsd/v4.4/facturaElectronicaExportacion.xsd';
    }
}
