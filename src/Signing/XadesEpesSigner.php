<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Signing;

use DOMDocument;
use DOMElement;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use Stea\FacturaElectronica\Credentials\SigningCertificate;
use Stea\FacturaElectronica\Exceptions\SigningException;

/**
 * Clean-room XAdES-EPES signer for Costa Rica Hacienda comprobantes.
 *
 * Builds the xades:QualifyingProperties subtree by hand and relies on stock
 * robrichards/xmlseclibs only for SignedInfo, digests, canonicalization, and
 * the RSA-SHA256 signature value.
 */
final class XadesEpesSigner
{
    private const XMLDSIG_NS = 'http://www.w3.org/2000/09/xmldsig#';

    private const SHA256_ALGO = 'http://www.w3.org/2001/04/xmlenc#sha256';

    private const ENVELOPED_TRANSFORM = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';

    public function sign(DOMDocument $doc, SigningCertificate $cert): DOMDocument
    {
        $parsed = [];
        if (! openssl_pkcs12_read($cert->contents(), $parsed, $cert->pin())) {
            throw new SigningException('Unable to read PKCS#12 certificate: '.openssl_error_string());
        }

        /** @var string $privatePem */
        $privatePem = $parsed['pkey'];
        /** @var string $certPem */
        $certPem = $parsed['cert'];

        $signatureId = 'xmldsig-'.self::uuid();
        $signedPropertiesId = $signatureId.'-signedprops';
        $keyInfoId = $signatureId.'-keyinfo';
        $reference0Id = $signatureId.'-ref0';
        $xadesObjectId = $signatureId.'-object';
        $qualifyingPropertiesId = $signatureId.'-qualifyingprops';

        $objDSig = new XMLSecurityDSig('ds');
        $objDSig->setCanonicalMethod(XMLSecurityDSig::C14N);

        /** @var DOMElement $sigNode */
        $sigNode = $objDSig->sigNode;
        $sigNode->setAttribute('Id', $signatureId);

        // Place the (still empty) signature into the FEE document up front so
        // every reference digest is computed in its final namespace context.
        // Without this, importing the subtree afterwards changes its canonical
        // form and the KeyInfo/SignedProperties digests no longer validate.
        /** @var DOMElement $sigNode */
        $sigNode = $objDSig->appendSignature($doc->documentElement);
        $objDSig->sigNode = $sigNode;
        $this->resetInternalXPath($objDSig);
        $sigDoc = $doc;

        // Build KeyInfo and the xades Object subtree inside the FEE document.
        $keyInfoNode = $this->buildKeyInfo($sigDoc, $sigNode, $keyInfoId, $certPem);
        $signedPropertiesNode = $this->buildXadesObject(
            $sigDoc,
            $sigNode,
            $xadesObjectId,
            $qualifyingPropertiesId,
            $signatureId,
            $signedPropertiesId,
            $reference0Id,
            $certPem,
        );

        // Reference 0: the enveloping document. The enveloped-signature
        // transform means the verifier digests the document WITHOUT the
        // Signature element, so we must do the same when signing: detach the
        // Signature from the document, digest the bare document, then re-attach.
        // (xmlseclibs treats the enveloped-signature transform as a no-op and
        // simply C14Ns whatever node we pass, so the detach is required.)
        $parent = $sigNode->parentNode;
        $parent->removeChild($sigNode);
        $objDSig->addReference(
            $doc,
            XMLSecurityDSig::SHA256,
            [self::ENVELOPED_TRANSFORM],
            ['force_uri' => true],
        );
        $parent->appendChild($sigNode);
        // Stamp the stable reference0Id referenced by xades:DataObjectFormat.
        $this->setReferenceId($sigNode, 0, $reference0Id);

        // Reference 1: the KeyInfo node (Id="...-keyinfo").
        $objDSig->addReference(
            $keyInfoNode,
            XMLSecurityDSig::SHA256,
            null,
            ['force_uri' => false, 'id_name' => 'Id', 'overwrite' => false],
        );

        // Reference 2: the SignedProperties node, with the ETSI Type attribute.
        $objDSig->addReference(
            $signedPropertiesNode,
            XMLSecurityDSig::SHA256,
            null,
            ['force_uri' => false, 'id_name' => 'Id', 'overwrite' => false],
        );
        $this->setSignedPropertiesReferenceType($sigNode, $signedPropertiesId);

        $objKey = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $objKey->loadKey($privatePem, false);

        // Signature is already in place; sign() computes SignatureValue over
        // SignedInfo and inserts it after SignedInfo.
        $objDSig->sign($objKey);

        return $doc;
    }

