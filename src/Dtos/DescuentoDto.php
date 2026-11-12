<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Dtos;

/** Un descuento de la línea (hasta 5; cada uno se calcula sobre la base menos el anterior). Códigos: nota 20 del Anexo. */
final readonly class DescuentoDto
{
    public function __construct(
        public string|float $monto,
        public string $codigo = '07',          // 07 = descuento comercial; 99 = otros (exige $naturaleza)
        public ?string $codigoOtro = null,     // descripción cuando el código es 99
        public ?string $naturaleza = null,     // obligatoria con el código 99
    ) {}
}
