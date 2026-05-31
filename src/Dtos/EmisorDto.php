<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Dtos;

final readonly class EmisorDto
{
    public function __construct(
        public string $nombre,
        public IdentificacionDto $identificacion,
        public ?UbicacionDto $ubicacion = null,
        public ?string $correoElectronico = null,
        public ?string $nombreComercial = null,
        public ?TelefonoDto $telefono = null,
        public ?string $otrasSenasExtranjero = null,
    ) {}
}
