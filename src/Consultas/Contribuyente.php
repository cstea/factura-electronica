<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Consultas;

/** Lo que Hacienda sabe de una cédula (GET /fe/ae). */
final readonly class Contribuyente
{
    /** @param list<Actividad> $actividades */
    public function __construct(
        public string $identificacion,
        public string $nombre,
        public string $tipoIdentificacion,   // 01 física · 02 jurídica · 03 DIMEX · 04 NITE
        public ?string $regimen,
        public string $estado,               // "Inscrito", "No inscrito", "Inscrito de oficio"…
        public bool $moroso,
        public bool $omiso,
        public ?string $mensaje,
        public array $actividades,
    ) {}

    public function inscrito(): bool
    {
        return str_starts_with(mb_strtolower($this->estado), 'inscrito');
    }

    /** @return list<Actividad> */
    public function actividadesActivas(): array
    {
        return array_values(array_filter($this->actividades, static fn (Actividad $a): bool => $a->activa()));
    }
}
