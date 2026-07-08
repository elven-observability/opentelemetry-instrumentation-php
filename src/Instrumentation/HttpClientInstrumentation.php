<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Instrumentation;

use Elven\Observability\Php\Observability;
use Elven\Observability\Php\Privacy\UrlSanitizer;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Throwable;

final class HttpClientInstrumentation
{
    public function __construct(private readonly UrlSanitizer $urlSanitizer = new UrlSanitizer())
    {
    }

    /**
     * @template T
     * @param array<string, mixed> $headers
     * @param callable(array<string, mixed>): T $callback
     * @return T
     */
    public function request(string $method, string $url, array $headers, callable $callback): mixed
    {
        $method = strtoupper($method);
        $host = $this->urlSanitizer->host($url) ?? 'unknown';
        $path = $this->urlSanitizer->path($url);
        $start = hrtime(true);

        return Observability::tracer()->withSpan(
            $method . ' ' . $host,
            function ($span) use ($callback, &$headers, $method, $url, $host, $path, $start) {
                Observability::propagator()->inject($headers);
                $span->setAttributes([
                    'http.request.method' => $method,
                    'server.address' => $host,
                    'url.path' => $path,
                    'url.full' => $this->urlSanitizer->sanitize($url),
                ]);

                try {
                    $result = $callback($headers);
                    $status = $this->statusCode($result);
                    if ($status !== null) {
                        $span->setAttribute('http.response.status_code', $status);
                        if ($status >= 400) {
                            $span->setStatus(StatusCode::STATUS_ERROR, (string) $status);
                            $span->setAttribute('error.type', 'http.status_code.' . $status);
                        }
                    }
                    $this->recordDependency('http', $host, $method, $status, $start);

                    return $result;
                } catch (Throwable $throwable) {
                    $span->recordException($throwable);
                    $span->setStatus(StatusCode::STATUS_ERROR, $throwable::class);
                    $this->recordDependency('http', $host, $method, null, $start, $throwable);
                    throw $throwable;
                }
            },
            SpanKind::KIND_CLIENT,
        );
    }

    private function statusCode(mixed $result): ?int
    {
        if (is_object($result) && method_exists($result, 'getStatusCode')) {
            return (int) $result->getStatusCode();
        }
        if (is_array($result) && isset($result['status_code'])) {
            return (int) $result['status_code'];
        }

        return null;
    }

    private function recordDependency(
        string $type,
        string $name,
        string $operation,
        ?int $status,
        int $start,
        ?Throwable $throwable = null,
    ): void {
        $attributes = [
            'dependency.type' => $type,
            'dependency.name' => $name,
            'operation' => strtolower($operation),
        ];
        if ($status !== null) {
            $attributes['http.response.status_code'] = $status;
        }
        if ($throwable !== null) {
            $attributes['error.type'] = $this->errorType($throwable);
        }
        Observability::metrics()->histogram('http.client.request.duration', 's')->record(
            (hrtime(true) - $start) / 1_000_000_000,
            array_filter([
                'server.address' => $name,
                'http.request.method' => $operation,
                'http.response.status_code' => $status,
                'error.type' => $throwable !== null ? $this->errorType($throwable) : null,
            ], static fn (mixed $value): bool => $value !== null),
        );
        Observability::metrics()->histogram('elven.php.dependency.duration', 's')->record(
            (hrtime(true) - $start) / 1_000_000_000,
            $attributes,
        );
    }

    private function errorType(Throwable $throwable): string
    {
        $parts = explode('\\', $throwable::class);

        return end($parts) ?: 'Throwable';
    }
}
