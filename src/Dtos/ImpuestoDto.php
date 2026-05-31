<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Dtos;

final readonly class ImpuestoDto
{
    public function __construct(
        public string $codigo,        // 01 = IVA
        public string $codigoTarifa,  // e.g. 10
        public float $tarifa,         // e.g. 0.0
        public float $monto,
    ) {}
}
