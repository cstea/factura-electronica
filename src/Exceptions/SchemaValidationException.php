<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Exceptions;

/**
 * The signed comprobante does not satisfy Hacienda's v4.4 XSD. Thrown before
 * anything is sent, so the consecutivo is not spent on a document Hacienda
 * would reject.
 */
final class SchemaValidationException extends FacturaElectronicaException
{
    /**
     * @param  string[]  $errors
     */
    public function __construct(
        public readonly string $clave,
        public readonly array $errors,
    ) {
        parent::__construct("Comprobante {$clave} fails Hacienda's v4.4 schema: ".implode('; ', array_slice($errors, 0, 5)));
    }
}
