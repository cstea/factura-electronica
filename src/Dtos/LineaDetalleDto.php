<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Dtos;

final readonly class LineaDetalleDto
{
    /** @param ImpuestoDto[] $impuestos */
    public function __construct(
        public int $numeroLinea,
        public string $codigoCabys,
        public float $cantidad,
        public string $unidadMedida,
        public string $detalle,
        public float $precioUnitario,
        public float $montoTotal,
        public float $subTotal,
        public float $montoTotalLinea,
        public array $impuestos = [],
        public string $tipoTransaccion = '01',
        public ?string $codigoComercialTipo = null,
        public ?string $codigoComercial = null,
        public ?float $baseImponible = null,
    ) {}
}
