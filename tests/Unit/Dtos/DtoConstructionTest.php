<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Tests\Unit\Dtos;

use PHPUnit\Framework\TestCase;
use Stea\FacturaElectronica\Dtos\EmisorDto;
use Stea\FacturaElectronica\Dtos\IdentificacionDto;
use Stea\FacturaElectronica\Dtos\UbicacionDto;

final class DtoConstructionTest extends TestCase
{
    public function test_emisor_dto_holds_values(): void
    {
        $emisor = new EmisorDto(
            nombre: 'MI EMPRESA S.A.',
            identificacion: new IdentificacionDto('02', '3101000000'),
            ubicacion: new UbicacionDto('1', '01', '01', 'San Jose', '123 Calle Principal'),
            correoElectronico: 'emisor@example.com',
        );

        $this->assertSame('3101000000', $emisor->identificacion->numero);
        $this->assertSame('1', $emisor->ubicacion->provincia);
    }
}
