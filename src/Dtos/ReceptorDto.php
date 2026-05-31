<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Dtos;

final readonly class ReceptorDto
{
    public function __construct(
        public string $nombre,
        public ?IdentificacionDto $identificacion = null,
        public ?string $identificacionExtranjero = null,
        public ?string $otrasSenasExtranjero = null,
        public ?string $correoElectronico = null,
        public ?UbicacionDto $ubicacion = null,
        public ?TelefonoDto $telefono = null,
    ) {}
}
