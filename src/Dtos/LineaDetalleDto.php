<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Dtos;

final readonly class LineaDetalleDto
{
    /**
     * @param  ImpuestoDto[]  $impuestos
     * @param  DescuentoDto[]  $descuentos  hasta 5
     */
    public function __construct(
        public int $numeroLinea,
        public string $codigoCabys,
        public float|string $cantidad,
        public string $unidadMedida,
        public string $detalle,
        public float|string $precioUnitario,
        public float|string $montoTotal,
        public float|string $subTotal,
        public float|string $montoTotalLinea,
        public array $impuestos = [],
        public string $tipoTransaccion = '01',
        public ?string $codigoComercialTipo = null,
        public ?string $codigoComercial = null,
        public float|string|null $baseImponible = null,
        public array $descuentos = [],
        public float|string|null $impuestoNeto = null,   // por omisión: impuestos − exoneraciones
        public float|string $impuestoAsumidoEmisorFabrica = 0,
    ) {}
}
