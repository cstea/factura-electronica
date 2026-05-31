<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Dtos;

use DateTimeInterface;

final readonly class InformacionReferenciaDto
{
    public function __construct(
        public string $tipoDocIR,                 // e.g. 16 = otro
        public string $numero,
        public DateTimeInterface $fechaEmisionIR,
        public string $codigo,                    // e.g. 11
        public string $razon,
    ) {}
}
