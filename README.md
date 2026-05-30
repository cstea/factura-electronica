# stea/factura-electronica

[![CI](https://github.com/cstea/factura-electronica/actions/workflows/ci.yml/badge.svg)](https://github.com/cstea/factura-electronica/actions/workflows/ci.yml)
[![Latest version](https://img.shields.io/packagist/v/stea/factura-electronica.svg)](https://packagist.org/packages/stea/factura-electronica)
[![PHP](https://img.shields.io/packagist/dependency-v/stea/factura-electronica/php.svg)](composer.json)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

A native PHP 8.5 / Laravel package for **Costa Rica factura electrónica v4.4**. It builds
comprobante XML, signs it with **XAdES-EPES** using a Hacienda-issued PKCS#12 certificate, and
submits it to the Ministerio de Hacienda reception API — with no dependency on external SOAP
wrappers, Java signers, or proprietary tooling.

The signing layer is built cleanly on top of [`robrichards/xmlseclibs`](https://github.com/robrichards/xmlseclibs);
the rest is plain PHP + `illuminate/http`. The core (DTOs, clave, XML builders, signer) has no
Laravel dependency and is independently testable.

> **Status:** the four document types this project actually transacts — FE, FEE, FEC, and
> MensajeReceptor — have been accepted by Hacienda's sandbox (`api-stag`). ND, NC, and TE build and
> validate against the official v4.4 XSDs but have not been exercised against a live response. This
> is community software, not affiliated with or endorsed by the Ministerio de Hacienda. **Validate
> against your own Hacienda environment before relying on it in production.** No warranty — see LICENSE.

## Features

- **All v4.4 document types:** `FacturaElectronica` (01), `NotaDebito` (02), `NotaCredito` (03),
  `TiqueteElectronico` (04), `FacturaElectronicaCompra` (08), `FacturaElectronicaExportacion` (09),
  and `MensajeReceptor` (05/06/07).
- **Clave + consecutivo** helpers (you own the numbering).
- **XAdES-EPES** signing: signing-policy hash, `SignedProperties`, `SigningCertificate`,
  `SignerRole`, RSA-SHA256, the three required references — built to the structure Hacienda accepts.
- **Transport:** OAuth2 password grant against the IDP (token cached), submit to `recepción`, and
  `consultar` status, for both sandbox and production.
- **Typed exceptions** and **XSD validation** against the bundled official v4.4 schemas.
- Framework-friendly: a service provider binds a `FacturaElectronicaManager` from config.

## Requirements

- PHP **8.5+**, `ext-openssl`, `ext-dom`, `ext-libxml`
- A Hacienda-issued PKCS#12 (`.p12`) certificate and its PIN
- Hacienda API credentials (username / password / `client_id`) for your environment

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

## Architecture

```
Dtos/        immutable request + value DTOs
Clave/       50-digit clave generator
Xml/         DocumentBuilder per tipo -> DOMDocument; BuilderRegistry; XsdValidator; bundled v4.4 XSDs
Signing/     XadesEpesSigner (clean-room on xmlseclibs) + SignaturePolicy
Hacienda/    HaciendaClient (token / send / consultar) + TokenStore
FacturaElectronicaManager   orchestrates clave -> build -> sign -> send -> consultar
```

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

- **Anexos y Estructuras v4.4** (the authoritative technical spec — document structures + field notes):
  [portal](https://atv.hacienda.go.cr/ATV/ComprobanteElectronico/frmAnexosyEstructuras.aspx) ·
  [PDF](https://atv.hacienda.go.cr/ATV/ComprobanteElectronico/docs/esquemas/2024/v4.4/ANEXOS%20Y%20ESTRUCTURAS_V4.4.pdf)
- **Comprobantes Electrónicos — Generalidades y Versión 4.4** (overview of the changes):
  [PDF](https://www.hacienda.go.cr/docs/ComprobantesElectronicos-GeneralidadesyVersion4.4.marzo2025.pdf)
- **Resolución DGT — Disposiciones Técnicas de Comprobantes Electrónicos** (the governing resolution):
  [PDF](https://www.hacienda.go.cr/docs/DGT-R-000-2024DisposicionesTecnicasDeComprobantesElectronicosCP.pdf)
- **Official v4.4 XML schemas (XSD)** — bundled in `resources/xsd/v4.4/`, sourced from:
  `https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/` (e.g.
  [facturaElectronica.xsd](https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/facturaElectronica.xsd),
  `facturaElectronicaCompra.xsd`, `facturaElectronicaExportacion.xsd`, `mensajeReceptor.xsd`, …)
- **XAdES signature policy** — the resolution whose SHA-256 digest is embedded in the `SignaturePolicy`:
  [Resolución General sobre disposiciones técnicas (PDF)](https://cdn.comprobanteselectronicos.go.cr/xml-schemas/Resoluci%C3%B3n_General_sobre_disposiciones_t%C3%A9cnicas_comprobantes_electr%C3%B3nicos_para_efectos_tributarios.pdf)
- **ATV — Comprobantes Electrónicos portal:**
  [atv.hacienda.go.cr](https://atv.hacienda.go.cr/ATV/ComprobanteElectronico/frmInicio.aspx)

## Contributing

Issues and PRs welcome. Please do not include real cédulas, certificates, signatures, or invoice data
in fixtures or examples — use synthetic values only.

## License

MIT — see [LICENSE](LICENSE).
