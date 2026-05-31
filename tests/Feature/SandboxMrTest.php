<?php

declare(strict_types=1);

/**
 * GATED SANDBOX EMISSION TEST — MR (Mensaje Receptor, tipo 05)
 *
 * This test is intentionally manual/gated. It does NOT mock HTTP — it fires
 * a real MR against Hacienda's api-stag environment.
 *
 * SANDBOX LIMITATION NOTE:
 * The SYNTH_CLAVE used here is a synthetic placeholder. For a live sandbox run you
 * would replace it with a clave from a comprobante that actually exists in Hacienda's
 * sandbox ledger. The Hacienda SANDBOX may not resolve a synthetic clave to aceptado.
 * The meaningful assertion is that recepción accepted our MR payload+signature (HTTP 2xx → Enviado).
 *
 * To run:
 *   FE_SANDBOX=1 \
 *   FE_USERNAME=your@email.cr \
 *   FE_PASSWORD=yourPassword \
 *   FE_PIN=1234 \
 *   FE_P12_PATH=/absolute/path/to/cert.p12 \
 *   vendor/bin/phpunit tests/Feature/SandboxMrTest.php
 *
 * Without FE_SANDBOX=1 the test is silently skipped — CI stays green.
 */

namespace Stea\FacturaElectronica\Tests\Feature;

use DateTimeImmutable;
use Orchestra\Testbench\TestCase;
use Stea\FacturaElectronica\Dtos\MensajeReceptorDto;
use Stea\FacturaElectronica\Enums\EstadoComprobante;
use Stea\FacturaElectronica\Exceptions\HaciendaRejectedException;
use Stea\FacturaElectronica\Exceptions\HaciendaSendException;
use Stea\FacturaElectronica\FacturaElectronicaManager;
use Stea\FacturaElectronica\FacturaElectronicaServiceProvider;

final class SandboxMrTest extends TestCase
{
    /**
     * Synthetic received-comprobante clave (50 digits, no real party encoded).
     *
     * A real MR sandbox test would use a clave from a comprobante that exists in
     * Hacienda's sandbox ledger. This constant is a synthetic placeholder — supply
     * a real sandbox clave via environment variables if running live sandbox tests.
     */
    private const SYNTH_CLAVE = '50601012600310100000000100001050000000001100000001';

    /** Synthetic cédula jurídica of the received-comprobante emisor. */
    private const SYNTH_CEDULA_EMISOR = '3101999999';

    // -------------------------------------------------------------------------
    // Testbench wiring
    // -------------------------------------------------------------------------

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [FacturaElectronicaServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Real sandbox environment — credentials come from env vars supplied by
        // the operator; they are never committed to the repository.
        $app['config']->set('factura-electronica.environment', 'sandbox');
        $app['config']->set('factura-electronica.credentials.username', (string) getenv('FE_USERNAME'));
        $app['config']->set('factura-electronica.credentials.password', (string) getenv('FE_PASSWORD'));
        $app['config']->set('factura-electronica.certificate.path', (string) getenv('FE_P12_PATH'));
        $app['config']->set('factura-electronica.certificate.pin', (string) getenv('FE_PIN'));
    }

    // -------------------------------------------------------------------------
    // Gate: real MR against api-stag
    // -------------------------------------------------------------------------

