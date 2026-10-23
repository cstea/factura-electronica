<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Xml;

use Stea\FacturaElectronica\Dtos\TiqueteDto;
use Stea\FacturaElectronica\Xml\Concerns\BuildsComprobanteVenta;

/** Builds the unsigned body of a Tiquete Electrónico (TE, tipo 04): the receptor is optional. Shares everything with the other sales documents through {@see BuildsComprobanteVenta}. */
final class TiqueteXmlBuilder implements DocumentBuilder
{
    use BuildsComprobanteVenta;

    private const NS = 'https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/tiqueteElectronico';

    protected function raiz(): string
    {
        return 'TiqueteElectronico';
    }

    protected function namespace(): string
    {
        return self::NS;
    }

    protected function dtoClass(): string
    {
        return TiqueteDto::class;
    }

    protected function llevaActividadReceptor(): bool
    {
        return false;
    }

    protected function llevaTipoTransaccion(): bool
    {
        return false;
    }

    public function xsdPath(): string
    {
        return dirname(__DIR__, 2).'/resources/xsd/v4.4/tiqueteElectronico.xsd';
    }
}
