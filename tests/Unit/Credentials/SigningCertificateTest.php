<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Tests\Unit\Credentials;

use PHPUnit\Framework\TestCase;
use Stea\FacturaElectronica\Credentials\SigningCertificate;

final class SigningCertificateTest extends TestCase
{
    public function test_from_path_reads_bytes_and_hides_pin(): void
    {
        $path = sys_get_temp_dir().'/fe_test.bin';
        file_put_contents($path, 'PKCSBYTES');
        $cert = SigningCertificate::fromPath($path, '1234');

        $this->assertSame('PKCSBYTES', $cert->contents());
        $this->assertSame('1234', $cert->pin());
        $this->assertStringNotContainsString('1234', print_r($cert, true));
        @unlink($path);
    }

    public function test_missing_file_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SigningCertificate::fromPath('/no/such.p12', '1234');
    }
}
