<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Exceptions;

final class HaciendaSendException extends FacturaElectronicaException
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : "Hacienda recepción failed: HTTP {$status} — ".substr($body, 0, 600));
    }
}
