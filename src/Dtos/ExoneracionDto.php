<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Dtos;

use DateTimeInterface;

/** Nodo Exoneracion de un impuesto de línea. Tipos de documento: nota 10.1; instituciones: nota 23. */
final readonly class ExoneracionDto
{
    public function __construct(
        public string $tipoDocumento,            // 01..11, 99
        public string $numeroDocumento,          // tal como está en el documento, con guiones (3-40 caracteres)
        public string $nombreInstitucion,        // 01..12, 99
        public DateTimeInterface $fechaEmision,
        public string|float $tarifaExonerada,    // puntos de la tarifa exonerada (13 = total del IVA general)
        public string|float $montoExoneracion,   // tarifaExonerada × subtotal / 100
        public ?string $tipoDocumentoOtro = null,
        public ?int $articulo = null,
        public ?int $inciso = null,
        public ?string $nombreInstitucionOtros = null,
    ) {}
}
