<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Dtos\Contracts;

use DateTimeInterface;
use Stea\FacturaElectronica\Dtos\IdentificacionDto;
use Stea\FacturaElectronica\Enums\Situacion;

interface ComprobanteRequest
{
    public function consecutivo(): string;

    public function fechaEmision(): DateTimeInterface;

    public function cedulaEmisor(): string;

    public function tipoIdentificacionEmisor(): string;

    public function situacion(): Situacion;

    /** Receptor identification for the recepción payload; null when not applicable (e.g. foreign receptor). */
    public function receptorIdentificacion(): ?IdentificacionDto;
}
