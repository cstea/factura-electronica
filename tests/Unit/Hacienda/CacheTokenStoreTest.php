<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Tests\Unit\Hacienda;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory as HttpFactory;
use PHPUnit\Framework\TestCase;
use Stea\FacturaElectronica\Credentials\ApiCredentials;
use Stea\FacturaElectronica\Enums\Environment;
use Stea\FacturaElectronica\Exceptions\HaciendaAuthException;
use Stea\FacturaElectronica\Hacienda\CacheTokenStore;
use Stea\FacturaElectronica\Hacienda\HaciendaClient;
use Stea\FacturaElectronica\Hacienda\RespuestaEnvio;

/**
 * One cache shared by several taxpayers (the accounting app issues for more than one emisor) must never hand one taxpayer's
 * token to another, and must not put credentials in its keys.
 */
final class CacheTokenStoreTest extends TestCase
{
    /** An IdP fake that issues a token naming the user and environment it was granted to. */
    private function idp(): HttpFactory
    {
        $http = new HttpFactory;
        $http->fake(function ($request) use ($http) {
            if (str_contains($request->url(), 'openid-connect/token')) {
                $env = str_contains($request->url(), 'rut-stag') ? 'stag' : 'prod';

                return $http->response(['access_token' => "tok:{$request['username']}:{$env}", 'expires_in' => 300]);
            }

            return $http->response('', 201);
        });

        return $http;
    }

    public function test_two_taxpayers_sharing_one_cache_each_get_their_own_token(): void
    {
        $store = new CacheTokenStore(new Repository(new ArrayStore));
        $http = $this->idp();

        $a = new HaciendaClient($http, new ApiCredentials('fisica@stag', 'pa', Environment::Sandbox), $store);
        $b = new HaciendaClient($http, new ApiCredentials('sa@stag', 'pb', Environment::Sandbox), $store);

        $this->assertSame('tok:fisica@stag:stag', $a->token());
        $this->assertSame('tok:sa@stag:stag', $b->token());
        // And again, now served from the shared cache.
        $this->assertSame('tok:fisica@stag:stag', $a->token());
        $this->assertSame('tok:sa@stag:stag', $b->token());
    }

    public function test_sandbox_and_production_tokens_of_the_same_user_are_kept_apart(): void
    {
        $store = new CacheTokenStore(new Repository(new ArrayStore));
        $http = $this->idp();

        $sandbox = new HaciendaClient($http, new ApiCredentials('same@user', 'p', Environment::Sandbox), $store);
        $production = new HaciendaClient($http, new ApiCredentials('same@user', 'p', Environment::Production), $store);

        $this->assertNotSame($sandbox->token(), $production->token());
    }

    public function test_forgetting_one_taxpayers_token_leaves_the_others(): void
    {
        $store = new CacheTokenStore(new Repository(new ArrayStore));
        $http = $this->idp();
        $a = new HaciendaClient($http, new ApiCredentials('fisica@stag', 'pa', Environment::Sandbox), $store);
        $b = new HaciendaClient($http, new ApiCredentials('sa@stag', 'pb', Environment::Sandbox), $store);
        $a->token();
        $b->token();

        $a->olvidarToken();

        $http->assertSentCount(2);
        $b->token();
        $http->assertSentCount(2); // b still cached: no new login
    }

    public function test_no_username_or_password_appears_in_a_cache_key(): void
    {
        $array = new ArrayStore;
        $store = new CacheTokenStore(new Repository($array));
        (new HaciendaClient($this->idp(), new ApiCredentials('fisica@stag', 's3cr3t', Environment::Sandbox), $store))->token();

        $keys = implode(' ', array_keys((fn () => $this->storage)->call($array)));

        $this->assertStringNotContainsString('fisica@stag', $keys);
        $this->assertStringNotContainsString('s3cr3t', $keys);
    }

    /** Another worker holding the login lock is a transient failure, never an uncaught LockTimeoutException. */
    public function test_a_busy_login_lock_is_reported_as_transient(): void
    {
        $array = new ArrayStore;
        $store = new CacheTokenStore(new Repository($array), waitSeconds: 0);
        $credentials = new ApiCredentials('fisica@stag', 'pa', Environment::Sandbox);
        $scope = hash('sha256', 'sandbox|fisica@stag');
        $array->lock("factura-electronica.token.{$scope}.lock", 60)->get(); // another worker is logging in

        $client = new HaciendaClient($this->idp(), $credentials, $store);

        $r = $client->enviar(str_repeat('5', 50), 'WA==', ['emisorTipo' => '01', 'emisorNumero' => '901070387']);
        $this->assertSame(RespuestaEnvio::TRANSITORIO, $r->clase);
        $this->assertInstanceOf(HaciendaAuthException::class, $r->excepcion);

        $this->expectException(HaciendaAuthException::class);
        $client->token();
    }
}
