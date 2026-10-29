# Optional 0.2 RC Migration

The intended release is `0.2.0-rc.1`, not a transparent `0.1.1` patch. It is not published merely because this file exists. PHP remains `>=8.2 <9.0`; no runtime floor is raised. Existing stable `^0.1` Composer constraints do not select this new minor prerelease. Opt in only after the immutable tag and your required profile are qualified.

## Transport Ownership

The built-in exporter uses its own bounded HTTP exchange. It does not discover or reuse an application's arbitrary PSR client, handler stack, client certificate customization, compressed-response handling or exotic proxy/resolver behavior. Existing applications relying on those customizations must qualify an equivalent supported configuration before migration; silently restoring an unbounded custom client is not a safety fix.

Verified ordinary profiles include direct HTTP, verified TLS/custom CA, Basic origin authentication, CLI/lowercase proxies, authenticated HTTPS CONNECT and selected NO_PROXY cases. These are scoped test results, not every client/platform combination. TLS stays verified and redirects remain disabled. Synchronous OS DNS cannot be preempted in the compatibility profile; strict resolver refusal is explicit opt-in.

## Telemetry Under Pressure

Queues and metric identities share an 8MiB logical admission budget, with 64KiB trace/log records and 4MiB total transmitted payload per flush. This does not bound total process RSS. New high-cardinality combinations can aggregate into a real overflow series or be dropped; instrument names are capped. Keep dimensions/names static and review dashboards using exact high-cardinality label combinations.

Flush/shutdown is synchronous and uses a shared cooperative deadline. Serialization can consume that deadline before the first HTTP send. Exhausted exports can be drained/dropped; failed DELTA metric collection is not durable or replayed automatically. Independent 10k-operation/50ms pressure tests lost all application signal exports, while low-volume and 1000ms controls delivered all three signals within their queue limits. Those observations are not timing guarantees. Do not use a path receipt alone as evidence that application measurements arrived.

## Adoption

Confirm the release tag, run a clean VCS install and `composer audit`, then test real decoded logs/traces/metrics, normal dimensions, proxy/TLS settings, slow/failed collector behavior and worker lifecycle in staging. Preserve the existing `^0.1` lock until accepted. No customer environment is changed by publication; disabling `ELVEN_OTEL_ENABLED` is a telemetry rollback, not a way to recover previously dropped data.
