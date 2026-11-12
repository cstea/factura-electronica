# stea/factura-electronica

[![CI](https://github.com/cstea/factura-electronica/actions/workflows/ci.yml/badge.svg)](https://github.com/cstea/factura-electronica/actions/workflows/ci.yml)
[![Latest version](https://img.shields.io/packagist/v/stea/factura-electronica.svg)](https://packagist.org/packages/stea/factura-electronica)
[![PHP](https://img.shields.io/packagist/dependency-v/stea/factura-electronica/php.svg)](composer.json)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

A native PHP 8.3 / 8.4 / 8.5 / Laravel package for **Costa Rica factura electrónica v4.4**. It builds
comprobante XML, signs it with **XAdES-EPES** using a Hacienda-issued PKCS#12 certificate, and
submits it to the Ministerio de Hacienda reception API — with no dependency on external SOAP
wrappers, Java signers, or proprietary tooling.

The signing layer is built cleanly on top of [`robrichards/xmlseclibs`](https://github.com/robrichards/xmlseclibs);
the rest is plain PHP + `illuminate/http`. The core (DTOs, clave, XML builders, signer) has no
Laravel dependency and is independently testable.

> **Status:** FEE, FEC, FE and MensajeReceptor are accepted by Hacienda's sandbox (`api-stag`) through
> the gated sandbox tests, and FE, TE, NC and ND have been validated against Hacienda by a production
> user. This is community software, not affiliated with or endorsed by the Ministerio de Hacienda.
> **Validate against your own Hacienda environment before relying on it in production.**
> No warranty — see LICENSE.
>
> Exact decimal amounts, descuentos, exoneraciones, several medios de pago, the two-step send, the
> public queries and the certificate check were contributed by Santiago Rodríguez
> ([@santydesignscr](https://github.com/santydesignscr), #1).

## Features

- **All v4.4 document types:** `FacturaElectronica` (01), `NotaDebito` (02), `NotaCredito` (03),
  `TiqueteElectronico` (04), `FacturaElectronicaCompra` (08), `FacturaElectronicaExportacion` (09),
  and `MensajeReceptor` (05/06/07).
- **Clave + consecutivo** helpers (you own the numbering).
- **XAdES-EPES** signing: signing-policy hash, `SignedProperties`, `SigningCertificate`,
  `SignerRole`, RSA-SHA256, the three required references — built to the structure Hacienda accepts.
- **Exact amounts:** money is a decimal string computed with `bcmath` (never `float`);
  `ResumenCalculator` builds the `ResumenFactura` or you pass your own `ResumenDto`.
- **Descuentos, exoneraciones and up to 4 medios de pago** (`DescuentoDto`, `ExoneracionDto`,
  `MedioPagoDto`), with `plazoCredito` for credit sales.
- **Transport:** OAuth2 password grant against the IDP, submit to `recepción` and query the status,
  for both sandbox and production. The token can be shared across processes through the Laravel cache
  (`CacheTokenStore`, one entry per environment + username), with a lock so concurrent workers log in
  once; timeouts, one retry after a 401, and Hacienda's "ya fue recibido" for the same clave treated as
  success.
- **Two-step sending:** `preparar()` (build + XSD validation + signature) and `enviar()` /
  `consultarEstado()`, which classify Hacienda's answer instead of throwing — fit for queued jobs.
- **Public queries:** `ConsultaPublicaClient` (contribuyente `fe/ae`, exoneración `fe/ex`, CABYS
  `fe/cabys`, tipo de cambio).
- **Certificate check:** `SigningCertificate::validar()` before first use.
- **Typed exceptions** and **XSD validation** against the bundled official v4.4 schemas
  (current set, including referencia codes 13–16 and tipos 19–20, mandatory from 1 Nov 2026).
- Framework-friendly: a service provider binds a `FacturaElectronicaManager` from config.

## Requirements

- PHP **8.3+** (CI on 8.3, 8.4 and 8.5)
- Extensions `ext-bcmath` (required since 0.2), `ext-openssl`, `ext-dom`, `ext-libxml`
- Laravel components `illuminate/support` and `illuminate/http` **11, 12 or 13**; a cache store
  shared by your workers (Redis, database, file…) if you send from queues
- A Hacienda-issued PKCS#12 (`.p12`) certificate and its PIN
- Hacienda API credentials (username / password) for your environment

## Installation

```bash
composer require stea/factura-electronica
```

Publish the config (Laravel):

```bash
php artisan vendor:publish --tag=factura-electronica-config
```

Set the environment variables the config reads:

```dotenv
FE_ENV=sandbox            # sandbox | production
FE_USERNAME=...
FE_PASSWORD=...
FE_PIN=...
FE_P12_PATH=/path/to/certificado.p12
FE_HTTP_TIMEOUT=30        # seconds, optional
FE_HTTP_CONNECT_TIMEOUT=10
```

> The certificate is read from a filesystem path, not an env var. Keep the `.p12` out of version
> control and inject it at deploy time.

## Usage

```php
use Stea\FacturaElectronica\FacturaElectronicaManager;
use Stea\FacturaElectronica\Enums\TipoDocumento;
use Stea\FacturaElectronica\Dtos\{FacturaExportacionDto, EmisorDto, ReceptorDto, IdentificacionDto, UbicacionDto};

$dto = new FacturaExportacionDto(
    consecutivo: '00100001090000000001',           // you allocate this (sucursal+terminal+tipo+seq)
    fechaEmision: new DateTimeImmutable('now'),
    proveedorSistemas: '3101000000',
    codigoActividadEmisor: '620100',
    emisor: new EmisorDto(
        nombre: 'MI EMPRESA S.A.',
        identificacion: new IdentificacionDto('02', '3101000000'),
        ubicacion: new UbicacionDto('1', '01', '01'),
        correoElectronico: 'facturacion@example.com',
    ),
    receptor: new ReceptorDto(
        nombre: 'FOREIGN CLIENT, INC',
        identificacionExtranjero: '00-0000000',
        otrasSenasExtranjero: '123 Main St, City, ST 00000',
    ),
    lineas: [ /* LineaDetalleDto[] */ ],
    moneda: 'USD',
    tipoCambio: 1.0,
);

/** @var FacturaElectronicaManager $manager */
$result = $manager->emitir(TipoDocumento::FacturaExportacion, $dto);
// $result->clave, $result->signedXml, $result->estado

// later, resolve the async status:
$status = $manager->consultar($result->clave); // aceptado | rechazado | procesando ...
```

`MensajeReceptor` (accepting a received comprobante) uses `emitirMensajeReceptor(MensajeReceptorDto)`.

### Recommended flow for production (queued)

`emitir()` is kept for compatibility but throws on HTTP errors. For jobs, prepare once, persist the
signed XML and retry only the sending:

```php
$p = $manager->preparar(TipoDocumento::Factura, $dto);   // validates XSD + signs; throws on bad input
// store $p->clave and $p->xmlFirmado now

$r = $manager->enviar($p);                                // never throws on an HTTP answer
match (true) {
    $r->fueRecibido()   => /* wait for the verdict */,
    $r->esTransitorio() => /* retry later with the SAME signed XML */,
    default             => /* RECHAZO: fix the content, build a new comprobante */,
};

$e = $manager->consultarEstado($p->clave);               // EstadoConsulta
$e->resuelto();                                           // aceptado | rechazado
$e->noEncontrado();                                       // 404: Hacienda does not have it (yet)
```

`preparar()` accepts an already assigned `clave` and validates it against the consecutivo, so a retry
never changes the clave.

### Amounts, discounts, exonerations and payments

Amounts are decimal strings (`'1250.00'`); `float` is still accepted but converted. Pass the lines and
the resumen is computed; give a `ResumenDto` only if you compute the totals yourself.

```php
new FacturaDto(
    /* … */
    condicionVenta: '01',
    mediosPago: [new MedioPagoDto('02', '5650.00'), new MedioPagoDto('01', '1000.00')], // up to 4
    // credit sale: condicionVenta '02', mediosPago: [], plazoCredito: 30
    referencias: [],                       // extra InformacionReferencia, up to 10 in total
    informacionReferencia: null,           // main reference (e.g. codigo 15/16 when replacing a factura)
);
```

Not supported yet: `OtrosCargos` (and `TotalOtrosCargos`). The computed `TotalComprobante` is the net sale plus tax.

## Architecture

```
Dtos/        immutable request + value DTOs
Clave/       50-digit clave generator
Xml/         DocumentBuilder per tipo -> DOMDocument; BuilderRegistry; XsdValidator; bundled v4.4 XSDs
Signing/     XadesEpesSigner (clean-room on xmlseclibs) + SignaturePolicy
Support/     Decimal + ResumenCalculator (bcmath)
Hacienda/    HaciendaClient (token / enviar / consultar) + TokenStore (memory | cache)
Consultas/   ConsultaPublicaClient (contribuyente, exoneración, CABYS, tipo de cambio)
FacturaElectronicaManager   preparar -> enviar -> consultarEstado  (emitir = preparar + send)
```

FE, TE, NC and ND share one builder (`Xml\Concerns\BuildsComprobanteVenta`).

## Upgrading from 0.1.x

1. **`ext-bcmath` is required.**
2. **`TokenStore` is an interface** whose methods take a `$scope` (`get`, `put`, `forget`, `exclusive`);
   `HaciendaClient` derives the scope from the environment and username. Replace `new TokenStore()`
   with `new MemoryTokenStore()` or `CacheTokenStore`; the Laravel service provider wires
   `CacheTokenStore`.
3. **`medioPago` no longer defaults to `'04'`.** It is `?string` and defaults to `null`; set it or use
   `mediosPago`. A cash sale without one now fails to build instead of silently claiming a transfer.
4. **Totals are computed with `bcmath`**, so a figure that only matched thanks to float error can
   differ by a céntimo. Re-check stored expected totals.
5. FE, TE, NC and ND always emit `BaseImponible`, and `Cantidad` has 3 decimals.
6. `HaciendaClient::send()` and `emitir()` keep their contract: `HaciendaSendException` means Hacienda
   answered; a timeout or a failed login propagates as `ConnectionException` / `HaciendaAuthException`.
   The new `enviar()` never throws.

New constructor arguments are appended at the end of each DTO, so existing named and positional calls
keep working.

## Testing

```bash
composer install
vendor/bin/phpunit
```

Document builders are validated against the official v4.4 XSDs. A gated integration suite can emit to
the Hacienda sandbox when `FE_SANDBOX=1` and credentials are present in the environment; it is skipped
otherwise.

## Costa Rica Hacienda v4.4 references

Official Ministerio de Hacienda / Dirección General de Tributación documentation this package
implements (v4.4 is mandatory since 2025; it adds `ProveedorSistemas`, clave/consecutivo coding,
and `CodigoActividadReceptor`):

- **Anexos y Estructuras v4.4** (the authoritative technical spec — document structures + field notes;
  updated May 2026 with the November 2026 reference codes):
  [PDF](https://www.hacienda.go.cr/docs/ANEXOS_Y_ESTRUCTURAS_V4.4.pdf) ·
  [comunicado](https://www.hacienda.go.cr/docs/ActualizacionAnexosyEstructurasVersion4.4CEv2.pdf) ·
  [older ATV portal](https://atv.hacienda.go.cr/ATV/ComprobanteElectronico/frmAnexosyEstructuras.aspx)
- **Comprobantes Electrónicos — Generalidades y Versión 4.4** (overview of the changes):
  [PDF](https://www.hacienda.go.cr/docs/ComprobantesElectronicos-GeneralidadesyVersion4.4.marzo2025.pdf)
- **Resolución DGT — Disposiciones Técnicas de Comprobantes Electrónicos** (the governing resolution):
  [PDF](https://www.hacienda.go.cr/docs/DGT-R-000-2024DisposicionesTecnicasDeComprobantesElectronicosCP.pdf)
- **Official v4.4 XML schemas (XSD)** — bundled in `resources/xsd/v4.4/`, the current set published on
  `https://www.hacienda.go.cr/docs/` (`FacturaElectronica_V4.4.xsd`, …); namespaces under
  `https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/` (e.g.
  [facturaElectronica.xsd](https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/facturaElectronica.xsd),
  `facturaElectronicaCompra.xsd`, `facturaElectronicaExportacion.xsd`, `mensajeReceptor.xsd`, …)
- **XAdES signature policy** — the resolution whose SHA-256 digest is embedded in the `SignaturePolicy`:
  [Resolución General sobre disposiciones técnicas (PDF)](https://cdn.comprobanteselectronicos.go.cr/xml-schemas/Resoluci%C3%B3n_General_sobre_disposiciones_t%C3%A9cnicas_comprobantes_electr%C3%B3nicos_para_efectos_tributarios.pdf)
- **ATV — Comprobantes Electrónicos portal:**
  [atv.hacienda.go.cr](https://atv.hacienda.go.cr/ATV/ComprobanteElectronico/frmInicio.aspx)

## Contributing

Issues and PRs welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) for setup, testing, static analysis,
code style, and PR guidelines. Please do not include real cédulas, certificates, signatures, or
invoice data in fixtures or examples — use synthetic values only.

## License

MIT — see [LICENSE](LICENSE).
