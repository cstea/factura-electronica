<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Xml;

use Stea\FacturaElectronica\Dtos\NotaDebitoDto;
use Stea\FacturaElectronica\Xml\Concerns\BuildsComprobanteVenta;

/** Builds the unsigned body of a Nota de Débito Electrónica (ND, tipo 02); same schema as the NC. Shares everything with the other sales documents through {@see BuildsComprobanteVenta}. */
final class NotaDebitoXmlBuilder implements DocumentBuilder
{
    use BuildsComprobanteVenta;

    private const NS = 'https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/notaDebitoElectronica';

    protected function raiz(): string
    {
        return 'NotaDebitoElectronica';
    }

    protected function namespace(): string
    {
        return self::NS;
    }

    protected function dtoClass(): string
    {
        return NotaDebitoDto::class;
    }

    public function xsdPath(): string
    {
        return dirname(__DIR__, 2).'/resources/xsd/v4.4/notaDebitoElectronica.xsd';
    }
}
