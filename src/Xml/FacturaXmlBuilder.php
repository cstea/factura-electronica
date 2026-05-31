<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Xml;

use DOMDocument;
use DOMElement;
use Stea\FacturaElectronica\Dtos\EmisorDto;
use Stea\FacturaElectronica\Dtos\FacturaDto;
use Stea\FacturaElectronica\Dtos\LineaDetalleDto;
use Stea\FacturaElectronica\Dtos\ReceptorDto;
use Stea\FacturaElectronica\Exceptions\XmlBuildException;
use Stea\FacturaElectronica\Xml\Concerns\BuildsDomElements;

final class FacturaXmlBuilder implements DocumentBuilder
{
    use BuildsDomElements;

    private const NS = 'https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/facturaElectronica';

    /** Tarifa code 01 = service/good not subject to IVA ("no sujeto"). */
    private const TARIFA_NO_SUJETO = '01';

    public function build(object $dto, string $clave): DOMDocument
    {
        if (! $dto instanceof FacturaDto) {
            throw new XmlBuildException('FacturaXmlBuilder requires a FacturaDto.');
        }

        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->formatOutput = false;

        $root = $doc->createElementNS(self::NS, 'FacturaElectronica');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ds', 'http://www.w3.org/2000/09/xmldsig#');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $root->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'xsi:schemaLocation', self::NS.' '.self::NS.'.xsd');
        $doc->appendChild($root);

        $this->el($doc, $root, 'Clave', $clave);
        $this->el($doc, $root, 'ProveedorSistemas', $dto->proveedorSistemas);
        $this->el($doc, $root, 'CodigoActividadEmisor', $dto->codigoActividadEmisor);
        if ($dto->codigoActividadReceptor !== null) {
            $this->el($doc, $root, 'CodigoActividadReceptor', $dto->codigoActividadReceptor);
        }
        $this->el($doc, $root, 'NumeroConsecutivo', $dto->consecutivo);
        $this->el($doc, $root, 'FechaEmision', $dto->fechaEmision->format('Y-m-d\TH:i:sP'));

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

        if ($emisor->nombreComercial !== null) {
            $this->el($doc, $node, 'NombreComercial', $emisor->nombreComercial);
        }

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

        if ($emisor->telefono !== null) {
            $tel = $this->el($doc, $node, 'Telefono');
            $this->el($doc, $tel, 'CodigoPais', $emisor->telefono->codigoPais);
            $this->el($doc, $tel, 'NumTelefono', $emisor->telefono->numTelefono);
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

        if ($receptor->telefono !== null) {
            $tel = $this->el($doc, $node, 'Telefono');
            $this->el($doc, $tel, 'CodigoPais', $receptor->telefono->codigoPais);
            $this->el($doc, $tel, 'NumTelefono', $receptor->telefono->numTelefono);
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

        if ($linea->codigoComercial !== null) {
            $codComercial = $this->el($doc, $node, 'CodigoComercial');
            $this->el($doc, $codComercial, 'Tipo', $linea->codigoComercialTipo ?? '01');
            $this->el($doc, $codComercial, 'Codigo', $linea->codigoComercial);
        }

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

        $this->el($doc, $node, 'ImpuestoAsumidoEmisorFabrica', $this->money(0.0));
        $this->el($doc, $node, 'ImpuestoNeto', $this->money($this->impuestoLinea($linea)));
        $this->el($doc, $node, 'MontoTotalLinea', $this->money($linea->montoTotalLinea));
    }

    private function buildResumenFactura(DOMDocument $doc, DOMElement $root, FacturaDto $dto): void
    {
        $node = $this->el($doc, $root, 'ResumenFactura');

        $moneda = $this->el($doc, $node, 'CodigoTipoMoneda');
        $this->el($doc, $moneda, 'CodigoMoneda', $dto->moneda);
        $this->el($doc, $moneda, 'TipoCambio', $this->money($dto->tipoCambio));

        $totalServGravados = 0.0;
        $totalServExentos = 0.0;
        $totalServNoSujeto = 0.0;
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
                $totalServGravados += $linea->subTotal;
            } elseif ($this->isNoSujeto($linea)) {
                $totalServNoSujeto += $linea->subTotal;
            } else {
                $totalServExentos += $linea->subTotal;
            }
        }

        $totalNoSujeto = $totalServNoSujeto;

        $this->el($doc, $node, 'TotalServGravados', $this->money($totalServGravados));
        $this->el($doc, $node, 'TotalServExentos', $this->money($totalServExentos));
        $this->el($doc, $node, 'TotalServExonerado', $this->money(0.0));
        $this->el($doc, $node, 'TotalServNoSujeto', $this->money($totalServNoSujeto));
        $this->el($doc, $node, 'TotalMercanciasGravadas', $this->money(0.0));
        $this->el($doc, $node, 'TotalMercanciasExentas', $this->money(0.0));
        $this->el($doc, $node, 'TotalMercExonerada', $this->money(0.0));
        $this->el($doc, $node, 'TotalMercNoSujeta', $this->money(0.0));
        $this->el($doc, $node, 'TotalGravado', $this->money($totalServGravados));
        $this->el($doc, $node, 'TotalExento', $this->money($totalServExentos));
        $this->el($doc, $node, 'TotalExonerado', $this->money(0.0));
        $this->el($doc, $node, 'TotalNoSujeto', $this->money($totalNoSujeto));
        $this->el($doc, $node, 'TotalVenta', $this->money($totalVenta));
        $this->el($doc, $node, 'TotalDescuentos', $this->money(0.0));
        $this->el($doc, $node, 'TotalVentaNeta', $this->money($totalVenta));

        foreach ($desglose as $row) {
            $totalDesglose = $this->el($doc, $node, 'TotalDesgloseImpuesto');
            $this->el($doc, $totalDesglose, 'Codigo', $row['codigo']);
            $this->el($doc, $totalDesglose, 'CodigoTarifaIVA', $row['codigoTarifa']);
            $this->el($doc, $totalDesglose, 'TotalMontoImpuesto', $this->money($row['monto']));
        }

        $this->el($doc, $node, 'TotalImpuesto', $this->money($totalImpuesto));
        $this->el($doc, $node, 'TotalImpAsumEmisorFabrica', $this->money(0.0));
        $this->el($doc, $node, 'TotalIVADevuelto', $this->money(0.0));

        $totalComprobante = $totalVenta + $totalImpuesto;

        $medioPago = $this->el($doc, $node, 'MedioPago');
        $this->el($doc, $medioPago, 'TipoMedioPago', $dto->medioPago);
        $this->el($doc, $medioPago, 'TotalMedioPago', $this->money($totalComprobante));

        $this->el($doc, $node, 'TotalComprobante', $this->money($totalComprobante));
    }

    private function impuestoLinea(LineaDetalleDto $linea): float
    {
        $total = 0.0;
        foreach ($linea->impuestos as $impuesto) {
            $total += $impuesto->monto;
        }

        return $total;
    }

    private function isNoSujeto(LineaDetalleDto $linea): bool
    {
        foreach ($linea->impuestos as $impuesto) {
            if ($impuesto->codigoTarifa === self::TARIFA_NO_SUJETO) {
                return true;
            }
        }

        return false;
    }

    /**
     * Format a monetary amount, trimming insignificant trailing zeros (and the
     * decimal point) to match the FE fixture's rendering: 0, 1, 86817.58.
     */
    private function money(float $value): string
    {
        $formatted = number_format($value, 5, '.', '');
        $formatted = rtrim($formatted, '0');

        return rtrim($formatted, '.');
    }

    /** Format a quantity with 2 decimals, matching the fixture's Cantidad rendering. */
    private function quantity(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    /** Format an IVA rate with 2 decimals (e.g. 0.00, 13.00), matching the fixture's Tarifa rendering. */
    private function rate(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    public function xsdPath(): string
    {
        return dirname(__DIR__, 2).'/resources/xsd/v4.4/facturaElectronica.xsd';
    }
}
