# Compatibility

## Validated PHP Versions

Local validation ran on:

- PHP 8.2.32
- PHP 8.3.32
- PHP 8.4.23
- PHP 8.5.8

## Dependency Decisions

The package uses direct production dependencies, not the `open-telemetry/opentelemetry` metapackage.
The library does not commit a `composer.lock` file and does not pin `config.platform.php` in `composer.json`;
CI resolves dependencies on each target PHP version.

Core dependencies:

- `open-telemetry/api`
- `open-telemetry/sdk`
- `open-telemetry/exporter-otlp`
- `open-telemetry/sem-conv`
- `open-telemetry/opentelemetry-logger-monolog`

Validation includes a PHP 8.2 `--prefer-lowest` solve to catch optimistic minimum constraints.

## Optional Native Auto-Instrumentation

The upstream `open-telemetry/opentelemetry-auto-*` packages require `ext-opentelemetry`.

This package does not require the extension. It provides Composer/framework-level integrations first, and documents native auto-instrumentation as optional.
