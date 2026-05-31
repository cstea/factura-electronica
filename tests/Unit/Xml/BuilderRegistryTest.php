<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Tests\Unit\Xml;

use PHPUnit\Framework\TestCase;
use Stea\FacturaElectronica\Enums\TipoDocumento;
use Stea\FacturaElectronica\Exceptions\FacturaElectronicaException;
use Stea\FacturaElectronica\Xml\BuilderRegistry;
use Stea\FacturaElectronica\Xml\DocumentBuilder;

final class BuilderRegistryTest extends TestCase
{
    public function test_resolves_registered_builder_for_tipo(): void
    {
        $builder = $this->createMock(DocumentBuilder::class);
        $registry = new BuilderRegistry;
        $registry->register(TipoDocumento::FacturaExportacion, $builder);

        $this->assertSame($builder, $registry->for(TipoDocumento::FacturaExportacion));
    }

    public function test_unregistered_tipo_throws(): void
    {
        $this->expectException(FacturaElectronicaException::class);
        (new BuilderRegistry)->for(TipoDocumento::Tiquete);
    }
}
