<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Stea\FacturaElectronica\Clave\ClaveGenerator;
use Stea\FacturaElectronica\Credentials\ApiCredentials;
use Stea\FacturaElectronica\Credentials\SigningCertificate;
use Stea\FacturaElectronica\Enums\Environment;
use Stea\FacturaElectronica\Enums\TipoDocumento;
use Stea\FacturaElectronica\Hacienda\HaciendaClient;
use Stea\FacturaElectronica\Signing\XadesEpesSigner;
use Stea\FacturaElectronica\Xml\BuilderRegistry;
use Stea\FacturaElectronica\Xml\FacturaCompraXmlBuilder;
use Stea\FacturaElectronica\Xml\FacturaExportacionXmlBuilder;
use Stea\FacturaElectronica\Xml\FacturaXmlBuilder;
use Stea\FacturaElectronica\Xml\MensajeReceptorXmlBuilder;
use Stea\FacturaElectronica\Xml\NotaCreditoXmlBuilder;
use Stea\FacturaElectronica\Xml\NotaDebitoXmlBuilder;
use Stea\FacturaElectronica\Xml\TiqueteXmlBuilder;

final class FacturaElectronicaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/factura-electronica.php', 'factura-electronica');

        $this->app->singleton(BuilderRegistry::class, function (): BuilderRegistry {
            $registry = new BuilderRegistry;
            $registry->register(TipoDocumento::Factura, new FacturaXmlBuilder);
            $registry->register(TipoDocumento::FacturaExportacion, new FacturaExportacionXmlBuilder);
            $registry->register(TipoDocumento::FacturaCompra, new FacturaCompraXmlBuilder);
            $registry->register(TipoDocumento::NotaCredito, new NotaCreditoXmlBuilder);
            $registry->register(TipoDocumento::NotaDebito, new NotaDebitoXmlBuilder);
            $registry->register(TipoDocumento::Tiquete, new TiqueteXmlBuilder);
            // One MR builder instance handles all three MR tipos (05/06/07);
            // the tipo only affects the consecutivo, which the caller supplies.
            $registry->register(TipoDocumento::MensajeReceptorAceptado, new MensajeReceptorXmlBuilder);

            return $registry;
        });

        $this->app->singleton(HaciendaClient::class, function ($app): HaciendaClient {
            $config = $app['config']['factura-electronica'];

            return new HaciendaClient(
                $app->make(HttpFactory::class),
                new ApiCredentials(
                    (string) $config['credentials']['username'],
                    (string) $config['credentials']['password'],
                    Environment::from((string) $config['environment']),
                ),
            );
        });

        $this->app->singleton(FacturaElectronicaManager::class, function ($app): FacturaElectronicaManager {
            $config = $app['config']['factura-electronica'];

            return new FacturaElectronicaManager(
                $app->make(BuilderRegistry::class),
                new ClaveGenerator,
                new XadesEpesSigner,
                $app->make(HaciendaClient::class),
                SigningCertificate::fromPath(
                    (string) $config['certificate']['path'],
                    (string) $config['certificate']['pin'],
                ),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/factura-electronica.php' => function_exists('config_path')
                ? config_path('factura-electronica.php')
                : 'factura-electronica.php',
        ], 'factura-electronica-config');
    }
}
