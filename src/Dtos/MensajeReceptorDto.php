<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Dtos;

use DateTimeInterface;

/**
 * Mensaje Receptor (MR, tipo 05 aceptado / 06 aceptado parcial / 07 rechazo).
 *
 * An MR is STEA's "comprobante de recibido": it acknowledges a comprobante a
 * supplier already sent us. It is fundamentally different from a sales document
 * — it does NOT generate a new 50-digit clave. Its {@see claveComprobante} IS
 * the clave of the RECEIVED supplier comprobante. There is no Emisor/líneas/
 * ResumenFactura; instead it carries the received doc's clave, the supplier's
 * cédula, the message type, tax/total fields, and STEA's own
 * {@see numeroConsecutivoReceptor} (whose tipo segment encodes 05/06/07).
 *
 * This is a plain value DTO and intentionally does NOT implement
 * {@see Contracts\ComprobanteRequest}: that contract models clave generation,
 * which does not apply to an MR.
 */
final readonly class MensajeReceptorDto
{
    public function __construct(
        public string $claveComprobante,            // received doc clave, 50 digits
        public string $numeroCedulaEmisor,          // supplier cédula
        public DateTimeInterface $fechaEmisionDoc,
        public int $mensaje,                        // 1 = aceptado, 2 = aceptado parcial, 3 = rechazo
        public float $montoTotalImpuesto,
        public string $codigoActividad,
        public string $condicionImpuesto,
        public float $montoTotalImpuestoAcreditar,
        public float $montoTotalDeGastoAplicable,
        public float $totalFactura,
        public string $numeroCedulaReceptor,        // the receptor (issuer of this message)
        public string $numeroConsecutivoReceptor,   // the receptor's own consecutivo, 20 digits (tipo 05/06/07)
        public ?string $detalleMensaje = null,
    ) {}

    public function numeroConsecutivoReceptor(): string
    {
        return $this->numeroConsecutivoReceptor;
    }
}