    /**
     * KeyInfo: X509Data/X509Certificate + KeyValue/RSAKeyValue (Modulus + Exponent).
     */
    private function buildKeyInfo(DOMDocument $sigDoc, DOMElement $sigNode, string $keyInfoId, string $certPem): DOMElement
    {
        $keyInfo = $this->ds($sigDoc, 'KeyInfo');
        $keyInfo->setAttribute('Id', $keyInfoId);
        $sigNode->appendChild($keyInfo);

        $x509Data = $this->ds($sigDoc, 'X509Data');
        $keyInfo->appendChild($x509Data);
        $x509Data->appendChild($this->ds($sigDoc, 'X509Certificate', $this->derBase64($certPem)));

        $publicKey = openssl_pkey_get_public($certPem);
        if ($publicKey === false) {
            throw new SigningException('Could not read public key from certificate.');
        }
        $details = openssl_pkey_get_details($publicKey);
        if ($details === false || ! isset($details['rsa'])) {
            throw new SigningException('Certificate does not expose an RSA public key.');
        }

        $keyValue = $this->ds($sigDoc, 'KeyValue');
        $keyInfo->appendChild($keyValue);
        $rsaKeyValue = $this->ds($sigDoc, 'RSAKeyValue');
        $keyValue->appendChild($rsaKeyValue);
        $rsaKeyValue->appendChild($this->ds($sigDoc, 'Modulus', base64_encode($details['rsa']['n'])));
        $rsaKeyValue->appendChild($this->ds($sigDoc, 'Exponent', base64_encode($details['rsa']['e'])));

        return $keyInfo;
    }

