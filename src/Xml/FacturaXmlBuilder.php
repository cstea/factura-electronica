<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Xml;

use Stea\FacturaElectronica\Dtos\FacturaDto;
use Stea\FacturaElectronica\Xml\Concerns\BuildsComprobanteVenta;

/** Builds the unsigned body of a Factura Electrónica (FE, tipo 01). Shares everything with the other sales documents through {@see BuildsComprobanteVenta}. */
final class FacturaXmlBuilder implements DocumentBuilder
{
    use BuildsComprobanteVenta;

    private const NS = 'https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/facturaElectronica';

    protected function raiz(): string
    {
        return 'FacturaElectronica';
    }

    protected function namespace(): string
    {
        return self::NS;
    }

    protected function dtoClass(): string
    {
        return FacturaDto::class;
    }

    public function xsdPath(): string
    {
        return dirname(__DIR__, 2).'/resources/xsd/v4.4/facturaElectronica.xsd';
    }
}
