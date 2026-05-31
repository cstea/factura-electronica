<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Exceptions;

final class HaciendaRejectedException extends FacturaElectronicaException
{
    public function __construct(
        public readonly string $clave,
        public readonly string $respuestaXml,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : "Hacienda rejected comprobante {$clave}");
    }
}
