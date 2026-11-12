<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Dtos;

/** Un medio de pago (hasta 4 por comprobante; el monto es obligatorio cuando son varios). Códigos: nota 6. */
final readonly class MedioPagoDto
{
    public function __construct(
        public string $tipo,                   // 01 efectivo · 02 tarjeta · 03 cheque · 04 transferencia · 05 terceros · 06 SINPE Móvil · 07 plataforma · 99 otros
        public string|float|null $total = null,
        public ?string $otros = null,          // descripción cuando el tipo es 99
    ) {}
}
