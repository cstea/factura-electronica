<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica;

use DOMDocument;
use Stea\FacturaElectronica\Clave\ClaveGenerator;
use Stea\FacturaElectronica\Credentials\SigningCertificate;
use Stea\FacturaElectronica\Dtos\Contracts\ComprobanteRequest;
use Stea\FacturaElectronica\Dtos\MensajeReceptorDto;
use Stea\FacturaElectronica\Dtos\Preparado;
use Stea\FacturaElectronica\Dtos\ResultadoDto;
use Stea\FacturaElectronica\Enums\EstadoComprobante;
use Stea\FacturaElectronica\Enums\TipoDocumento;
use Stea\FacturaElectronica\Exceptions\HaciendaRejectedException;
use Stea\FacturaElectronica\Exceptions\SchemaValidationException;
use Stea\FacturaElectronica\Exceptions\XmlBuildException;
use Stea\FacturaElectronica\Hacienda\EstadoConsulta;
use Stea\FacturaElectronica\Hacienda\HaciendaClient;
use Stea\FacturaElectronica\Hacienda\RespuestaEnvio;
use Stea\FacturaElectronica\Signing\XadesEpesSigner;
use Stea\FacturaElectronica\Xml\BuilderRegistry;
use Stea\FacturaElectronica\Xml\XsdValidator;

final class FacturaElectronicaManager
{
    public function __construct(
        private readonly BuilderRegistry $registry,
        private readonly ClaveGenerator $claveGenerator,
        private readonly XadesEpesSigner $signer,
        private readonly HaciendaClient $client,
        private readonly SigningCertificate $certificate,
        /**
         * Check every signed document against the bundled v4.4 XSD before it
         * is sent. Turn off only if Hacienda's published schema and its
         * recepción disagree.
         */
        private readonly bool $validateSchema = true,
    ) {}

    /**
     * Builds, validates (XSD) and signs a comprobante without sending it. Pass `$clave` when it was already assigned (it is respected). Throws {@see SchemaValidationException} with the schema errors
     * when the signed XML would not validate (unless `validate_schema` is off), so bad data never reaches Hacienda.
     *
     * @throws SchemaValidationException
     * @throws XmlBuildException
     */
    public function preparar(TipoDocumento $tipo, ComprobanteRequest $dto, ?string $codigoSeguridad = null, ?string $clave = null): Preparado
    {
        if ($clave !== null) {
            // Quien numera el comprobante ya le asignó su clave (se imprime y se referencia antes de firmar): se respeta, pero debe ser
            // coherente con el comprobante, o Hacienda lo rechazaría.
            if (preg_match('/^\d{50}$/', $clave) !== 1 || substr($clave, 21, 20) !== $dto->consecutivo()) {
                throw new XmlBuildException('The clave does not match the comprobante consecutivo.');
            }

            $codigoSeguridad = substr($clave, 42, 8);
        } else {
            $codigoSeguridad ??= str_pad((string) random_int(0, 99_999_999), 8, '0', STR_PAD_LEFT);

            $clave = $this->claveGenerator->generate(
                $dto->cedulaEmisor(),
                $dto->fechaEmision(),
                $dto->consecutivo(),
                $dto->situacion(),
                $codigoSeguridad,
            );
        }

        $builder = $this->registry->for($tipo);
        $doc = $builder->build($dto, $clave);

        $signedXml = (string) $this->signer->sign($doc, $this->certificate)->saveXML();

        // Validate what Hacienda will receive: the signed text, re-read (the schema requires the Signature node).
        $this->assertSchemaValid($clave, $signedXml, $builder->xsdPath());

        $payload = [
            'emisorTipo' => $dto->tipoIdentificacionEmisor(),
            'emisorNumero' => $dto->cedulaEmisor(),
            'fecha' => $dto->fechaEmision()->format('Y-m-d\TH:i:sP'),
        ];

        if (($r = $dto->receptorIdentificacion()) !== null) {
            $payload['receptorTipo'] = $r->tipo;
            $payload['receptorNumero'] = $r->numero;
        }

        return new Preparado($clave, $dto->consecutivo(), $codigoSeguridad, $signedXml, $payload);
    }

