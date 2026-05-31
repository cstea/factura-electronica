<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Clave;

use DateTimeInterface;
use InvalidArgumentException;
use Stea\FacturaElectronica\Enums\Situacion;

final class ClaveGenerator
{
    private const PAIS = '506';

    public function generate(
        string $cedula,
        DateTimeInterface $fecha,
        string $consecutivo,
        Situacion $situacion,
        string $codigoSeguridad,
    ): string {
        $cedulaDigits = preg_replace('/\D+/', '', $cedula);

        if (strlen($consecutivo) !== 20 || ! ctype_digit($consecutivo)) {
            throw new InvalidArgumentException('Consecutivo must be exactly 20 digits.');
        }
        if (strlen($codigoSeguridad) !== 8 || ! ctype_digit($codigoSeguridad)) {
            throw new InvalidArgumentException('Codigo de seguridad must be exactly 8 digits.');
        }

        return self::PAIS
            .$fecha->format('dmy')
            .str_pad($cedulaDigits, 12, '0', STR_PAD_LEFT)
            .$consecutivo
            .$situacion->value
            .$codigoSeguridad;
    }
}
