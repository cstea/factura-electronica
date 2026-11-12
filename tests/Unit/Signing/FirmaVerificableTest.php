<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Tests\Unit\Signing;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use Stea\FacturaElectronica\Credentials\SigningCertificate;
use Stea\FacturaElectronica\Dtos\EmisorDto;
use Stea\FacturaElectronica\Dtos\IdentificacionDto;
use Stea\FacturaElectronica\Dtos\ImpuestoDto;
use Stea\FacturaElectronica\Dtos\LineaDetalleDto;
use Stea\FacturaElectronica\Dtos\ReceptorDto;
use Stea\FacturaElectronica\Dtos\TiqueteDto;
use Stea\FacturaElectronica\Dtos\UbicacionDto;
use Stea\FacturaElectronica\Signing\XadesEpesSigner;
use Stea\FacturaElectronica\Xml\TiqueteXmlBuilder;

/**
 * Verifies the signature the way a receiver does, without xmlseclibs: the XML is serialized and read back, then each ds:Reference digest
 * is recomputed and the SignatureValue is checked with the certificate's public key. A signature that only verifies in memory would be
 * rejected by Hacienda.
 */
final class FirmaVerificableTest extends TestCase
{
    public function test_los_digests_y_el_valor_de_la_firma_se_verifican_sobre_el_xml_serializado(): void
    {
        $dto = new TiqueteDto(
            '00100001040000000001',
            new DateTimeImmutable('2026-01-01T10:00:00-06:00'),
            '3101000000',
            '452001',
            new EmisorDto('EMISOR DE PRUEBA S.A.', new IdentificacionDto('02', '3101000000'), new UbicacionDto('1', '01', '01', null, 'Frente al parque'), 'e@example.com'),
            new ReceptorDto('Cliente de contado'),
            [new LineaDetalleDto(1, '4311502000000', '1', 'Unid', 'Filtro', '100', '100', '100', '113', [new ImpuestoDto('01', '08', '13', '13')])],
            medioPago: '01',
        );

        $firmado = (new XadesEpesSigner)->sign(
            (new TiqueteXmlBuilder)->build($dto, '50601012600310100000000100001040000000001100000001'),
            SigningCertificate::fromPath(__DIR__.'/../../fixtures/cert/test.p12', '1234'),
        );
        $xml = (string) $firmado->saveXML();

        $doc = new DOMDocument;
        $doc->preserveWhiteSpace = true;
        $doc->loadXML($xml);
        $x = $this->xpath($doc);

        $firma = $x->query('//ds:Signature')->item(0);
        $this->assertNotNull($firma);
        $referencias = $x->query('ds:SignedInfo/ds:Reference', $firma);
        $this->assertSame(3, $referencias->length, 'documento, KeyInfo y SignedProperties');

        foreach ($referencias as $ref) {
            $this->assertInstanceOf(DOMElement::class, $ref);
            $uri = $ref->getAttribute('URI');
            $esperado = $x->query('ds:DigestValue', $ref)->item(0)->textContent;

            if ($uri === '') {
                $sinFirma = new DOMDocument;
                $sinFirma->preserveWhiteSpace = true;
                $sinFirma->loadXML($xml);
                $nodo = $this->xpath($sinFirma)->query('//ds:Signature')->item(0);
                $nodo->parentNode->removeChild($nodo);
                $bytes = $sinFirma->C14N();
            } else {
                $nodo = $x->query('//*[@Id="'.substr($uri, 1).'"]')->item(0);
                $this->assertNotNull($nodo, "No existe el nodo {$uri}");
                $bytes = $nodo->C14N();
            }

            $this->assertSame($esperado, base64_encode(hash('sha256', $bytes, true)), "Digest de la referencia '{$uri}'");
        }

        $signedInfo = $x->query('ds:SignedInfo', $firma)->item(0);
        $valor = base64_decode($x->query('ds:SignatureValue', $firma)->item(0)->textContent);
        $certificado = "-----BEGIN CERTIFICATE-----\n".chunk_split(preg_replace('/\s+/', '', $x->query('.//ds:X509Certificate', $firma)->item(0)->textContent), 64)."-----END CERTIFICATE-----\n";

        $this->assertSame(1, openssl_verify($signedInfo->C14N(), $valor, $certificado, OPENSSL_ALGO_SHA256), 'SignatureValue');
    }

    private function xpath(DOMDocument $doc): DOMXPath
    {
        $x = new DOMXPath($doc);
        $x->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

        return $x;
    }
}
