<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Respuesta;

/** The MensajeHacienda XML that Hacienda signs when it finishes validating a comprobante. */
final readonly class MensajeHacienda
{
    public function __construct(
        public string $clave,
        public int $mensaje,            // 1 aceptado · 3 rechazado
        public string $detalle,
        public ?string $montoTotalImpuesto = null,
        public ?string $totalFactura = null,
    ) {}

    public function aceptado(): bool
    {
        return $this->mensaje === 1;
    }
}
