<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Dtos;

/**
 * A comprobante that is built, validated against its XSD and signed, but not sent. Store `xmlFirmado` as is: a retry must resend these
 * exact bytes (signing again would change the SigningTime and the digests).
 */
final readonly class Preparado
{
    /** @param array{emisorTipo:string,emisorNumero:string,receptorTipo?:string,receptorNumero?:string,fecha?:string,consecutivoReceptor?:string} $payload */
    public function __construct(
        public string $clave,
        public string $consecutivo,
        public string $codigoSeguridad,
        public string $xmlFirmado,
        public array $payload,
    ) {}
}
