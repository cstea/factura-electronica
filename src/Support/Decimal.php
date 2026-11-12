<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Support;

/**
 * Decimal arithmetic on numeric strings (bcmath), so amounts never travel as binary floats.
 *
 * Hacienda recalculates every line and every total and rejects a comprobante that does not add up, so the
 * rounding rule matters: half away from zero, at the scale the field allows (5 decimals for money).
 */
final class Decimal
{
    /** Scale used internally; money fields have 5 decimals, so 12 keeps every intermediate product exact. */
    private const WORK = 12;

    /** Normalises an int, float or numeric string to a plain decimal string ("12.5", "-0.001"). */
    public static function of(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        if (is_float($value)) {
            // 15 significant digits is what a float can actually carry; this drops binary noise such as 0.1+0.2.
            $value = rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');
        }

        $value = trim((string) $value);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            throw new \InvalidArgumentException("Not a decimal number: {$value}");
        }

        return $value;
    }

    public static function add(string|int|float $a, string|int|float $b): string
    {
        return bcadd(self::of($a), self::of($b), self::WORK);
    }

    public static function sub(string|int|float $a, string|int|float $b): string
    {
        return bcsub(self::of($a), self::of($b), self::WORK);
    }

    public static function mul(string|int|float $a, string|int|float $b): string
    {
        return bcmul(self::of($a), self::of($b), self::WORK);
    }

    public static function div(string|int|float $a, string|int|float $b): string
    {
        return bcdiv(self::of($a), self::of($b), self::WORK);
    }

    public static function cmp(string|int|float $a, string|int|float $b): int
    {
        return bccomp(self::of($a), self::of($b), self::WORK);
    }

    public static function isZero(string|int|float $a): bool
    {
        return self::cmp($a, 0) === 0;
    }

    /** Rounds half away from zero to $scale decimals and returns exactly $scale decimals. */
    public static function round(string|int|float $value, int $scale = 5): string
    {
        $v = self::of($value);
        $negative = str_starts_with($v, '-');
        $abs = $negative ? substr($v, 1) : $v;

        // Add 0.5 × 10^-scale and truncate: half-up on the magnitude.
        $half = '0.'.str_repeat('0', $scale).'5';
        $rounded = bcadd($abs, $half, $scale + 1);
        $rounded = bcadd(substr($rounded, 0, strlen($rounded) - 1), '0', $scale);

        if ($scale === 0) {
            $rounded = (string) (int) $rounded;
        }

        return ($negative && bccomp($rounded, '0', $scale) !== 0) ? '-'.$rounded : $rounded;
    }

    /** Money as the XML carries it: rounded to 5 decimals and without insignificant trailing zeros ("0", "1", "86817.58"). */
    public static function money(string|int|float|null $value): string
    {
        $r = self::round($value ?? 0, 5);
        $r = rtrim($r, '0');
        $r = rtrim($r, '.');

        return $r === '' || $r === '-' ? '0' : $r;
    }

    /** A fixed number of decimals ("13.00", "1.500"). */
    public static function fixed(string|int|float|null $value, int $scale): string
    {
        return self::round($value ?? 0, $scale);
    }
}
