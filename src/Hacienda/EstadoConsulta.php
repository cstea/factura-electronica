<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Hacienda;

use Stea\FacturaElectronica\Enums\EstadoComprobante;
use Stea\FacturaElectronica\Respuesta\MensajeHacienda;

/** The state of a comprobante according to GET /recepcion/{clave}. */
final readonly class EstadoConsulta
{
    public function __construct(
        public int $status,
        public ?EstadoComprobante $estado,
        public ?string $respuestaXml = null,
        public ?MensajeHacienda $mensaje = null,
        public bool $transitorio = false,
    ) {}

    /** Hacienda has no record of the clave (yet): HTTP 404. */
    public function noEncontrado(): bool
    {
        return $this->status === 404;
    }

    public function resuelto(): bool
    {
        return $this->estado === EstadoComprobante::Aceptado || $this->estado === EstadoComprobante::Rechazado;
    }
}
