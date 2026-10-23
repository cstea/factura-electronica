<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Tests\Unit\Credentials;

use PHPUnit\Framework\TestCase;
use Stea\FacturaElectronica\Credentials\SigningCertificate;
use Stea\FacturaElectronica\Exceptions\SigningException;

final class CertificadoValidarTest extends TestCase
{
    private const RUTA = __DIR__.'/../../fixtures/cert/test.p12';

    public function test_lee_sujeto_vigencia_y_bits(): void
    {
        $info = SigningCertificate::fromPath(self::RUTA, '1234')->validar();

        $this->assertSame('FE Test', $info->sujeto);
        $this->assertGreaterThanOrEqual(2048, $info->bits);
        $this->assertFalse($info->vencido());
        $this->assertGreaterThan(300, $info->diasRestantes());
    }

    public function test_un_pin_incorrecto_da_un_mensaje_accionable(): void
    {
        $this->expectException(SigningException::class);
        $this->expectExceptionMessage('PIN');
        SigningCertificate::fromPath(self::RUTA, 'mal')->validar();
    }

    public function test_un_archivo_que_no_es_p12_falla_sin_filtrar_el_pin(): void
    {
        try {
            SigningCertificate::fromContents('basura', 'secreto')->validar();
            $this->fail('Debió lanzar SigningException');
        } catch (SigningException $e) {
            $this->assertStringNotContainsString('secreto', $e->getMessage());
        }
    }
}
