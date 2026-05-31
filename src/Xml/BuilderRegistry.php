<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Xml;

use Stea\FacturaElectronica\Enums\TipoDocumento;
use Stea\FacturaElectronica\Exceptions\FacturaElectronicaException;

final class BuilderRegistry
{
    /** @var array<string, DocumentBuilder> */
    private array $builders = [];

    public function register(TipoDocumento $tipo, DocumentBuilder $builder): void
    {
        $this->builders[$tipo->value] = $builder;
    }

    public function for(TipoDocumento $tipo): DocumentBuilder
    {
        return $this->builders[$tipo->value]
            ?? throw new FacturaElectronicaException("No builder registered for {$tipo->name}.");
    }
}
