<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Enums;

enum EstadoComprobante: string
{
    case Enviado = 'enviado';        // local: accepted by recepción endpoint, not yet resolved
    case Recibido = 'recibido';
    case Procesando = 'procesando';
    case Aceptado = 'aceptado';
    case Rechazado = 'rechazado';
    case Error = 'error';

    public static function fromHacienda(string $indEstado): self
    {
        return self::tryFrom(strtolower(trim($indEstado))) ?? self::Error;
    }
}
