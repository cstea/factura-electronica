<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Dtos;

final readonly class TelefonoDto
{
    public function __construct(
        public string $codigoPais,   // e.g. 506
        public string $numTelefono,
    ) {}
}
