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
use Stea\FacturaElectronica\Dtos\FacturaExportacionDto;
use Stea\FacturaElectronica\Dtos\IdentificacionDto;
use Stea\FacturaElectronica\Dtos\ImpuestoDto;
use Stea\FacturaElectronica\Dtos\LineaDetalleDto;
use Stea\FacturaElectronica\Dtos\MensajeReceptorDto;
use Stea\FacturaElectronica\Dtos\ReceptorDto;
use Stea\FacturaElectronica\Dtos\UbicacionDto;
use Stea\FacturaElectronica\Enums\Environment;
use Stea\FacturaElectronica\Enums\EstadoComprobante;
use Stea\FacturaElectronica\Enums\TipoDocumento;
use Stea\FacturaElectronica\Exceptions\HaciendaRejectedException;
use Stea\FacturaElectronica\FacturaElectronicaManager;
use Stea\FacturaElectronica\Hacienda\HaciendaClient;
use Stea\FacturaElectronica\Signing\XadesEpesSigner;
use Stea\FacturaElectronica\Xml\BuilderRegistry;
use Stea\FacturaElectronica\Xml\FacturaExportacionXmlBuilder;
use Stea\FacturaElectronica\Xml\MensajeReceptorXmlBuilder;

final class ManagerEmitTest extends TestCase
{
    public function test_emits_fee_end_to_end(): void
    {
        $http = new HttpFactory;
        $http->fake([
            '*protocol/openid-connect/token' => $http->response(['access_token' => 'TKN', 'expires_in' => 300], 200),
            '*recepcion*' => $http->response('', 202),
        ]);

        $registry = new BuilderRegistry;
        $registry->register(TipoDocumento::FacturaExportacion, new FacturaExportacionXmlBuilder);

        $cert = SigningCertificate::fromPath(__DIR__.'/../fixtures/cert/test.p12', '1234');
        $client = new HaciendaClient($http, new ApiCredentials('u@stag', 'p', Environment::Sandbox));

        $manager = new FacturaElectronicaManager($registry, new ClaveGenerator, new XadesEpesSigner, $client, $cert);

        $dto = $this->dtoFromGolden();
        $result = $manager->emitir(TipoDocumento::FacturaExportacion, $dto);

        $this->assertSame(50, strlen($result->clave));
        $this->assertStringStartsWith('506', $result->clave);
        $this->assertSame($dto->consecutivo, $result->consecutivo);
        $this->assertStringContainsString('ds:Signature', $result->signedXml);
        $this->assertSame(EstadoComprobante::Enviado, $result->estado);

        // Assert the recepción body carried a base64 signed XML containing a signature.
        $http->assertSent(function ($request) {
            if (! str_contains($request->url(), 'recepcion')) {
                return false;
            }
            $xml = base64_decode($request['comprobanteXml'] ?? '', true) ?: '';

            return str_contains($xml, 'ds:Signature');
        });
    }

    public function test_emits_mensaje_receptor_end_to_end(): void
    {
        $http = new HttpFactory;
        $http->fake([
            '*protocol/openid-connect/token' => $http->response(['access_token' => 'TKN', 'expires_in' => 300], 200),
            '*recepcion*' => $http->response('', 202),
        ]);

        $registry = new BuilderRegistry;
        $registry->register(TipoDocumento::MensajeReceptorAceptado, new MensajeReceptorXmlBuilder);

        $cert = SigningCertificate::fromPath(__DIR__.'/../fixtures/cert/test.p12', '1234');
        $client = new HaciendaClient($http, new ApiCredentials('u@stag', 'p', Environment::Sandbox));

        $manager = new FacturaElectronicaManager($registry, new ClaveGenerator, new XadesEpesSigner, $client, $cert);

        $dto = new MensajeReceptorDto(
            claveComprobante: '50601012600310100000000100001050000000001100000001',
            numeroCedulaEmisor: '3101999999',
            fechaEmisionDoc: new DateTimeImmutable('2026-01-01T10:00:00.000'),
            mensaje: 1,
            montoTotalImpuesto: 13.0,
            codigoActividad: '6201.0',
            condicionImpuesto: '01',
            montoTotalImpuestoAcreditar: 13.0,
            montoTotalDeGastoAplicable: 100.0,
            totalFactura: 113.0,
            numeroCedulaReceptor: '3101000000',
            numeroConsecutivoReceptor: '00100001050000000001',
        );

        $result = $manager->emitirMensajeReceptor($dto);

        // The clave is the RECEIVED comprobante's clave — not freshly generated.
        $this->assertSame($dto->claveComprobante, $result->clave);
        $this->assertSame($dto->numeroConsecutivoReceptor(), $result->consecutivo);
        $this->assertStringContainsString('ds:Signature', $result->signedXml);
        $this->assertSame(EstadoComprobante::Enviado, $result->estado);

        $http->assertSent(function ($request) use ($dto) {
            if (! str_contains($request->url(), 'recepcion')) {
                return false;
            }
            if (($request['clave'] ?? null) !== $dto->claveComprobante) {
                return false;
            }
            $xml = base64_decode($request['comprobanteXml'] ?? '', true) ?: '';

            return str_contains($xml, 'ds:Signature') && str_contains($xml, 'MensajeReceptor');
        });
    }

