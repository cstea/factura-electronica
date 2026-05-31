# Contributing

## Requirements

- **PHP 8.5+** with extensions `ext-openssl`, `ext-dom`, `ext-libxml`
- The **`openssl` CLI** — `tests/bootstrap.php` auto-generates a throwaway PKCS#12 certificate for the unit test suite on first run; no real certificate is required to run tests
- **Composer**

## Setup

```bash
git clone https://github.com/cstea/factura-electronica.git
cd factura-electronica
composer install
```

## Running tests

```bash
vendor/bin/phpunit
```

The suite uses **synthetic data only**. Never commit real cédulas, certificates, private keys, signatures, or invoice XML to fixtures or examples — all test data must use fake or placeholder values.

### Gated sandbox tests

Three integration tests (`SandboxEmitTest`, `SandboxFecTest`, `SandboxMrTest`) fire real HTTP requests against Hacienda's `api-stag` environment. They are skipped by default and only run when the following environment variables are set:

```bash
FE_SANDBOX=1 \
FE_USERNAME=your@email.cr \
FE_PASSWORD=yourPassword \
FE_PIN=1234 \
FE_P12_PATH=/absolute/path/to/cert.p12 \
vendor/bin/phpunit tests/Feature/SandboxEmitTest.php
```

These tests never run in CI and do not block merging.

## Static analysis

```bash
vendor/bin/phpstan analyse --no-progress --memory-limit 512M
```

Configured in `phpstan.neon` at **level 4** (no baseline). All errors must be resolved before a PR is merged.

## Code style

Fix style automatically:

```bash
vendor/bin/pint
```

Check without modifying:

```bash
vendor/bin/pint --test
```

Style is enforced by the `laravel` Pint preset (`pint.json`).

## CI

Every push and pull request runs three checks (all must pass):

1. **PHPUnit** — `vendor/bin/phpunit`
2. **Pint** — `vendor/bin/pint --test`
3. **PHPStan** — `vendor/bin/phpstan analyse --no-progress --memory-limit 512M`

Run all three locally before opening a PR.

## Pull request guidelines

- **Branch** off `main` and keep PRs focused on a single concern.
- **Add or extend tests** for any change to `src/`. The suite covers happy paths, failure paths, and edge cases — maintain that coverage.
- **Follow existing conventions**: typed signatures everywhere, `readonly` DTOs, XML builders own all XSD knowledge (callers pass DTOs, not raw strings), exceptions extend `FacturaElectronicaException`.
- Run `vendor/bin/pint` (not `--test`) before committing so style fixes are already applied.
- PRs that introduce real cédulas, certificates, or invoice data will be rejected — use synthetic values only.
