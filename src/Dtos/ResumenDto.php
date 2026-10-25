<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Dtos;

/**
 * Los totales del comprobante ya calculados por quien lo emite. Si un DTO de venta los trae, el constructor los emite tal cual
 * (no recalcula): así el sistema controla el redondeo y lo que ve el cliente coincide con el XML.
 */
final readonly class ResumenDto
{
    /** @param list<array{codigo: string, codigoTarifa: ?string, monto: string|float}> $desglose */
    public function __construct(
        public string|float $totalServGravados = '0',
        public string|float $totalServExentos = '0',
        public string|float $totalServExonerado = '0',
        public string|float $totalServNoSujeto = '0',
        public string|float $totalMercanciasGravadas = '0',
        public string|float $totalMercanciasExentas = '0',
        public string|float $totalMercExonerada = '0',
        public string|float $totalMercNoSujeta = '0',
        public string|float $totalGravado = '0',
        public string|float $totalExento = '0',
        public string|float $totalExonerado = '0',
        public string|float $totalNoSujeto = '0',
        public string|float $totalVenta = '0',
        public string|float $totalDescuentos = '0',
        public string|float $totalVentaNeta = '0',
        public array $desglose = [],
        public string|float $totalImpuesto = '0',
        public string|float $totalImpAsumEmisorFabrica = '0',
        public string|float $totalComprobante = '0',
    ) {}
}