    /**
     * Build ds:Object > xades:QualifyingProperties and return the SignedProperties node.
     */
    private function buildXadesObject(
        DOMDocument $sigDoc,
        DOMElement $sigNode,
        string $xadesObjectId,
        string $qualifyingPropertiesId,
        string $signatureId,
        string $signedPropertiesId,
        string $reference0Id,
        string $certPem,
    ): DOMElement {
        $xades = SignaturePolicy::XADES_NS;

        $object = $this->ds($sigDoc, 'Object');
        $object->setAttribute('Id', $xadesObjectId);
        $sigNode->appendChild($object);

        $qualifyingProperties = $sigDoc->createElementNS($xades, 'xades:QualifyingProperties');
        $qualifyingProperties->setAttribute('Id', $qualifyingPropertiesId);
        $qualifyingProperties->setAttribute('Target', '#'.$signatureId);
        $object->appendChild($qualifyingProperties);

        $signedProperties = $sigDoc->createElementNS($xades, 'xades:SignedProperties');
        $signedProperties->setAttribute('Id', $signedPropertiesId);
        $qualifyingProperties->appendChild($signedProperties);

        $signedSignatureProperties = $sigDoc->createElementNS($xades, 'xades:SignedSignatureProperties');
        $signedProperties->appendChild($signedSignatureProperties);

        // SigningTime (per-doc). Derived in Costa Rica's timezone so the offset
        // is correct regardless of the host's local timezone (e.g. UTC on Lambda).
        $signedSignatureProperties->appendChild(
            $sigDoc->createElementNS(
                $xades,
                'xades:SigningTime',
                (new \DateTimeImmutable('now', new \DateTimeZone('America/Costa_Rica')))->format('Y-m-d\TH:i:sP'),
            ),
        );

        // SigningCertificate.
        $signingCertificate = $sigDoc->createElementNS($xades, 'xades:SigningCertificate');
        $signedSignatureProperties->appendChild($signingCertificate);
        $certEl = $sigDoc->createElementNS($xades, 'xades:Cert');
        $signingCertificate->appendChild($certEl);

        $certDigest = $sigDoc->createElementNS($xades, 'xades:CertDigest');
        $certEl->appendChild($certDigest);
        $certDigest->appendChild($this->dsDigestMethod($sigDoc));
        $fingerprint = openssl_x509_fingerprint($certPem, 'sha256', true);
        if ($fingerprint === false) {
            throw new SigningException('Could not compute certificate SHA-256 fingerprint.');
        }
        $certDigest->appendChild($this->ds($sigDoc, 'DigestValue', base64_encode($fingerprint)));

        $issuerSerial = $sigDoc->createElementNS($xades, 'xades:IssuerSerial');
        $certEl->appendChild($issuerSerial);
        [$issuerName, $serialDecimal] = $this->issuerSerial($certPem);
        $issuerSerial->appendChild($this->ds($sigDoc, 'X509IssuerName', $issuerName));
        $issuerSerial->appendChild($this->ds($sigDoc, 'X509SerialNumber', $serialDecimal));

        // SignaturePolicyIdentifier.
        $policyIdentifier = $sigDoc->createElementNS($xades, 'xades:SignaturePolicyIdentifier');
        $signedSignatureProperties->appendChild($policyIdentifier);
        $policyId = $sigDoc->createElementNS($xades, 'xades:SignaturePolicyId');
        $policyIdentifier->appendChild($policyId);

        $sigPolicyId = $sigDoc->createElementNS($xades, 'xades:SigPolicyId');
        $policyId->appendChild($sigPolicyId);
        $sigPolicyId->appendChild($sigDoc->createElementNS($xades, 'xades:Identifier', SignaturePolicy::POLICY_URL));
        $sigPolicyId->appendChild($sigDoc->createElementNS($xades, 'xades:Description'));

        $sigPolicyHash = $sigDoc->createElementNS($xades, 'xades:SigPolicyHash');
        $policyId->appendChild($sigPolicyHash);
        $sigPolicyHash->appendChild($this->dsDigestMethod($sigDoc));
        $sigPolicyHash->appendChild($this->ds($sigDoc, 'DigestValue', SignaturePolicy::POLICY_DIGEST_SHA256_B64));

        // SignerRole.
        $signerRole = $sigDoc->createElementNS($xades, 'xades:SignerRole');
        $signedSignatureProperties->appendChild($signerRole);
        $claimedRoles = $sigDoc->createElementNS($xades, 'xades:ClaimedRoles');
        $signerRole->appendChild($claimedRoles);
        $claimedRoles->appendChild($sigDoc->createElementNS($xades, 'xades:ClaimedRole', 'Emisor'));

        // SignedDataObjectProperties.
        $signedDataObjectProperties = $sigDoc->createElementNS($xades, 'xades:SignedDataObjectProperties');
        $signedProperties->appendChild($signedDataObjectProperties);
        $dataObjectFormat = $sigDoc->createElementNS($xades, 'xades:DataObjectFormat');
        $dataObjectFormat->setAttribute('ObjectReference', '#'.$reference0Id);
        $signedDataObjectProperties->appendChild($dataObjectFormat);
        $dataObjectFormat->appendChild($sigDoc->createElementNS($xades, 'xades:MimeType', 'text/xml'));
        $dataObjectFormat->appendChild($sigDoc->createElementNS($xades, 'xades:Encoding', 'UTF-8'));

        return $signedProperties;
    }

    /**
     * Set the Id on the Nth ds:Reference inside SignedInfo so xades
     * DataObjectFormat/@ObjectReference can target it.
     */
    private function setReferenceId(DOMElement $sigNode, int $index, string $id): void
    {
        $references = $this->references($sigNode);
        $ref = $references->item($index);
        if (! $ref instanceof DOMElement) {
            throw new SigningException("Could not locate ds:Reference at index {$index} in SignedInfo; xmlseclibs reference ordering may have changed.");
        }
        $ref->setAttribute('Id', $id);
    }

