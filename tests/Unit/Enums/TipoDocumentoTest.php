<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use Stea\FacturaElectronica\Enums\TipoDocumento;

final class TipoDocumentoTest extends TestCase
{
    public function test_codigo_is_two_digit_string(): void
    {
        $this->assertSame('09', TipoDocumento::FacturaExportacion->codigo());
        $this->assertSame('01', TipoDocumento::Factura->codigo());
        $this->assertSame('08', TipoDocumento::FacturaCompra->codigo());
    }
}
