<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Tests\Unit\Xml;

use DateTimeImmutable;
use DOMDocument;
use PHPUnit\Framework\TestCase;
use Stea\FacturaElectronica\Credentials\SigningCertificate;
use Stea\FacturaElectronica\Dtos\MensajeReceptorDto;
use Stea\FacturaElectronica\Signing\XadesEpesSigner;
use Stea\FacturaElectronica\Xml\MensajeReceptorXmlBuilder;
use Stea\FacturaElectronica\Xml\XsdValidator;

/**
 * Schema-valid only — uses fully synthetic data (no real cédulas, names, or claves).
 *
 * A MensajeReceptor's <Clave> is the received supplier comprobante's clave, not
 * freshly generated. We use a synthetic 50-digit clave that satisfies the XSD
 * pattern constraint.
 */
final class MensajeReceptorXmlBuilderTest extends TestCase
{
    // Synthetic received-comprobante clave (50 digits, no real party encoded).
    private const SYNTH_CLAVE = '50601012600310100000000100001050000000001100000001';

    private function dto(): MensajeReceptorDto
    {
        return new MensajeReceptorDto(
            claveComprobante: self::SYNTH_CLAVE,
            numeroCedulaEmisor: '3101999999',
            fechaEmisionDoc: new DateTimeImmutable('2026-01-01T10:00:00.000'),
            mensaje: 1,
            montoTotalImpuesto: 13.0,
            codigoActividad: '6201.0',
            condicionImpuesto: '01',
            montoTotalImpuestoAcreditar: 13.0,
            montoTotalDeGastoAplicable: 100.0,
            totalFactura: 113.0,
            numeroCedulaReceptor: '3101000000',
            numeroConsecutivoReceptor: '00100001050000000001',
        );
    }

    private function cert(): SigningCertificate
    {
        return SigningCertificate::fromPath(__DIR__.'/../../fixtures/cert/test.p12', '1234');
    }

    public function test_signed_mr_passes_xsd(): void
    {
        $builder = new MensajeReceptorXmlBuilder;
        $doc = $builder->build($this->dto(), self::SYNTH_CLAVE);
        $signed = (new XadesEpesSigner)->sign($doc, $this->cert());

        $wire = new DOMDocument;
        $wire->loadXML((string) $signed->saveXML());

        $validator = new XsdValidator;
        $passes = $validator->validate($wire, $builder->xsdPath());

        $this->assertTrue($passes, 'Signed MR must pass XSD: '.implode('; ', $validator->errors()));
    }

    public function test_mr_contains_expected_root_and_clave(): void
    {
        $builder = new MensajeReceptorXmlBuilder;
        $xml = $builder->build($this->dto(), self::SYNTH_CLAVE)->saveXML();

        $this->assertStringContainsString('<MensajeReceptor', $xml);
        $this->assertStringContainsString(self::SYNTH_CLAVE, $xml);
        $this->assertSame(50, strlen(self::SYNTH_CLAVE));
    }
}
