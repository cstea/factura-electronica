<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use PHPUnit\Framework\TestCase;
use Stea\FacturaElectronica\Clave\ClaveGenerator;
use Stea\FacturaElectronica\Credentials\ApiCredentials;
use Stea\FacturaElectronica\Credentials\SigningCertificate;
use Stea\FacturaElectronica\Dtos\EmisorDto;
use Stea\FacturaElectronica\Dtos\IdentificacionDto;
use Stea\FacturaElectronica\Dtos\ImpuestoDto;
use Stea\FacturaElectronica\Dtos\LineaDetalleDto;
use Stea\FacturaElectronica\Dtos\ReceptorDto;
use Stea\FacturaElectronica\Dtos\TiqueteDto;
use Stea\FacturaElectronica\Dtos\UbicacionDto;
use Stea\FacturaElectronica\Enums\Environment;
use Stea\FacturaElectronica\Enums\Situacion;
use Stea\FacturaElectronica\Enums\TipoDocumento;
use Stea\FacturaElectronica\Exceptions\XmlBuildException;
use Stea\FacturaElectronica\FacturaElectronicaManager;
use Stea\FacturaElectronica\Hacienda\HaciendaClient;
use Stea\FacturaElectronica\Hacienda\RespuestaEnvio;
use Stea\FacturaElectronica\Signing\XadesEpesSigner;
use Stea\FacturaElectronica\Xml\BuilderRegistry;
use Stea\FacturaElectronica\Xml\TiqueteXmlBuilder;

final class PrepararTest extends TestCase
{
    private function manager(HttpFactory $http): FacturaElectronicaManager
    {
        $registry = new BuilderRegistry;
        $registry->register(TipoDocumento::Tiquete, new TiqueteXmlBuilder);

        return new FacturaElectronicaManager(
            $registry,
            new ClaveGenerator,
            new XadesEpesSigner,
            new HaciendaClient($http, new ApiCredentials('u@stag', 'p', Environment::Sandbox)),
            SigningCertificate::fromPath(__DIR__.'/../fixtures/cert/test.p12', '1234'),
        );
    }

    private function dto(): TiqueteDto
    {
        return new TiqueteDto(
            '00100001040000000007',
            new DateTimeImmutable('2026-01-01T10:00:00-06:00'),
            '3101000000',
            '452001',
            new EmisorDto('EMISOR DE PRUEBA S.A.', new IdentificacionDto('02', '3101000000'), new UbicacionDto('1', '01', '01', null, 'Frente al parque'), 'e@example.com'),
            new ReceptorDto('Cliente de contado'),
            [new LineaDetalleDto(1, '4311502000000', '1', 'Unid', 'Filtro', '100', '100', '100', '113', [new ImpuestoDto('01', '08', '13', '13')])],
            medioPago: '01',
        );
    }

    public function test_respeta_la_clave_ya_asignada_y_firma_con_ella(): void
    {
        $clave = (new ClaveGenerator)->generate('3101000000', new DateTimeImmutable('2026-01-01T10:00:00-06:00'), '00100001040000000007', Situacion::Normal, '87654321');

        $p = $this->manager(new HttpFactory)->preparar(TipoDocumento::Tiquete, $this->dto(), clave: $clave);

        $this->assertSame($clave, $p->clave);
        $this->assertSame('87654321', $p->codigoSeguridad);
        $this->assertStringContainsString("<Clave>{$clave}</Clave>", $p->xmlFirmado);
    }

    public function test_una_clave_que_no_corresponde_al_consecutivo_se_rechaza(): void
    {
        $this->expectException(XmlBuildException::class);
        $this->manager(new HttpFactory)->preparar(TipoDocumento::Tiquete, $this->dto(), clave: str_repeat('1', 50));
    }

    public function test_preparar_no_envia_nada_y_enviar_manda_los_mismos_bytes_firmados(): void
    {
        $http = new HttpFactory;
        $http->fake([
            '*openid-connect/token' => $http->response(['access_token' => 'T', 'expires_in' => 300]),
            '*recepcion*' => $http->response('', 201),
        ]);
        $manager = $this->manager($http);

        $p = $manager->preparar(TipoDocumento::Tiquete, $this->dto());
        $http->assertNothingSent();

        $r = $manager->enviar($p);

        $this->assertSame(RespuestaEnvio::RECIBIDO, $r->clase);
        $http->assertSent(fn ($req) => str_contains((string) $req->url(), '/recepcion') && ($req->data()['comprobanteXml'] ?? null) === base64_encode($p->xmlFirmado));
    }

    public function test_un_xml_que_no_valida_contra_el_xsd_no_llega_a_hacienda(): void
    {
        $dto = $this->dto();
        $malo = new TiqueteDto($dto->consecutivo, $dto->fechaEmision, $dto->proveedorSistemas, '12', $dto->emisor, $dto->receptor, $dto->lineas, medioPago: '01');

        $this->expectException(XmlBuildException::class);
        $this->expectExceptionMessage('XSD');
        $this->manager(new HttpFactory)->preparar(TipoDocumento::Tiquete, $malo);
    }
}
