<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Consultas;

/** Un código del catálogo CABYS (GET /fe/cabys). */
final readonly class CabysItem
{
    /** @param list<string> $categorias */
    public function __construct(
        public string $codigo,
        public string $descripcion,
        public float $impuesto,       // IVA sugerido, en puntos (13, 4, 1, 0…)
        public array $categorias = [],
    ) {}
}
