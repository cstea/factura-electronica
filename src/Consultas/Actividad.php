<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Consultas;

/** Una actividad económica inscrita ante Hacienda (el código viene como "3510.0"). */
final readonly class Actividad
{
    public function __construct(
        public string $codigo,
        public string $descripcion,
        public string $estado,   // A activa · D inactiva
        public string $tipo,     // P principal · S secundaria
    ) {}

    public function activa(): bool
    {
        return strtoupper($this->estado) === 'A';
    }

    /** El código de 6 dígitos que lleva el XML: "3510.0" → "351000". */
    public function codigoXml(): string
    {
        [$entero, $decimal] = array_pad(explode('.', $this->codigo, 2), 2, '');

        return str_pad(preg_replace('/\D/', '', $entero) ?? '', 4, '0', STR_PAD_LEFT).str_pad(substr(preg_replace('/\D/', '', $decimal) ?? '', 0, 2), 2, '0');
    }
}
