<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Dtos;

use Stea\FacturaElectronica\Enums\EstadoComprobante;

final readonly class ResultadoDto
{
    public function __construct(
        public string $clave,
        public string $consecutivo,
        public string $signedXml,
        public EstadoComprobante $estado,
        public ?string $respuestaXml = null,
    ) {}
}
