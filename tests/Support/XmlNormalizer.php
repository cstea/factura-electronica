<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Tests\Support;

use DOMDocument;

final class XmlNormalizer
{
    /** Canonicalize the comprobante body: drop the ds:Signature subtree + insignificant whitespace. */
    public static function bodyC14n(string $xml): string
    {
        $doc = new DOMDocument;
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = false;
        $doc->loadXML($xml);

        foreach (iterator_to_array($doc->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'Signature')) as $sig) {
            $sig->parentNode?->removeChild($sig);
        }

        return (string) $doc->C14N();
    }
}
