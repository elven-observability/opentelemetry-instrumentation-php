# Public-tag runtime qualification

This is a manually approved, test-only hosted Ubuntu profile for two immutable
Git/VCS distributions, not a package publication or a test of the workflow
checkout as the product:

| Package | Tag | Product commit |
| --- | --- | --- |
| elven-observability/opentelemetry-instrumentation-php | v0.2.0-rc.1 | 4b583db8aecc741e2553820c814ac83deeb4690c |
| elven-observability/opentelemetry-instrumentation-php-legacy | v0.8.0-rc.1 | 8cbdef82827a34bc9e2e059264e9d2ae86dd9b00 |

The fixtures contain only clean Composer manifests/locks and the expected 49/69
own-source hashes. The synthetic consumer is unchanged. Composer installs the
locked public graphs into a fresh private workspace with plugins/scripts off,
empty authentication and no user/system Git configuration. It does not update
dependencies, invoke the product checkout's Composer scripts, or publish anything.

The fixed 12 cases are modern/legacy, 1/10 operations, three repetitions each.
All three decoded OTLP signals are required: traces, metrics and logs, including
the operation-counter sum, forceFlush phase, RC metadata and synthetic-secret
absence controls. The default library export budgets/retry settings are retained.

This profile selects PHP 8.5.12, Node 26.13.0 and Composer 2.11.0 on hosted
Ubuntu 24.04. It is not the local Docker ARM64 profile and must not borrow its
binary hashes, runtime observations, resource caps or failure results. Actual
binary/version/runner/control-source bindings are recorded per run. There is no
claim of a 512 MiB/2-CPU Docker-equivalent limit or a strict overhead budget.

The workflow is manual-only, confirmation defaults false, permissions are
contents-read, and checkout credentials are not persisted. GitHub's manual event
requires a workflow definition on the default branch. Source review, any parent
activation/merge and explicit execution approval are separate gates. This file
does not authorize any of them. Existing CI can still run on a later PR under
its existing rules; this new 12-case job has no push/PR trigger.

Only four explicitly named JSON metadata receipts may be uploaded. Vendor trees,
Git metadata, HOME/cache, raw Composer logs, raw exported payloads and credentials
must never be uploaded. A failed install/registry access is not bypassed with
authentication, insecure TLS, ignored platform requirements or graph updates.

Normal CLI delivery checks are not exploit tests or FPM/mod_php/Windows/all-version
security qualification. PHP 8.5.12 is an upstream fix level for CVE-2026-109618 and
CVE-2026-109619, not a zero-vulnerability guarantee; the conditional session issue
and bundled-library advisories remain separate. Old PHP 8.5.10 CLI receipts remain
valid scoped delivery evidence; their vendor-backport status is not inferred.

Authoritative documentation: https://docs.elven.works and its Git source
https://github.com/elvenworks-ps/docs.elven.works. Current official runtime notices:
https://www.php.net/index.php.
