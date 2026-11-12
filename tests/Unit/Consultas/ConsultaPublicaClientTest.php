<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Tests\Unit\Consultas;

use DateTimeImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use PHPUnit\Framework\TestCase;
use Stea\FacturaElectronica\Consultas\ConsultaNoDisponible;
use Stea\FacturaElectronica\Consultas\ConsultaPublicaClient;
use Stea\FacturaElectronica\Consultas\LimiteExcedido;

final class ConsultaPublicaClientTest extends TestCase
{
    private function cliente(HttpFactory $http): ConsultaPublicaClient
    {
        return new ConsultaPublicaClient($http);
    }

    public function test_lee_un_contribuyente_inscrito(): void
    {
        $http = new HttpFactory;
        $http->fake(['*/fe/ae*' => $http->response([
            'nombre' => 'COMPAÑIA DE PRUEBA S.A.', 'tipoIdentificacion' => '02',
            'regimen' => ['codigo' => 1, 'descripcion' => 'Régimen general'],
            'situacion' => ['moroso' => 'NO', 'omiso' => 'NO', 'estado' => 'Inscrito', 'administracionTributaria' => 'X'],
            'actividades' => [
                ['estado' => 'A', 'tipo' => 'P', 'codigo' => '3510.0', 'descripcion' => 'Energía'],
                ['estado' => 'D', 'tipo' => 'S', 'codigo' => '4711.0', 'descripcion' => 'Comercio'],
            ],
        ])]);

        $c = $this->cliente($http)->contribuyente('3-101-000046');

        $this->assertNotNull($c);
        $this->assertSame('3101000046', $c->identificacion);
        $this->assertSame('COMPAÑIA DE PRUEBA S.A.', $c->nombre);
        $this->assertTrue($c->inscrito());
        $this->assertFalse($c->moroso);
        $this->assertCount(1, $c->actividadesActivas());
        $this->assertSame('351000', $c->actividadesActivas()[0]->codigoXml());
        $http->assertSent(fn ($r) => str_contains((string) $r->url(), 'identificacion=3101000046'));
    }

    public function test_una_persona_no_inscrita_trae_mensaje_y_sin_actividades(): void
    {
        $http = new HttpFactory;
        $http->fake(['*' => $http->response([
            'nombre' => 'JUAN PEREZ', 'tipoIdentificacion' => '01', 'regimen' => ['codigo' => 0, 'descripcion' => 'No tiene'],
            'situacion' => ['moroso' => 'SI', 'omiso' => 'NO', 'estado' => 'No inscrito', 'mensaje' => 'Debe inscribirse'],
            'actividades' => [],
        ])]);

        $c = $this->cliente($http)->contribuyente('109990999');

        $this->assertNotNull($c);
        $this->assertFalse($c->inscrito());
        $this->assertTrue($c->moroso);
        $this->assertSame('Debe inscribirse', $c->mensaje);
    }

    public function test_un_404_es_null_y_un_429_lanza_limite_excedido(): void
    {
        $http = new HttpFactory;
        $http->fake(['*' => $http->response(['code' => 404], 404)]);
        $this->assertNull($this->cliente($http)->contribuyente('999999999999'));

        $http = new HttpFactory;
        $http->fake(['*' => $http->response('', 429)]);
        $this->expectException(LimiteExcedido::class);
        $this->cliente($http)->contribuyente('3101000046');
    }

    public function test_un_5xx_lanza_consulta_no_disponible(): void
    {
        $http = new HttpFactory;
        $http->fake(['*' => $http->response('', 503)]);

        $this->expectException(ConsultaNoDisponible::class);
        $this->cliente($http)->contribuyente('3101000046');
    }

    public function test_una_identificacion_con_formato_invalido_no_llega_a_la_red(): void
    {
        $http = new HttpFactory;
        $http->fake();

        $this->assertNull(ConsultaPublicaClient::normalizarIdentificacion('12345678'));
        $this->assertSame('108450321', ConsultaPublicaClient::normalizarIdentificacion('1-0845-0321'));

        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->cliente($http)->contribuyente('abc');
        } finally {
            $http->assertNothingSent();
        }
    }

    public function test_lee_una_exoneracion_y_calcula_vigencia_y_cobertura(): void
    {
        $http = new HttpFactory;
        $http->fake(['*/fe/ex*' => $http->response([
            'numeroDocumento' => 'AL-00012345-23', 'identificacion' => '3007051185', 'porcentajeExoneracion' => 13,
            'fechaEmision' => '2023-02-18T00:00:00', 'fechaVencimiento' => '2027-09-01T00:00:00',
            'cabys' => ['4311502000000'], 'poseeCabys' => true,
            'tipoDocumento' => ['codigo' => '04', 'descripcion' => 'Exenciones DGH'],
            'CodigoInstitucion' => '01', 'nombreInstitucion' => 'Ministerio de Hacienda', 'tipoAutorizacion' => 'exoneracion',
        ])]);

        $e = $this->cliente($http)->exoneracion('al-00012345-23');

        $this->assertNotNull($e);
        $this->assertSame('AL-00012345-23', $e->numeroDocumento);
        $this->assertSame('01', $e->codigoInstitucion);
        $this->assertSame(13.0, $e->porcentajeExoneracion);
        $this->assertTrue($e->vigente(new DateTimeImmutable('2026-10-23')));
        $this->assertFalse($e->vigente(new DateTimeImmutable('2027-09-02')));
        $this->assertTrue($e->cubre('4311502000000'));
        $this->assertFalse($e->cubre('8713100000000'));
    }

    public function test_el_formato_de_la_autorizacion_se_valida(): void
    {
        $this->assertNull(ConsultaPublicaClient::normalizarAutorizacion('AL-0460853-20'));
        $this->assertSame('AL-00460853-20', ConsultaPublicaClient::normalizarAutorizacion(' al-00460853-20 '));
    }

    public function test_busca_y_consulta_cabys(): void
    {
        $http = new HttpFactory;
        $http->fake([
            '*codigo=4391501009900*' => $http->response([['codigo' => '4391501009900', 'descripcion' => 'Filtros de aceite', 'impuesto' => 13, 'categorias' => ['A', 'B']]]),
            '*q=*' => $http->response(['total' => 170, 'cantidad' => 1, 'cabys' => [['codigo' => '4391501009900', 'descripcion' => 'Filtros de aceite', 'impuesto' => 13, 'categorias' => []]]]),
        ]);
        $c = $this->cliente($http);

        $busqueda = $c->buscarCabys('filtro aceite');
        $this->assertSame(170, $busqueda['total']);
        $this->assertSame('4391501009900', $busqueda['items'][0]->codigo);
        $this->assertSame(13.0, $busqueda['items'][0]->impuesto);
        $this->assertSame([], $c->buscarCabys('ab')['items']);
        $this->assertSame('Filtros de aceite', $c->cabys('4391501009900')?->descripcion);
    }

    public function test_un_codigo_cabys_inexistente_devuelve_null(): void
    {
        $http = new HttpFactory;
        $http->fake(['*' => $http->response([])]);

        $this->assertNull($this->cliente($http)->cabys('1111111111111'));
    }
}