    public function test_emits_mr_to_sandbox_and_recepcion_accepts_payload(): void
    {
        // ── Gate ──────────────────────────────────────────────────────────────
        // Skip unless the operator has explicitly opted in to real-network calls.
        if (getenv('FE_SANDBOX') !== '1') {
            $this->markTestSkipped('Set FE_SANDBOX=1 + FE_USERNAME/FE_PASSWORD/FE_PIN/FE_P12_PATH to run.');
        }

        // ── Build a fresh, collision-safe consecutivo ─────────────────────────
        // Format: sucursal(001) + terminal(00001) + tipo(05) + secuencia(10 digits)
        // We use a high random sequence (9 000 000 000–9 999 999 999) to avoid
        // colliding with any real submitted MRs.
        $secuencia = str_pad((string) random_int(9_000_000_000, 9_999_999_999), 10, '0', STR_PAD_LEFT);
        $consecutivo = '001'.'00001'.'05'.$secuencia; // 20 digits total

        $this->assertSame(20, strlen($consecutivo), 'Consecutivo must be exactly 20 digits.');

        // ── Build MR DTO ──────────────────────────────────────────────────────
        // All values are synthetic placeholders. For a live sandbox run, replace
        // SYNTH_CLAVE and SYNTH_CEDULA_EMISOR with real values from a comprobante
        // that exists in Hacienda's sandbox ledger.
        $dto = new MensajeReceptorDto(
            claveComprobante: self::SYNTH_CLAVE,
            numeroCedulaEmisor: self::SYNTH_CEDULA_EMISOR,
            fechaEmisionDoc: new DateTimeImmutable('2026-01-01T10:00:00.000'),
            mensaje: 1,                          // 1 = aceptado
            montoTotalImpuesto: 13.0,
            codigoActividad: '6201.0',
            condicionImpuesto: '01',
            montoTotalImpuestoAcreditar: 13.0,
            montoTotalDeGastoAplicable: 100.0,
            totalFactura: 113.0,
            numeroCedulaReceptor: '3101000000',
            numeroConsecutivoReceptor: $consecutivo,
        );

        // ── Emit ──────────────────────────────────────────────────────────────
        // SANDBOX LIMITATION (MR): The MR sandbox is inherently limited — Hacienda's
        // sandbox may not have the referenced production comprobante in its ledger.
        // Additionally, if the same MR consecutivo was already submitted, Hacienda will
        // return a 400 "duplicate" error. Both outcomes are acceptable here; what is NOT
        // acceptable is a 400 caused by a malformed payload (missing consecutivoReceptor,
        // wrong emisor/receptor shape, etc.).
        /** @var FacturaElectronicaManager $manager */
        $manager = $this->app->make(FacturaElectronicaManager::class);

        try {
            $result = $manager->emitirMensajeReceptor($dto);
        } catch (HaciendaSendException $e) {
            // Inspect the body to distinguish a duplicate/already-registered MR
            // (acceptable sandbox outcome) from a malformed-payload error (real bug).
            $bodyLower = strtolower($e->body);
            $isDuplicate = str_contains($bodyLower, 'duplicad')
                || str_contains($bodyLower, 'ya ha sido')
                || str_contains($bodyLower, 'ya fue')
                || str_contains($bodyLower, 'registrado anteriormente');

            $this->assertTrue(
                $isDuplicate,
                "Hacienda recepción returned HTTP {$e->status} with an unexpected (non-duplicate) error body.\n".
                "This indicates a malformed payload — NOT a sandbox limitation.\n".
                "Response body:\n{$e->body}",
            );

            // Duplicate is an acceptable sandbox outcome: our payload was valid
            // but Hacienda already registered this MR consecutivo.
            return;
        }

        // A 2xx from recepción means the MR payload shape + signature are valid.
        // emitirMensajeReceptor() throws HaciendaSendException on non-2xx.
        $this->assertSame(
            EstadoComprobante::Enviado,
            $result->estado,
            'emitirMensajeReceptor() must return estado=enviado after a 2xx from Hacienda recepción.',
        );

        // ── Poll for final estado ─────────────────────────────────────────────
        // The referenced comprobante was issued in PRODUCTION, so Hacienda SANDBOX
        // may not resolve the MR to full aceptado. We poll a few times and assert
        // the estado is NOT rechazado/error — any of recibido/procesando/aceptado
        // is a passing outcome here.
        $maxAttempts = 5;
        $pollIntervalSeconds = 3;
        $finalEstado = null;
        $finalRespuestaXml = null;

        // SANDBOX LIMITATION: claveComprobante is synthetic so Hacienda's sandbox
        // cannot resolve it — consultar() will throw HaciendaRejectedException with
        // error code -29
        // ("el comprobante no se encuentra registrado"). That is the EXPECTED outcome
        // here — the meaningful assertion is already above (estado=Enviado after the
        // recepción 2xx). We catch -29 and pass; any other rejection reason is a
        // genuine failure and causes the test to re-throw / fail with full detail.
        $expectedSandboxRejection = false;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            sleep($pollIntervalSeconds);

            try {
                $consulta = $manager->consultar(self::SYNTH_CLAVE);
                $finalEstado = $consulta->estado;
                $finalRespuestaXml = $consulta->respuestaXml;
            } catch (HaciendaRejectedException $e) {
                // -29 = "el comprobante no se encuentra registrado" — sandbox cannot
                // resolve a production clave. This is expected; treat as a pass.
                if (str_contains($e->respuestaXml, '-29') || str_contains($e->respuestaXml, 'no se encuentra registrado')) {
                    $expectedSandboxRejection = true;
                    break;
                }

                // Any other rejection reason is an actual failure.
                $this->fail(
                    "Hacienda RECHAZADO (unexpected reason) on attempt {$attempt} for clave ".self::SYNTH_CLAVE.".\n".
                    "respuestaXml:\n".$e->respuestaXml,
                );
            }

            // If aceptado, great — no need to keep polling.
            if ($finalEstado === EstadoComprobante::Aceptado) {
                break;
            }

            // Break early on any terminal non-rechazado estado too.
            if (! in_array($finalEstado, [EstadoComprobante::Procesando, EstadoComprobante::Recibido], true)) {
                break;
            }
        }

        // ── Assert final outcome ──────────────────────────────────────────────
        // Pass if: (a) sandbox returned the expected -29 rejection (production clave
        // not in sandbox ledger), OR (b) final estado is not rechazado/error.
        if ($expectedSandboxRejection) {
            // Expected sandbox limitation — recepción accepted our payload (Enviado
            // asserted above) and -29 confirms the rejection is only because sandbox
            // doesn't know this production clave. This is a pass.
            $this->addToAssertionCount(1); // recepción accepted our payload; sandbox rejected with expected -29 (production clave not in sandbox ledger)

            return;
        }

        $this->assertNotContains(
            $finalEstado,
            [EstadoComprobante::Rechazado, EstadoComprobante::Error],
            "MR was rejected or errored after polling {$maxAttempts} times for clave ".self::SYNTH_CLAVE.'. '.
            "Got: {$finalEstado?->value}.\nrespuestaXml:\n{$finalRespuestaXml}",
        );
    }
}
