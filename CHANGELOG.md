# Changelog

## 0.2.0-rc.1 - Unreleased Optional Migration

- Replace the planned `0.1.1` patch with a new pre-1.0 minor RC. Existing stable `^0.1` consumers are not moved to the new transport/admission policy automatically. See MIGRATION.md; this is not a universally compatible PSR-client replacement.

- Bound flush/shutdown transfer I/O with one shared deadline and at most three retries; defer rather than shorten collector Retry-After backpressure.
- Restore compatible DNS, endpoint Basic auth and CLI proxy egress; strict resolver refusal is explicit opt-in. Synchronous OS DNS is outside a hard deadline in the default profile. TLS/redirect protections remain enabled.
- Fix SDK link/resource/scope byte accounting, real per-instrument metric overflow across DELTA exports, valid repeated headers and retained-size accounting on attribute replacement. Add independent-review regressions.
- Bound queue/metric-series admission, serialized payload acceptance and HTTP headers/responses; preserve TLS verification and refuse redirects.
- Fix circuit-future accounting, exception/URL privacy, recursive values and emergency disable precedence without replacing the application log writer.
- Add stalled/drip/oversized/TLS collectors and byte-budget tests. VCS is the verified distribution method; v0.2.0-rc.1 still awaits final qualification and immutable-tag publication.
- Metric overflow/drop policies and omission of raw exception stacks are intentional telemetry behavior changes; review README limits before rollout.

## 0.1.0 - 2026-07-08

- Initial modern PHP 8.2+ observability package.
- Added OpenTelemetry SDK/OTLP bootstrap.
- Added traces, metrics and OTLP log support.
- Added Laravel, Symfony, PSR-15, FrankenPHP and Guzzle integrations.
- Added privacy/redaction defaults.
- Added fake collector, Docker validation, CI and tests.
