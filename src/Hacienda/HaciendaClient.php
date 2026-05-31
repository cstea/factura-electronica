<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Hacienda;

use Illuminate\Http\Client\Factory as HttpFactory;
use Stea\FacturaElectronica\Credentials\ApiCredentials;
use Stea\FacturaElectronica\Exceptions\HaciendaAuthException;
use Stea\FacturaElectronica\Exceptions\HaciendaSendException;

final class HaciendaClient
{
    private TokenStore $store;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly ApiCredentials $credentials,
        ?TokenStore $store = null,
    ) {
        $this->store = $store ?? new TokenStore;
    }

    public function token(): string
    {
        if ($this->store->valid(time())) {
            return (string) $this->store->token();
        }

        $resp = $this->http->asForm()->post($this->credentials->environment->idpUrl(), [
            'grant_type' => 'password',
            'client_id' => $this->credentials->environment->clientId(),
            'username' => $this->credentials->username,
            'password' => $this->credentials->password,
        ]);

        if (! $resp->successful()) {
            throw new HaciendaAuthException("Token grant failed: HTTP {$resp->status()}");
        }

        $accessToken = (string) $resp->json('access_token');
        $expiresIn = (int) $resp->json('expires_in');
        $this->store->set($accessToken, time() + $expiresIn - 30);

        return $accessToken;
    }

    /**
     * @param  array{emisorTipo:string,emisorNumero:string,receptorTipo?:string,receptorNumero?:string,fecha?:string,consecutivoReceptor?:string}  $payload
     * @return array{clave:string,status:int}
     */
    public function send(string $clave, string $base64SignedXml, array $payload): array
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

        $resp = $this->http
            ->withToken($this->token())
            ->acceptJson()
            ->post($this->credentials->environment->recepcionUrl(), $body);

        if (! $resp->successful()) {
            throw new HaciendaSendException($resp->status(), (string) $resp->body());
        }

        return ['clave' => $clave, 'status' => $resp->status()];
    }

    /**
     * @return array{estado:string,respuestaXml:string}
     */
    public function consultar(string $clave): array
    {
        $resp = $this->http
            ->withToken($this->token())
            ->acceptJson()
            ->get($this->credentials->environment->recepcionUrl().$clave);

        return [
            'estado' => (string) $resp->json('ind-estado'),
            'respuestaXml' => base64_decode((string) $resp->json('respuesta-xml'), true) ?: '',
        ];
    }
}
