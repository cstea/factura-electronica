<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Stea\FacturaElectronica\Support\Decimal;

final class DecimalTest extends TestCase
{
    public function test_redondea_la_mitad_hacia_arriba_con_la_escala_pedida(): void
    {
        $this->assertSame('20.20352', Decimal::round('20.203518'));
        $this->assertSame('20.20351', Decimal::round('20.203512'));
        $this->assertSame('0.00001', Decimal::round('0.000005'));
        $this->assertSame('-0.00001', Decimal::round('-0.000005'));
        $this->assertSame('13', Decimal::round('12.5', 0));
    }

    public function test_el_ruido_binario_de_un_float_no_llega_al_xml(): void
    {
        $this->assertSame('0.3', Decimal::money(0.1 + 0.2));
        $this->assertSame('86817.58', Decimal::money(86817.58));
    }

    public function test_money_quita_los_ceros_sobrantes(): void
    {
        $this->assertSame('0', Decimal::money('0.00000'));
        $this->assertSame('1', Decimal::money('1.00000'));
        $this->assertSame('100', Decimal::money(100));
        $this->assertSame('0', Decimal::money(null));
    }

    public function test_fixed_deja_decimales_fijos(): void
    {
        $this->assertSame('13.00', Decimal::fixed(13, 2));
        $this->assertSame('1.500', Decimal::fixed('1.5', 3));
    }

    public function test_rechaza_lo_que_no_es_un_numero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Decimal::of('12abc');
    }
}
