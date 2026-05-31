<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Tests\Unit\Signing;

use DateTimeImmutable;
use DOMDocument;
use PHPUnit\Framework\TestCase;
use RobRichards\XMLSecLibs\XMLSecEnc;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use Stea\FacturaElectronica\Credentials\SigningCertificate;
use Stea\FacturaElectronica\Dtos\EmisorDto;
use Stea\FacturaElectronica\Dtos\FacturaExportacionDto;
use Stea\FacturaElectronica\Dtos\IdentificacionDto;
use Stea\FacturaElectronica\Dtos\ImpuestoDto;
use Stea\FacturaElectronica\Dtos\LineaDetalleDto;
use Stea\FacturaElectronica\Dtos\ReceptorDto;
use Stea\FacturaElectronica\Dtos\UbicacionDto;
use Stea\FacturaElectronica\Signing\XadesEpesSigner;
use Stea\FacturaElectronica\Xml\FacturaExportacionXmlBuilder;
use Stea\FacturaElectronica\Xml\XsdValidator;

final class XadesEpesSignerTest extends TestCase
{
    private function doc(): DOMDocument
    {
        $doc = new DOMDocument;
        $doc->loadXML('<FacturaElectronicaExportacion xmlns="https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/facturaElectronicaExportacion"><Clave>50601012600310100000000100001090000000001100000001</Clave></FacturaElectronicaExportacion>');

        return $doc;
    }

    private function cert(): SigningCertificate
    {
        return SigningCertificate::fromPath(__DIR__.'/../../fixtures/cert/test.p12', '1234');
    }

    public function test_signed_document_carries_signature_and_xades_policy(): void
    {
        $xml = (new XadesEpesSigner)->sign($this->doc(), $this->cert())->saveXML();

        $this->assertStringContainsString('http://uri.etsi.org/01903/v1.3.2#', $xml);
        $this->assertStringContainsString('DWxin1xWOeI8OuWQXazh4VjLWAaCLAA954em7DMh0h8=', $xml);
        $this->assertStringContainsString('SignedProperties', $xml);
        $this->assertStringContainsString('Emisor', $xml);
    }

    public function test_signature_verifies_cryptographically(): void
    {
        $signed = (new XadesEpesSigner)->sign($this->doc(), $this->cert());

        $verify = new XMLSecurityDSig;
        $sig = $verify->locateSignature($signed);
        $this->assertNotNull($sig);

        // Validate every Reference digest in its proper context. We do NOT use
        // XMLSecurityDSig::validateReference() here: that method detaches the
        // entire Signature element before processing, which makes the XAdES
        // same-document references (KeyInfo and SignedProperties, which live
        // INSIDE the Signature) unresolvable. We validate each reference the
        // way a spec-correct verifier must.
        $this->assertTrue(
            $this->allReferenceDigestsValid($signed, $sig),
            'all three reference digests must validate',
        );

        // Verify the RSA-SHA256 signature over SignedInfo against the embedded
        // X509 certificate.
        $verify->canonicalizeSignedInfo();
        $key = $verify->locateKey();
        $this->assertNotNull($key);
        XMLSecEnc::staticLocateKeyInfo($key, $sig);
        $this->assertSame(1, $verify->verify($key), 'signature must verify against embedded cert');
    }

    /**
     * Validate each ds:Reference digest against its referenced node, computing
     * canonical form in the node's real document context.
     */
    private function allReferenceDigestsValid(DOMDocument $signed, \DOMNode $sig): bool
    {
        $xpath = new \DOMXPath($signed);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

        foreach ($xpath->query('./ds:SignedInfo/ds:Reference', $sig) as $reference) {
            if (! $reference instanceof \DOMElement) {
                continue;
            }
            $uri = $reference->getAttribute('URI');
            $stored = trim((string) $xpath->evaluate('string(./ds:DigestValue)', $reference));

            if ($uri === '') {
                // Enveloped document reference: digest the document with the
                // Signature element removed.
                $clone = clone $signed;
                $cloneSig = (new XMLSecurityDSig)->locateSignature($clone);
                $cloneSig->parentNode->removeChild($cloneSig);
                $canonical = (string) $clone->C14N();
            } else {
                $fragment = ltrim($uri, '#');
                $target = $xpath->query('//*[@Id="'.$fragment.'"]')->item(0);
                if ($target === null) {
                    return false;
                }
                $canonical = (string) $target->C14N();
            }

            if (base64_encode(hash('sha256', $canonical, true)) !== $stored) {
                return false;
            }
        }

        return true;
    }

    public function test_signed_fee_passes_full_xsd(): void
    {
        $builder = new FacturaExportacionXmlBuilder;
        $clave = '50601012600310100000000100001090000000001100000001';
        $doc = $builder->build($this->dtoFromGolden(), $clave);

        $signed = (new XadesEpesSigner)->sign($doc, $this->cert());

        // Validate the serialized (wire) form. libxml's schemaValidate has a
        // known quirk validating a live, surgically-modified DOM tree whose
        // body elements were created with createElement() (no explicit
        // namespace) and inherit the default namespace; round-tripping through
        // saveXML()/loadXML() resolves the namespace inheritance the same way
        // Hacienda receives it.
        $wire = new DOMDocument;
        $wire->loadXML((string) $signed->saveXML());

        $validator = new XsdValidator;
        $passes = $validator->validate($wire, $builder->xsdPath());

        $this->assertTrue($passes, 'signed FEE must pass full XSD: '.implode('; ', $validator->errors()));
    }

    private function dtoFromGolden(): FacturaExportacionDto
    {
        return new FacturaExportacionDto(
            consecutivo: '00100001090000000001',
            fechaEmision: new DateTimeImmutable('2026-01-01T10:00:00-06:00'),
            proveedorSistemas: '2100042005',
            codigoActividadEmisor: '6201.0',
            emisor: new EmisorDto(
                nombre: 'MI EMPRESA S.A.',
                identificacion: new IdentificacionDto('02', '3101000000'),
                ubicacion: new UbicacionDto(
                    provincia: '1',
                    canton: '01',
                    distrito: '01',
                    otrasSenas: '123 Calle Principal, San Jose',
                ),
                correoElectronico: 'emisor@example.com',
            ),
            receptor: new ReceptorDto(
                nombre: 'FOREIGN CLIENT, INC',
                identificacion: new IdentificacionDto('05', '00-0000000'),
                otrasSenasExtranjero: '100 Example Street, Anytown, USA',
            ),
            lineas: [
                new LineaDetalleDto(
                    numeroLinea: 1,
                    codigoCabys: '8314100000400',
                    cantidad: 10.0,
                    unidadMedida: 'h',
                    detalle: 'Servicios de desarrollo de software',
                    precioUnitario: 50.0,
                    montoTotal: 500.0,
                    subTotal: 500.0,
                    montoTotalLinea: 500.0,
                    impuestos: [
                        new ImpuestoDto(codigo: '01', codigoTarifa: '10', tarifa: 0.0, monto: 0.0),
                    ],
                ),
            ],
            condicionVenta: '01',
            medioPago: '04',
            moneda: 'USD',
            tipoCambio: 500.0,
        );
    }
}
