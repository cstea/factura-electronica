<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Xml;

use DOMDocument;
use Stea\FacturaElectronica\Dtos\MensajeReceptorDto;
use Stea\FacturaElectronica\Exceptions\XmlBuildException;
use Stea\FacturaElectronica\Xml\Concerns\BuildsDomElements;

/**
 * Builds the (unsigned) body of a Mensaje Receptor (MR, tipo 05/06/07).
 *
 * An MR is a "comprobante de recibido": it acknowledges a comprobante a supplier
 * already sent us. The {@see $clave} passed to {@see build()} is NOT a freshly
 * generated clave — it is the RECEIVED supplier comprobante's clave, emitted as
 * the <Clave> element. A single instance handles all three MR tipos; the tipo
 * only affects the consecutivo, which the caller supplies on the DTO.
 */
final class MensajeReceptorXmlBuilder implements DocumentBuilder
{
    use BuildsDomElements;

    private const NS = 'https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/mensajeReceptor';

    public function build(object $dto, string $clave): DOMDocument
    {
        if (! $dto instanceof MensajeReceptorDto) {
            throw new XmlBuildException('MensajeReceptorXmlBuilder requires a MensajeReceptorDto.');
        }

        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->formatOutput = false;

        $root = $doc->createElementNS(self::NS, 'MensajeReceptor');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsd', 'http://www.w3.org/2001/XMLSchema');
        $root->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'xsi:schemaLocation', self::NS.' '.self::NS.'.xsd');
        $doc->appendChild($root);

        $this->el($doc, $root, 'Clave', $clave);
        $this->el($doc, $root, 'NumeroCedulaEmisor', $dto->numeroCedulaEmisor);
        $this->el($doc, $root, 'FechaEmisionDoc', $dto->fechaEmisionDoc->format('Y-m-d\TH:i:s.v'));
        $this->el($doc, $root, 'Mensaje', (string) $dto->mensaje);

        if ($dto->detalleMensaje !== null) {
            $this->el($doc, $root, 'DetalleMensaje', $dto->detalleMensaje);
        }

        $this->el($doc, $root, 'MontoTotalImpuesto', $this->money($dto->montoTotalImpuesto));
        $this->el($doc, $root, 'CodigoActividad', $dto->codigoActividad);
        $this->el($doc, $root, 'CondicionImpuesto', $dto->condicionImpuesto);
        $this->el($doc, $root, 'MontoTotalImpuestoAcreditar', $this->money($dto->montoTotalImpuestoAcreditar));
        $this->el($doc, $root, 'MontoTotalDeGastoAplicable', $this->money($dto->montoTotalDeGastoAplicable));
        $this->el($doc, $root, 'TotalFactura', $this->money($dto->totalFactura));
        $this->el($doc, $root, 'NumeroCedulaReceptor', $dto->numeroCedulaReceptor);
        $this->el($doc, $root, 'NumeroConsecutivoReceptor', $dto->numeroConsecutivoReceptor);

        return $doc;
    }

    public function xsdPath(): string
    {
        return dirname(__DIR__, 2).'/resources/xsd/v4.4/mensajeReceptor.xsd';
    }
}
