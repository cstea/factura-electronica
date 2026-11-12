<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Xml;

use Stea\FacturaElectronica\Dtos\NotaCreditoDto;
use Stea\FacturaElectronica\Xml\Concerns\BuildsComprobanteVenta;

/** Builds the unsigned body of a Nota de Crédito Electrónica (NC, tipo 03); InformacionReferencia is required. Shares everything with the other sales documents through {@see BuildsComprobanteVenta}. */
final class NotaCreditoXmlBuilder implements DocumentBuilder
{
    use BuildsComprobanteVenta;

    private const NS = 'https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/notaCreditoElectronica';

    protected function raiz(): string
    {
        return 'NotaCreditoElectronica';
    }

    protected function namespace(): string
    {
        return self::NS;
    }

    protected function dtoClass(): string
    {
        return NotaCreditoDto::class;
    }

    public function xsdPath(): string
    {
        return dirname(__DIR__, 2).'/resources/xsd/v4.4/notaCreditoElectronica.xsd';
    }
}
