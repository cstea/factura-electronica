<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Dtos;

final readonly class IdentificacionDto
{
    public function __construct(
        public string $tipo,   // 01 física, 02 jurídica, 03 DIMEX, 04 NITE, 05 extranjero
        public string $numero,
    ) {}
}
