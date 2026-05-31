<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Xml;

use DOMDocument;
use LibXMLError;

final class XsdValidator
{
    /** @var string[] */
    private array $errors = [];

    public function validate(DOMDocument $doc, string $xsdPath): bool
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $ok = $doc->schemaValidate($xsdPath);

        $this->errors = array_map(
            static fn (LibXMLError $e): string => trim($e->message).' (line '.$e->line.')',
            libxml_get_errors(),
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $ok;
    }

    /** @return string[] */
    public function errors(): array
    {
        return $this->errors;
    }
}
