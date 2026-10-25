<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stea\FacturaElectronica\Tests\Support\SandboxIdentity;

final class SandboxIdentityTest extends TestCase
{
    public function test_reads_a_fisica_cedula_from_the_certificate_serial(): void
    {
        $id = SandboxIdentity::fromSerialNumber('CPF-09-0107-0387');

        $this->assertSame('01', $id->tipo);
        $this->assertSame('901070387', $id->cedula);
    }

    public function test_reads_a_juridica_cedula_from_the_certificate_serial(): void
    {
        $id = SandboxIdentity::fromSerialNumber('CPJ-3-102-935763');

        $this->assertSame('02', $id->tipo);
        $this->assertSame('3102935763', $id->cedula);
    }

    public function test_an_unknown_serial_asks_for_an_explicit_cedula(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/FE_SANDBOX_CEDULA/');

        SandboxIdentity::fromSerialNumber('CN=SOMETHING ELSE');
    }
}
