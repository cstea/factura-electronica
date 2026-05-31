<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Tests\Feature;

use Orchestra\Testbench\TestCase;
use Stea\FacturaElectronica\FacturaElectronicaManager;
use Stea\FacturaElectronica\FacturaElectronicaServiceProvider;

final class ServiceProviderTest extends TestCase
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [FacturaElectronicaServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('factura-electronica.environment', 'sandbox');
        $app['config']->set('factura-electronica.credentials.username', 'u@stag');
        $app['config']->set('factura-electronica.credentials.password', 'p');
        $app['config']->set('factura-electronica.certificate.path', __DIR__.'/../fixtures/cert/test.p12');
        $app['config']->set('factura-electronica.certificate.pin', '1234');
    }

    public function test_service_provider_resolves_manager(): void
    {
        $manager = $this->app->make(FacturaElectronicaManager::class);

        $this->assertInstanceOf(FacturaElectronicaManager::class, $manager);
    }
}
