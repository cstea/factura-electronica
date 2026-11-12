<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Support;

use Stea\FacturaElectronica\Cabys;
use Stea\FacturaElectronica\Dtos\LineaDetalleDto;
use Stea\FacturaElectronica\Dtos\ResumenDto;

/**
 * Computes ResumenFactura from the lines, following the Anexo 1 rules: goods (CABYS 0-4) and services are totalled apart; each is
 * split into gravado / exento / exonerado / no sujeto using the line's Monto Total (before discounts); an exonerated line
 * is split by the share of its tax that was exonerated; TotalVentaNeta = TotalVenta - TotalDescuentos; the tax breakdown uses
 * net tax (tax minus exoneration). Callers that need full control pass their own {@see ResumenDto} instead.
 */
final class ResumenCalculator
{
    /** Tarifa codes that mean "no sujeto" (nota 8.1: 01 and 11). */
    private const NO_SUJETO = ['01', '11'];

    /** @param LineaDetalleDto[] $lineas */
    public static function desdeLineas(array $lineas, string|float $otrosCargos = 0): ResumenDto
    {
        $b = [
            'sg' => '0', 'se' => '0', 'sx' => '0', 'sn' => '0',
            'mg' => '0', 'me' => '0', 'mx' => '0', 'mn' => '0',
        ];
        $descuentos = '0';
        $totalVenta = '0';
        $totalImpuesto = '0';
        $asumido = '0';
        /** @var array<string, array{codigo: string, codigoTarifa: ?string, monto: string}> $desglose */
        $desglose = [];

        foreach ($lineas as $linea) {
            $monto = Decimal::of($linea->montoTotal);
            $totalVenta = Decimal::add($totalVenta, $monto);

            foreach ($linea->descuentos as $d) {
                $descuentos = Decimal::add($descuentos, $d->monto);
            }

            $impuesto = '0';
            $exonerado = '0';
            $noSujeto = false;

            foreach ($linea->impuestos as $i) {
                $neto = Decimal::sub($i->monto, $i->exoneracion !== null ? $i->exoneracion->montoExoneracion : 0);
                $impuesto = Decimal::add($impuesto, $i->monto);
                $exonerado = Decimal::add($exonerado, $i->exoneracion !== null ? $i->exoneracion->montoExoneracion : 0);
                $noSujeto = $noSujeto || in_array($i->codigoTarifa, self::NO_SUJETO, true);

                $clave = $i->codigo.'|'.$i->codigoTarifa;
                $desglose[$clave] ??= ['codigo' => $i->codigo, 'codigoTarifa' => $i->codigoTarifa, 'monto' => '0'];
                $desglose[$clave]['monto'] = Decimal::add($desglose[$clave]['monto'], $neto);
            }

            $neto = $linea->impuestoNeto !== null ? Decimal::of($linea->impuestoNeto) : Decimal::sub($impuesto, $exonerado);
            $totalImpuesto = Decimal::add($totalImpuesto, $neto);
            $asumido = Decimal::add($asumido, $linea->impuestoAsumidoEmisorFabrica);

            $mercancia = Cabys::esMercancia($linea->codigoCabys);
            [$g, $e, $x, $n] = ['0', '0', '0', '0'];

            if ($noSujeto) {
                $n = $monto;
            } elseif (Decimal::cmp($impuesto, 0) > 0) {
                if (Decimal::cmp($exonerado, 0) > 0) {
                    $x = Decimal::round(Decimal::mul($monto, Decimal::div($exonerado, $impuesto)));
                    $g = Decimal::sub($monto, $x);
                } else {
                    $g = $monto;
                }
            } else {
                $e = $monto;
            }

            $p = $mercancia ? 'm' : 's';
            $b[$p.'g'] = Decimal::add($b[$p.'g'], $g);
            $b[$p.'e'] = Decimal::add($b[$p.'e'], $e);
            $b[$p.'x'] = Decimal::add($b[$p.'x'], $x);
            $b[$p.'n'] = Decimal::add($b[$p.'n'], $n);
        }

        $gravado = Decimal::add($b['sg'], $b['mg']);
        $exento = Decimal::add($b['se'], $b['me']);
        $exoneradoT = Decimal::add($b['sx'], $b['mx']);
        $noSujetoT = Decimal::add($b['sn'], $b['mn']);
        $neta = Decimal::sub($totalVenta, $descuentos);

        return new ResumenDto(
            totalServGravados: Decimal::round($b['sg']),
            totalServExentos: Decimal::round($b['se']),
            totalServExonerado: Decimal::round($b['sx']),
            totalServNoSujeto: Decimal::round($b['sn']),
            totalMercanciasGravadas: Decimal::round($b['mg']),
            totalMercanciasExentas: Decimal::round($b['me']),
            totalMercExonerada: Decimal::round($b['mx']),
            totalMercNoSujeta: Decimal::round($b['mn']),
            totalGravado: Decimal::round($gravado),
            totalExento: Decimal::round($exento),
            totalExonerado: Decimal::round($exoneradoT),
            totalNoSujeto: Decimal::round($noSujetoT),
            totalVenta: Decimal::round($totalVenta),
            totalDescuentos: Decimal::round($descuentos),
            totalVentaNeta: Decimal::round($neta),
            desglose: array_values(array_map(static fn (array $d): array => [
                'codigo' => $d['codigo'], 'codigoTarifa' => $d['codigoTarifa'], 'monto' => Decimal::round($d['monto']),
            ], $desglose)),
            totalImpuesto: Decimal::round($totalImpuesto),
            totalImpAsumEmisorFabrica: Decimal::round($asumido),
            totalComprobante: Decimal::round(Decimal::add(Decimal::add($neta, $totalImpuesto), $otrosCargos)),
        );
    }
}
