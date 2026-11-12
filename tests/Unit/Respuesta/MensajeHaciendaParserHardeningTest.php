<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Tests\Unit\Respuesta;

use PHPUnit\Framework\TestCase;
use Stea\FacturaElectronica\Respuesta\MensajeHaciendaParser;

final class MensajeHaciendaParserHardeningTest extends TestCase
{
    private const MENSAJE = '<MensajeHacienda><Clave>50601122600310100000000100001010000000001100000001</Clave><Mensaje>1</Mensaje><DetalleMensaje>ok</DetalleMensaje></MensajeHacienda>';

    public function test_reads_a_plain_mensaje_hacienda(): void
    {
        $this->assertNotNull(MensajeHaciendaParser::parse(self::MENSAJE));
    }

    /** Hacienda never sends a DTD: a document with one is refused, not expanded. */
    public function test_a_document_with_a_dtd_or_entities_is_refused(): void
    {
        $bomb = '<?xml version="1.0"?><!DOCTYPE m [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;">]>'
            .'<MensajeHacienda><Clave>&b;</Clave><Mensaje>1</Mensaje></MensajeHacienda>';
        $external = '<?xml version="1.0"?><!DOCTYPE m [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
            .'<MensajeHacienda><Clave>&x;</Clave><Mensaje>1</Mensaje></MensajeHacienda>';

        $this->assertNull(MensajeHaciendaParser::parse($bomb));
        $this->assertNull(MensajeHaciendaParser::parse($external));
    }

    public function test_an_oversized_document_is_refused(): void
    {
        $huge = str_replace('<DetalleMensaje>ok', '<DetalleMensaje>'.str_repeat('x', 1_100_000), self::MENSAJE);

        $this->assertNull(MensajeHaciendaParser::parse($huge));
    }
}
