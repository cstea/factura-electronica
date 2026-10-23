<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Hacienda;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Stea\FacturaElectronica\Credentials\ApiCredentials;
use Stea\FacturaElectronica\Enums\EstadoComprobante;
use Stea\FacturaElectronica\Exceptions\HaciendaAuthException;
use Stea\FacturaElectronica\Exceptions\HaciendaSendException;
use Stea\FacturaElectronica\Respuesta\MensajeHaciendaParser;

final class HaciendaClient
{
    private TokenStore $store;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly ApiCredentials $credentials,
        ?TokenStore $store = null,
        private readonly int $timeout = 30,
        private readonly int $connectTimeout = 10,
    ) {
        $this->store = $store ?? new MemoryTokenStore;
    }

    /**
     * A valid access token, logging in to the IdP only when none is stored. With a shared store (cache) and its lock, a fleet of workers
     * logs in once per ~5 minutes instead of once each.
     *
     * @phpstan-impure
     */
    public function token(): string
    {
        $cached = $this->store->get(time());

        if ($cached !== null) {
            return $cached;
        }

        return $this->store->exclusive(
            fn (): ?string => $this->store->get(time()),
            fn (): string => $this->login(),
        );
    }

    /** Drops the stored token (Hacienda answered 401: it expired early or was revoked). */
    public function olvidarToken(): void
    {
        $this->store->forget();
    }

    private function login(): string
    {
        try {
            $resp = $this->peticion()->asForm()->post($this->credentials->environment->idpUrl(), [
                'grant_type' => 'password',
                'client_id' => $this->credentials->environment->clientId(),
                'username' => $this->credentials->username,
                'password' => $this->credentials->password,
            ]);
        } catch (ConnectionException $e) {
            throw new HaciendaAuthException('Token grant failed: '.$e->getMessage());
        }

        if (! $resp->successful()) {
            throw new HaciendaAuthException("Token grant failed: HTTP {$resp->status()}");
        }

        $accessToken = (string) $resp->json('access_token');
        $expiresIn = (int) $resp->json('expires_in');

        if ($accessToken === '') {
            throw new HaciendaAuthException('Token grant returned no access_token.');
        }

        // Renew 30 seconds early so a token never expires between the check and the request.
        $this->store->put($accessToken, time() + max(30, $expiresIn - 30));

        return $accessToken;
    }

    /**
     * POST /recepcion. Never throws on an HTTP answer: the result says whether to wait, fix the data or retry later. Used by the queue jobs.
     *
     * @param  array{emisorTipo:string,emisorNumero:string,receptorTipo?:string,receptorNumero?:string,fecha?:string,consecutivoReceptor?:string}  $payload
     */
    public function enviar(string $clave, string $base64SignedXml, array $payload): RespuestaEnvio
    {
        $body = [
            'clave' => $clave,
            'fecha' => $payload['fecha'] ?? date('c'),
            'emisor' => [
                'tipoIdentificacion' => $payload['emisorTipo'],
                'numeroIdentificacion' => $payload['emisorNumero'],
            ],
            'comprobanteXml' => $base64SignedXml,
        ];

        if (isset($payload['receptorTipo'], $payload['receptorNumero'])) {
            $body['receptor'] = [
                'tipoIdentificacion' => $payload['receptorTipo'],
                'numeroIdentificacion' => $payload['receptorNumero'],
            ];
        }

        if (isset($payload['consecutivoReceptor'])) {
            $body['consecutivoReceptor'] = str_pad((string) $payload['consecutivoReceptor'], 20, '0', STR_PAD_LEFT);
        }

        try {
            $resp = $this->conToken(fn (PendingRequest $r): Response => $r->post($this->credentials->environment->recepcionUrl(), $body));
        } catch (ConnectionException|HaciendaAuthException $e) {
            return new RespuestaEnvio(RespuestaEnvio::TRANSITORIO, 0, $e->getMessage());
        }

        $causa = $resp->header('X-Error-Cause');
        $causa = $causa === '' ? null : $causa;
        $cuerpo = (string) $resp->body();

        if ($resp->successful()) {
            return new RespuestaEnvio(RespuestaEnvio::RECIBIDO, $resp->status(), null, $cuerpo);
        }

        if ($resp->status() === 400 && $causa !== null && preg_match('/ya fue recibido/iu', $causa) === 1) {
            return new RespuestaEnvio(RespuestaEnvio::YA_RECIBIDO, 400, $causa, $cuerpo);
        }

        if ($resp->status() === 400) {
            return new RespuestaEnvio(RespuestaEnvio::RECHAZO, 400, $causa ?? $cuerpo, $cuerpo);
        }

        return new RespuestaEnvio(RespuestaEnvio::TRANSITORIO, $resp->status(), $causa ?? substr($cuerpo, 0, 300), $cuerpo);
    }

    /**
     * POST /recepcion, throwing when Hacienda does not take the comprobante (kept for scripts that do not use the queue).
     *
     * @param  array{emisorTipo:string,emisorNumero:string,receptorTipo?:string,receptorNumero?:string,fecha?:string,consecutivoReceptor?:string}  $payload
     * @return array{clave:string,status:int}
     */
    public function send(string $clave, string $base64SignedXml, array $payload): array
    {
        $r = $this->enviar($clave, $base64SignedXml, $payload);

        if (! $r->fueRecibido()) {
            throw new HaciendaSendException($r->status, $r->cuerpo !== '' ? $r->cuerpo : (string) $r->causa);
        }

        return ['clave' => $clave, 'status' => $r->status];
    }

    /** GET /recepcion/{clave} without throwing: 404 = Hacienda does not have it yet; 5xx/timeouts are marked transient. */
    public function consultarEstado(string $clave): EstadoConsulta
    {
        try {
            $resp = $this->conToken(fn (PendingRequest $r): Response => $r->get($this->credentials->environment->recepcionUrl().$clave));
        } catch (ConnectionException|HaciendaAuthException) {
            return new EstadoConsulta(0, null, transitorio: true);
        }

        if ($resp->status() === 404) {
            return new EstadoConsulta(404, null);
        }

        if (! $resp->successful()) {
            return new EstadoConsulta($resp->status(), null, transitorio: true);
        }

        $estado = $this->texto($resp, ['ind-estado', 'indEstado']);
        $xml = base64_decode($this->texto($resp, ['respuesta-xml', 'respuestaXml']), true);
        $xml = $xml === false || $xml === '' ? null : $xml;

        return new EstadoConsulta(
            $resp->status(),
            EstadoComprobante::fromHacienda($estado),
            $xml,
            $xml === null ? null : MensajeHaciendaParser::parse($xml),
        );
    }

    /**
     * @return array{estado:string,respuestaXml:string}
     */
    public function consultar(string $clave): array
    {
        $resp = $this->conToken(fn (PendingRequest $r): Response => $r->get($this->credentials->environment->recepcionUrl().$clave));

        return [
            'estado' => $this->texto($resp, ['ind-estado', 'indEstado']),
            'respuestaXml' => base64_decode($this->texto($resp, ['respuesta-xml', 'respuestaXml']), true) ?: '',
        ];
    }

    /**
     * Runs a request with the bearer token; on 401 the token is dropped and the request is tried once more with a fresh one.
     *
     * @param  callable(PendingRequest): Response  $enviar
     */
    private function conToken(callable $enviar): Response
    {
        $resp = $enviar($this->peticion()->withToken($this->token())->acceptJson());

        if ($resp->status() === 401) {
            $this->olvidarToken();
            $resp = $enviar($this->peticion()->withToken($this->token())->acceptJson());
        }

        return $resp;
    }

    private function peticion(): PendingRequest
    {
        return $this->http->timeout($this->timeout)->connectTimeout($this->connectTimeout);
    }

    /** @param list<string> $claves */
    private function texto(Response $resp, array $claves): string
    {
        foreach ($claves as $k) {
            $v = $resp->json($k);

            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        return '';
    }
}