    /**
     * Add the ETSI Type attribute to the ds:Reference whose URI targets SignedProperties.
     */
    private function setSignedPropertiesReferenceType(DOMElement $sigNode, string $signedPropertiesId): void
    {
        $references = $this->references($sigNode);
        foreach ($references as $ref) {
            if ($ref instanceof DOMElement && $ref->getAttribute('URI') === '#'.$signedPropertiesId) {
                $ref->setAttribute('Type', SignaturePolicy::SIGNED_PROPERTIES_TYPE);

                return;
            }
        }
        throw new SigningException("Could not locate ds:Reference with URI='#{$signedPropertiesId}' for SignedProperties Type attribute; xmlseclibs reference ordering may have changed.");
    }

    /**
     * Force xmlseclibs to rebuild its cached DOMXPath after we re-point sigNode
     * into the FEE document. The cached context still targets the throwaway
     * signature document and would raise "Node from wrong document".
     */
    private function resetInternalXPath(XMLSecurityDSig $objDSig): void
    {
        // Accesses the private $xPathCtx property via reflection to clear the cached
        // DOMXPath context. If a future version of robrichards/xmlseclibs removes this
        // property, a ReflectionException will surface here with a clear stack trace.
        (new \ReflectionProperty(XMLSecurityDSig::class, 'xPathCtx'))->setValue($objDSig, null);
    }

    private function references(DOMElement $sigNode): \DOMNodeList
    {
        $xpath = new \DOMXPath($sigNode->ownerDocument);
        $xpath->registerNamespace('ds', self::XMLDSIG_NS);

        return $xpath->query('./ds:SignedInfo/ds:Reference', $sigNode);
    }

    private function ds(DOMDocument $sigDoc, string $name, ?string $value = null): DOMElement
    {
        if ($value === null) {
            return $sigDoc->createElementNS(self::XMLDSIG_NS, 'ds:'.$name);
        }

        return $sigDoc->createElementNS(self::XMLDSIG_NS, 'ds:'.$name, $value);
    }

    private function dsDigestMethod(DOMDocument $sigDoc): DOMElement
    {
        $digestMethod = $this->ds($sigDoc, 'DigestMethod');
        $digestMethod->setAttribute('Algorithm', self::SHA256_ALGO);

        return $digestMethod;
    }

    /**
     * Strip the PEM armor and re-encode the DER body as a single base64 string.
     */
    private function derBase64(string $certPem): string
    {
        $body = preg_replace('/-----(BEGIN|END) CERTIFICATE-----/', '', $certPem) ?? '';

        return preg_replace('/\s+/', '', $body) ?? '';
    }

    /**
     * @return array{0: string, 1: string} issuer DN (reversed, ", " joined) and serial in decimal
     */
    private function issuerSerial(string $certPem): array
    {
        $parsed = openssl_x509_parse($certPem);
        if ($parsed === false) {
            throw new SigningException('Unable to parse X509 certificate.');
        }

        /** @var array<string, string|array<int, string>> $issuer */
        $issuer = $parsed['issuer'] ?? [];
        $parts = [];
        foreach ($issuer as $key => $value) {
            $values = is_array($value) ? $value : [$value];
            foreach ($values as $single) {
                $parts[] = $key.'='.$single;
            }
        }
        $issuerName = implode(', ', array_reverse($parts));

        $serial = $parsed['serialNumber'] ?? '0';
        if (isset($parsed['serialNumberHex']) && is_string($parsed['serialNumberHex'])) {
            $serial = $this->hexToDecimal($parsed['serialNumberHex']);
        }

        return [$issuerName, (string) $serial];
    }

    private function hexToDecimal(string $hex): string
    {
        $hex = ltrim($hex, '0') ?: '0';
        $decimal = '0';
        foreach (str_split($hex) as $char) {
            $decimal = bcadd(bcmul($decimal, '16'), (string) hexdec($char));
        }

        return $decimal;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
