<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use Stea\FacturaElectronica\Enums\Environment;

final class EnvironmentTest extends TestCase
{
    public function test_sandbox_urls_match_hacienda_endpoints(): void
    {
        $env = Environment::Sandbox;
        $this->assertSame('api-stag', $env->clientId());
        $this->assertSame(
            'https://idp.comprobanteselectronicos.go.cr/auth/realms/rut-stag/protocol/openid-connect/token',
            $env->idpUrl(),
        );
        $this->assertSame(
            'https://api-sandbox.comprobanteselectronicos.go.cr/recepcion/v1/recepcion/',
            $env->recepcionUrl(),
        );
    }

    public function test_production_urls_match_hacienda_endpoints(): void
    {
        $env = Environment::Production;
        $this->assertSame('api-prod', $env->clientId());
        $this->assertSame(
            'https://idp.comprobanteselectronicos.go.cr/auth/realms/rut/protocol/openid-connect/token',
            $env->idpUrl(),
        );
        $this->assertSame(
            'https://api.comprobanteselectronicos.go.cr/recepcion/v1/recepcion/',
            $env->recepcionUrl(),
        );
    }
}
