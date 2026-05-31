<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Tests\Unit\Hacienda;

use Illuminate\Http\Client\Factory as HttpFactory;
use PHPUnit\Framework\TestCase;
use Stea\FacturaElectronica\Credentials\ApiCredentials;
use Stea\FacturaElectronica\Enums\Environment;
use Stea\FacturaElectronica\Exceptions\HaciendaAuthException;
use Stea\FacturaElectronica\Exceptions\HaciendaSendException;
use Stea\FacturaElectronica\Hacienda\HaciendaClient;

final class HaciendaClientTest extends TestCase
{
    public function test_token_uses_password_grant_and_caches(): void
    {
        $http = new HttpFactory;
        $http->fake([
            '*protocol/openid-connect/token' => $http->response(['access_token' => 'TKN', 'expires_in' => 300], 200),
        ]);

        $client = new HaciendaClient($http, new ApiCredentials('u@stag', 'p', Environment::Sandbox));
        $this->assertSame('TKN', $client->token());
        $this->assertSame('TKN', $client->token()); // cached — no second call

        $http->assertSentCount(1);
    }

    public function test_send_posts_signed_xml_and_returns_clave(): void
    {
        $http = new HttpFactory;
        $http->fake([
            '*protocol/openid-connect/token' => $http->response(['access_token' => 'TKN', 'expires_in' => 300], 200),
            '*recepcion*' => $http->response('', 202),
        ]);
        $client = new HaciendaClient($http, new ApiCredentials('u@stag', 'p', Environment::Sandbox));

        $result = $client->send('CLAVE123', base64_encode('<signed/>'), [
            'emisorTipo' => '02', 'emisorNumero' => '3101000000',
        ]);
        $this->assertSame('CLAVE123', $result['clave']);
        $this->assertSame(202, $result['status']);
    }

    public function test_failed_token_grant_throws(): void
    {
        $http = new HttpFactory;
        $http->fake(['*protocol/openid-connect/token' => $http->response('nope', 401)]);
        $client = new HaciendaClient($http, new ApiCredentials('u@stag', 'bad', Environment::Sandbox));

        $this->expectException(HaciendaAuthException::class);
        $client->token();
    }

    public function test_consultar_returns_decoded_estado_and_respuesta_xml(): void
    {
        $http = new HttpFactory;
        $http->fake([
            '*protocol/openid-connect/token' => $http->response(['access_token' => 'TKN', 'expires_in' => 300], 200),
            '*recepcion*' => $http->response([
                'ind-estado' => 'aceptado',
                'respuesta-xml' => base64_encode('<MensajeHacienda/>'),
            ], 200),
        ]);
        $client = new HaciendaClient($http, new ApiCredentials('u@stag', 'p', Environment::Sandbox));

        $result = $client->consultar('CLAVE123');

        $this->assertSame('aceptado', $result['estado']);
        $this->assertSame('<MensajeHacienda/>', $result['respuestaXml']);
    }

    public function test_send_includes_consecutivo_receptor_padded_to_20_digits(): void
    {
        $http = new HttpFactory;
        $http->fake([
            '*protocol/openid-connect/token' => $http->response(['access_token' => 'TKN', 'expires_in' => 300], 200),
            '*recepcion*' => $http->response('', 202),
        ]);
        $client = new HaciendaClient($http, new ApiCredentials('u@stag', 'p', Environment::Sandbox));

        $client->send('CLAVE_MR', base64_encode('<signed-mr/>'), [
            'emisorTipo' => '02',
            'emisorNumero' => '3101999999',
            'receptorTipo' => '02',
            'receptorNumero' => '3101000000',
            'consecutivoReceptor' => '0010000105123',   // 13 chars — must be padded to 20
        ]);

        $http->assertSent(function ($request) {
            // Skip the token endpoint
            if (str_contains($request->url(), 'openid-connect')) {
                return false;
            }

            $body = $request->data();

            return isset($body['consecutivoReceptor'])
                && strlen($body['consecutivoReceptor']) === 20
                && $body['consecutivoReceptor'] === '00000000010000105123';
        });
    }

    public function test_send_omits_consecutivo_receptor_for_regular_fe(): void
    {
        $http = new HttpFactory;
        $http->fake([
            '*protocol/openid-connect/token' => $http->response(['access_token' => 'TKN', 'expires_in' => 300], 200),
            '*recepcion*' => $http->response('', 202),
        ]);
        $client = new HaciendaClient($http, new ApiCredentials('u@stag', 'p', Environment::Sandbox));

        $client->send('CLAVE_FE', base64_encode('<signed-fe/>'), [
            'emisorTipo' => '02',
            'emisorNumero' => '3101000000',
        ]);

        $http->assertSent(function ($request) {
            if (str_contains($request->url(), 'openid-connect')) {
                return false;
            }

            $body = $request->data();

            return ! isset($body['consecutivoReceptor']);
        });
    }

    public function test_send_non_2xx_throws_with_status_and_body(): void
    {
        $http = new HttpFactory;
        $http->fake([
            '*protocol/openid-connect/token' => $http->response(['access_token' => 'TKN', 'expires_in' => 300], 200),
            '*recepcion*' => $http->response('boom', 400),
        ]);
        $client = new HaciendaClient($http, new ApiCredentials('u@stag', 'p', Environment::Sandbox));

        try {
            $client->send('CLAVE123', base64_encode('<signed/>'), ['emisorTipo' => '02', 'emisorNumero' => '3101000000']);
            $this->fail('expected HaciendaSendException');
        } catch (HaciendaSendException $e) {
            $this->assertSame(400, $e->status);
            $this->assertStringContainsString('boom', $e->body);
        }
    }
}
