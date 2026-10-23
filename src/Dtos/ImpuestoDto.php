<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Dtos;

final readonly class ImpuestoDto
{
    public function __construct(
        public string $codigo,              // 01 = IVA
        public string $codigoTarifa,        // e.g. 08
        public float|string $tarifa,        // e.g. 13
        public float|string $monto,
        public ?ExoneracionDto $exoneracion = null,
    ) {}
}
