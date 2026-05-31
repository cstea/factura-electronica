<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Xml;

use DOMDocument;

interface DocumentBuilder
{
    /** Build the (unsigned) comprobante DOM from its request DTO + the precomputed clave. */
    public function build(object $dto, string $clave): DOMDocument;

    /** Absolute path to the XSD this builder's output validates against. */
    public function xsdPath(): string;
}
