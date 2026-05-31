<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Tests\Unit\Clave;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Stea\FacturaElectronica\Clave\ClaveGenerator;
use Stea\FacturaElectronica\Enums\Situacion;

final class ClaveGeneratorTest extends TestCase
{
    public function test_builds_expected_clave_from_synthetic_data(): void
    {
        // Synthetic deterministic vector — no real cédulas.
        // Cedula 3101000000, date 2026-01-01, consecutivo 00100001090000000001,
        // Situacion::Normal (1), security 00000001.
        // Expected: 506 + 010126 + 003101000000 + 00100001090000000001 + 1 + 00000001
        $clave = (new ClaveGenerator)->generate(
            cedula: '3101000000',
            fecha: new DateTimeImmutable('2026-01-01'),
            consecutivo: '00100001090000000001',
            situacion: Situacion::Normal,
            codigoSeguridad: '00000001',
        );

        $this->assertSame('50601012600310100000000100001090000000001100000001', $clave);
        $this->assertSame(50, strlen($clave));
    }

    public function test_rejects_consecutivo_not_20_digits(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ClaveGenerator)->generate('3101000000', new DateTimeImmutable, '123', Situacion::Normal, '00000001');
    }
}
