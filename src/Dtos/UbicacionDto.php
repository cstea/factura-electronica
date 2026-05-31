<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Dtos;

final readonly class UbicacionDto
{
    public function __construct(
        public string $provincia,
        public string $canton,
        public string $distrito,
        public ?string $barrio = null,
        public ?string $otrasSenas = null,
    ) {}
}
