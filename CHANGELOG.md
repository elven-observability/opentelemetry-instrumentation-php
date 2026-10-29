# Changelog

## 0.1.1 - Unreleased Candidate

- Bound flush/shutdown I/O with one shared deadline, at most three retries and capped Retry-After waits.
- Bound queue/metric-series admission, serialized payload acceptance and HTTP headers/responses; preserve TLS verification and refuse redirects.
- Fix circuit-future accounting, exception/URL privacy, recursive values and emergency disable precedence without replacing the application log writer.
- Add stalled/drip/oversized/TLS collectors and byte-budget tests. VCS is the verified distribution method; v0.1.1 awaits parent approval/publication.
- Metric overflow/drop policies and omission of raw exception stacks are intentional telemetry behavior changes; review README limits before rollout.

## 0.1.0 - 2026-07-08

- Initial modern PHP 8.2+ observability package.
- Added OpenTelemetry SDK/OTLP bootstrap.
- Added traces, metrics and OTLP log support.
- Added Laravel, Symfony, PSR-15, FrankenPHP and Guzzle integrations.
- Added privacy/redaction defaults.
- Added fake collector, Docker validation, CI and tests.