    public function test_consultar_returns_resultado_with_aceptado_estado(): void
    {
        $http = new HttpFactory;
        $http->fake([
            '*protocol/openid-connect/token' => $http->response(['access_token' => 'TKN', 'expires_in' => 300], 200),
            '*recepcion*' => $http->response([
                'ind-estado' => 'aceptado',
                'respuesta-xml' => base64_encode('<MensajeHacienda/>'),
            ], 200),
        ]);

        $manager = $this->makeManager($http);

        $result = $manager->consultar('CLAVE50DIGITS12345678901234567890123456789012345678');

        $this->assertSame(EstadoComprobante::Aceptado, $result->estado);
        $this->assertSame('<MensajeHacienda/>', $result->respuestaXml);
    }

    public function test_consultar_throws_rejected_exception_with_respuesta_xml(): void
    {
        $http = new HttpFactory;
        $http->fake([
            '*protocol/openid-connect/token' => $http->response(['access_token' => 'TKN', 'expires_in' => 300], 200),
            '*recepcion*' => $http->response([
                'ind-estado' => 'rechazado',
                'respuesta-xml' => base64_encode('<MensajeRechazo/>'),
            ], 200),
        ]);

        $manager = $this->makeManager($http);

        $this->expectException(HaciendaRejectedException::class);

        try {
            $manager->consultar('CLAVE50DIGITS12345678901234567890123456789012345678');
        } catch (HaciendaRejectedException $e) {
            $this->assertSame('<MensajeRechazo/>', $e->respuestaXml);

            throw $e;
        }
    }

    private function makeManager(HttpFactory $http): FacturaElectronicaManager
    {
        $registry = new BuilderRegistry;
        $registry->register(TipoDocumento::FacturaExportacion, new FacturaExportacionXmlBuilder);

        $cert = SigningCertificate::fromPath(__DIR__.'/../fixtures/cert/test.p12', '1234');
        $client = new HaciendaClient($http, new ApiCredentials('u@stag', 'p', Environment::Sandbox));

        return new FacturaElectronicaManager($registry, new ClaveGenerator, new XadesEpesSigner, $client, $cert);
    }

    private function dtoFromGolden(): FacturaExportacionDto
    {
        return new FacturaExportacionDto(
            consecutivo: '00100001090000000001',
            fechaEmision: new DateTimeImmutable('2026-01-01T10:00:00-06:00'),
            proveedorSistemas: '2100042005',
            codigoActividadEmisor: '6201.0',
            emisor: new EmisorDto(
                nombre: 'MI EMPRESA S.A.',
                identificacion: new IdentificacionDto('02', '3101000000'),
                ubicacion: new UbicacionDto(
                    provincia: '1',
                    canton: '01',
                    distrito: '01',
                    otrasSenas: '123 Calle Principal, San Jose',
                ),
                correoElectronico: 'emisor@example.com',
            ),
            receptor: new ReceptorDto(
                nombre: 'FOREIGN CLIENT, INC',
                identificacion: new IdentificacionDto('05', '00-0000000'),
                otrasSenasExtranjero: '100 Example Street, Anytown, USA',
            ),
            lineas: [
                new LineaDetalleDto(
                    numeroLinea: 1,
                    codigoCabys: '8314100000400',
                    cantidad: 10.0,
                    unidadMedida: 'h',
                    detalle: 'Servicios de desarrollo de software',
                    precioUnitario: 50.0,
                    montoTotal: 500.0,
                    subTotal: 500.0,
                    montoTotalLinea: 500.0,
                    impuestos: [
                        new ImpuestoDto(codigo: '01', codigoTarifa: '10', tarifa: 0.0, monto: 0.0),
                    ],
                ),
            ],
            condicionVenta: '01',
            medioPago: '04',
            moneda: 'USD',
            tipoCambio: 500.0,
        );
    }
}
