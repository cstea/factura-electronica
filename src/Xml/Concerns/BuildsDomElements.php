<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Xml\Concerns;

use DOMDocument;
use DOMElement;

trait BuildsDomElements
{
    private function el(DOMDocument $doc, DOMElement $parent, string $name, ?string $text = null): DOMElement
    {
        $node = $doc->createElement($name);
        if ($text !== null) {
            $node->appendChild($doc->createTextNode($text));
        }
        $parent->appendChild($node);

        return $node;
    }

    private function money(float $value): string
    {
        return number_format($value, 5, '.', '');
    }
}
