<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Tests\Unit\Hacienda;

use Illuminate\Http\Client\Factory as HttpFactory;
use PHPUnit\Framework\TestCase;
use Stea\FacturaElectronica\Credentials\ApiCredentials;
use Stea\FacturaElectronica\Enums\Environment;
use Stea\FacturaElectronica\Enums\EstadoComprobante;
use Stea\FacturaElectronica\Hacienda\HaciendaClient;
use Stea\FacturaElectronica\Hacienda\MemoryTokenStore;
use Stea\FacturaElectronica\Hacienda\RespuestaEnvio;
use Stea\FacturaElectronica\Respuesta\MensajeHaciendaParser;

final class HaciendaClientEnvioTest extends TestCase
{
    private const PAYLOAD = ['emisorTipo' => '02', 'emisorNumero' => '3101000000'];

    private function cliente(HttpFactory $http): HaciendaClient
    {
        return new HaciendaClient($http, new ApiCredentials('u@stag', 'p', Environment::Sandbox), new MemoryTokenStore);
    }

    private function token(HttpFactory $http): mixed
    {
        return $http->response(['access_token' => 'TKN', 'expires_in' => 300]);
    }

    public function test_un_201_es_recibido(): void
    {
        $http = new HttpFactory;
        $http->fake(['*openid-connect/token' => $this->token($http), '*recepcion*' => $http->response('', 201)]);

        $r = $this->cliente($http)->enviar('C', 'WA==', self::PAYLOAD);

        $this->assertSame(RespuestaEnvio::RECIBIDO, $r->clase);
        $this->assertTrue($r->fueRecibido());
    }

    public function test_el_400_ya_fue_recibido_se_trata_como_exito(): void
    {
        $http = new HttpFactory;
        $http->fake([
            '*openid-connect/token' => $this->token($http),
            '*recepcion*' => $http->response('', 400, ['X-Error-Cause' => 'El comprobante con la Clave [506] ya fue recibido.']),
        ]);

        $r = $this->cliente($http)->enviar('C', 'WA==', self::PAYLOAD);

        $this->assertSame(RespuestaEnvio::YA_RECIBIDO, $r->clase);
        $this->assertTrue($r->fueRecibido());
    }

    public function test_otro_400_es_un_rechazo_con_su_causa(): void
    {
        $http = new HttpFactory;
        $http->fake([
            '*openid-connect/token' => $this->token($http),
            '*recepcion*' => $http->response('', 400, ['X-Error-Cause' => 'cvc-complex-type: falta el nodo X']),
        ]);

        $r = $this->cliente($http)->enviar('C', 'WA==', self::PAYLOAD);

        $this->assertSame(RespuestaEnvio::RECHAZO, $r->clase);
        $this->assertStringContainsString('cvc-complex-type', (string) $r->causa);
        $this->assertFalse($r->fueRecibido());
    }

    public function test_un_503_y_un_429_son_transitorios(): void
    {
        foreach ([503, 429, 500] as $status) {
            $http = new HttpFactory;
            $http->fake(['*openid-connect/token' => $this->token($http), '*recepcion*' => $http->response('', $status)]);

            $r = $this->cliente($http)->enviar('C', 'WA==', self::PAYLOAD);

            $this->assertTrue($r->esTransitorio(), "HTTP {$status}");
        }
    }

    public function test_si_el_idp_no_responde_el_envio_es_transitorio(): void
    {
        $http = new HttpFactory;
        $http->fake(['*openid-connect/token' => $http->response('', 503)]);

        $this->assertTrue($this->cliente($http)->enviar('C', 'WA==', self::PAYLOAD)->esTransitorio());
    }

    public function test_un_401_renueva_el_token_y_reintenta_una_vez(): void
    {
        $http = new HttpFactory;
        $http->fake([
            '*openid-connect/token' => $this->token($http),
            '*recepcion*' => $http->sequence()->push('', 401)->push('', 201),
        ]);

        $r = $this->cliente($http)->enviar('C', 'WA==', self::PAYLOAD);

        $this->assertTrue($r->fueRecibido());
        $http->assertSentCount(4); // token, envío 401, token nuevo, envío 201
    }

    public function test_consultar_estado_lee_ambas_formas_de_las_claves_y_el_mensaje(): void
    {
        $xml = '<MensajeHacienda xmlns="https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/mensajeHacienda"><Clave>506</Clave><NombreEmisor>X</NombreEmisor><Mensaje>3</Mensaje><EstadoMensaje>rechazado</EstadoMensaje><DetalleMensaje>El total no cuadra</DetalleMensaje><TotalFactura>113</TotalFactura></MensajeHacienda>';

        foreach (['ind-estado' => 'respuesta-xml', 'indEstado' => 'respuestaXml'] as $estado => $respuesta) {
            $http = new HttpFactory;
            $http->fake([
                '*openid-connect/token' => $this->token($http),
                '*recepcion*' => $http->response(['clave' => 'C', 'fecha' => 'x', $estado => 'RECHAZADO', $respuesta => base64_encode($xml)]),
            ]);

            $e = $this->cliente($http)->consultarEstado('C');

            $this->assertSame(EstadoComprobante::Rechazado, $e->estado);
            $this->assertTrue($e->resuelto());
            $this->assertSame(3, $e->mensaje?->mensaje);
            $this->assertSame('El total no cuadra', $e->mensaje->detalle);
        }
    }

    public function test_consultar_estado_distingue_404_de_una_caida(): void
    {
        $http = new HttpFactory;
        $http->fake(['*openid-connect/token' => $this->token($http), '*recepcion*' => $http->response('', 404)]);
        $e = $this->cliente($http)->consultarEstado('C');
        $this->assertTrue($e->noEncontrado());
        $this->assertFalse($e->transitorio);

        $http = new HttpFactory;
        $http->fake(['*openid-connect/token' => $this->token($http), '*recepcion*' => $http->response('', 502)]);
        $this->assertTrue($this->cliente($http)->consultarEstado('C')->transitorio);
    }

    public function test_el_parser_ignora_lo_que_no_es_un_mensaje_de_hacienda(): void
    {
        $this->assertNull(MensajeHaciendaParser::parse(''));
        $this->assertNull(MensajeHaciendaParser::parse('no es xml'));
        $this->assertNull(MensajeHaciendaParser::parse('<Otro><Clave>1</Clave></Otro>'));
    }
}
