<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Respuesta;

use DOMDocument;
use DOMXPath;

final class MensajeHaciendaParser
{
    /** A MensajeHacienda is a few KB; anything far larger is not one. */
    private const MAX_BYTES = 1_048_576;

    /** Null if the text is not a MensajeHacienda document. */
    public static function parse(string $xml): ?MensajeHacienda
    {
        if (trim($xml) === '' || strlen($xml) > self::MAX_BYTES) {
            return null;
        }

        // Hacienda never sends a DTD; refusing one rules out entity expansion and external entities whatever libxml's defaults are.
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument;
        $ok = $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $ok) {
            return null;
        }

        $xpath = new DOMXPath($doc);
        $texto = static function (string $nombre) use ($xpath): ?string {
            $n = $xpath->query('//*[local-name()="'.$nombre.'"]')->item(0);

            return $n === null ? null : trim($n->textContent);
        };

        $clave = $texto('Clave');
        $mensaje = $texto('Mensaje');

        if ($clave === null || $mensaje === null || ! ctype_digit($mensaje)) {
            return null;
        }

        return new MensajeHacienda(
            $clave,
            (int) $mensaje,
            $texto('DetalleMensaje') ?? '',
            $texto('MontoTotalImpuesto'),
            $texto('TotalFactura'),
        );
    }
}