    /** Sends an already signed comprobante. Never throws on an HTTP answer; see {@see RespuestaEnvio}. */
    public function enviar(Preparado $preparado): RespuestaEnvio
    {
        return $this->client->enviar($preparado->clave, base64_encode($preparado->xmlFirmado), $preparado->payload);
    }

    /** The state Hacienda reports for a clave, without throwing. */
    public function consultarEstado(string $clave): EstadoConsulta
    {
        return $this->client->consultarEstado($clave);
    }

    public function emitir(TipoDocumento $tipo, ComprobanteRequest $dto): ResultadoDto
    {
        $preparado = $this->preparar($tipo, $dto);
        $this->client->send($preparado->clave, base64_encode($preparado->xmlFirmado), $preparado->payload);

        return new ResultadoDto(
            clave: $preparado->clave,
            consecutivo: $dto->consecutivo(),
            signedXml: $preparado->xmlFirmado,
            estado: EstadoComprobante::Enviado,
        );
    }

    /**
     * Emit a Mensaje Receptor (MR, tipo 05/06/07): STEA's acknowledgement of a
     * comprobante a supplier already sent us.
     *
     * Unlike {@see emitir()}, no clave is generated — the received supplier
     * comprobante's clave ({@see MensajeReceptorDto::$claveComprobante}) is used
     * directly as the <Clave> element and as the recepción clave.
     */
    public function emitirMensajeReceptor(MensajeReceptorDto $dto): ResultadoDto
    {
        $clave = $dto->claveComprobante;

        $builder = $this->registry->for(TipoDocumento::MensajeReceptorAceptado);
        $doc = $builder->build($dto, $clave);
        $signed = $this->signer->sign($doc, $this->certificate);
        $signedXml = (string) $signed->saveXML();
        $this->assertSchemaValid($clave, $signedXml, $builder->xsdPath());

        // For an MR the supplier is the emisor of the original comprobante and
        // STEA is the receptor (we are the obligado tributario submitting the
        // acceptance message). Identification type is inferred from cédula length
        // (9 digits => física '01', 10 digits => jurídica '02').
        // consecutivoReceptor is required by Hacienda for MR recepción submissions.
        $this->client->send($clave, base64_encode($signedXml), [
            'emisorTipo' => $this->tipoIdentificacion($dto->numeroCedulaEmisor),
            'emisorNumero' => $dto->numeroCedulaEmisor,
            'receptorTipo' => $this->tipoIdentificacion($dto->numeroCedulaReceptor),
            'receptorNumero' => $dto->numeroCedulaReceptor,
            'fecha' => $dto->fechaEmisionDoc->format('Y-m-d\TH:i:sP'),
            'consecutivoReceptor' => $dto->numeroConsecutivoReceptor(),
        ]);

        return new ResultadoDto(
            clave: $clave,
            consecutivo: $dto->numeroConsecutivoReceptor(),
            signedXml: $signedXml,
            estado: EstadoComprobante::Enviado,
        );
    }

    /**
     * Validate the bytes that will be sent (re-parsed, as Hacienda receives them).
     *
     * @throws SchemaValidationException
     */
    private function assertSchemaValid(string $clave, string $signedXml, string $xsdPath): void
    {
        if (! $this->validateSchema) {
            return;
        }

        $wire = new DOMDocument;
        $wire->loadXML($signedXml);

        $validator = new XsdValidator;
        if (! $validator->validate($wire, $xsdPath)) {
            throw new SchemaValidationException($clave, $validator->errors());
        }
    }

    private function tipoIdentificacion(string $cedula): string
    {
        return strlen($cedula) >= 10 ? '02' : '01';
    }

    public function consultar(string $clave): ResultadoDto
    {
        $r = $this->client->consultar($clave);

        $estado = EstadoComprobante::fromHacienda($r['estado']);

        if ($estado === EstadoComprobante::Rechazado) {
            throw new HaciendaRejectedException($clave, $r['respuestaXml']);
        }

        return new ResultadoDto($clave, '', '', $estado, $r['respuestaXml'] ?: null);
    }
}
