<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Hacienda;

/** What Hacienda answered to a POST /recepcion, classified by what the caller should do next. */
final readonly class RespuestaEnvio
{
    /** Hacienda took the comprobante (HTTP 2xx): wait for the validation. */
    public const RECIBIDO = 'recibido';

    /** Hacienda already had this clave (HTTP 400 "ya fue recibido"): same as RECIBIDO, a retry is harmless. */
    public const YA_RECIBIDO = 'ya_recibido';

    /** Hacienda refused the content (HTTP 400): retrying the same XML will not help. */
    public const RECHAZO = 'rechazo';

    /** Network, timeout, 401/403/429 or 5xx: retry later with the same signed XML. */
    public const TRANSITORIO = 'transitorio';

    public function __construct(
        public string $clase,
        public int $status,
        public ?string $causa = null,
        public string $cuerpo = '',
    ) {}

    public function fueRecibido(): bool
    {
        return $this->clase === self::RECIBIDO || $this->clase === self::YA_RECIBIDO;
    }

    public function esTransitorio(): bool
    {
        return $this->clase === self::TRANSITORIO;
    }
}
