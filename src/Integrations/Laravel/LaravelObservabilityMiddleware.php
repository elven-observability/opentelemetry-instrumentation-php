<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Integrations\Laravel;

use Elven\Observability\Php\Observability;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

final class LaravelObservabilityMiddleware
{
    public function handle($request, \Closure $next)
    {
        $headers = [];
        foreach ($request->headers->all() as $key => $values) {
            $headers[$key] = implode(',', $values);
        }
        $scope = $this->activateInboundContext($headers);
        $route = $this->route($request);
        $method = strtoupper((string) $request->getMethod());
        $start = hrtime(true);
        $attributes = [
            'http.request.method' => $method,
            'url.path' => (string) $request->getPathInfo(),
            'url.scheme' => (string) $request->getScheme(),
            'server.address' => (string) $request->getHost(),
            'user_agent.original' => (string) $request->headers->get('user-agent', ''),
        ];
        if ($route !== null) {
            $attributes['http.route'] = $route;
        }

        $span = Observability::tracer()->span($route === null ? $method : $method . ' ' . $route, SpanKind::KIND_SERVER, $attributes);

        try {
            $response = $next($request);
            $status = method_exists($response, 'getStatusCode') ? (int) $response->getStatusCode() : 200;
            $span->setAttribute('http.response.status_code', $status);
            if ($status >= 500) {
                $span->setStatus(StatusCode::STATUS_ERROR, (string) $status);
            }
            Observability::metrics()->histogram('http.server.request.duration', 's')->record(
                (hrtime(true) - $start) / 1_000_000_000,
                $this->metricAttributes($route, $method, $status),
            );

            return $response;
        } catch (Throwable $throwable) {
            $span->recordException($throwable);
            $span->setStatus(StatusCode::STATUS_ERROR, $throwable::class);
            $errorAttributes = [
                'http.request.method' => $method,
                'error.type' => self::errorType($throwable),
            ];
            if ($route !== null) {
                $errorAttributes['http.route'] = $route;
            }
            Observability::metrics()->counter('http.server.request.errors')->add(1, $errorAttributes);
            Observability::metrics()->histogram('http.server.request.duration', 's')->record(
                (hrtime(true) - $start) / 1_000_000_000,
                $this->metricAttributes($route, $method, 500),
            );
            throw $throwable;
        } finally {
            $span->end();
            $this->detachScope($scope);
        }
    }

    private function route($request): ?string
    {
        try {
            $route = $request->route();
            if ($route !== null && method_exists($route, 'uri')) {
                return '/' . ltrim((string) $route->uri(), '/');
            }
            if ($route !== null && method_exists($route, 'getName') && $route->getName()) {
                return (string) $route->getName();
            }
        } catch (Throwable) {
        }

        return null;
    }

    private static function errorType(Throwable $throwable): string
    {
        $parts = explode('\\', $throwable::class);

        return end($parts) ?: 'Throwable';
    }

    /** @param array<string, string> $headers */
    private function activateInboundContext(array $headers): ?ScopeInterface
    {
        try {
            return Observability::propagator()->extract($headers)->activate();
        } catch (Throwable) {
            return null;
        }
    }

    private function detachScope(?ScopeInterface $scope): void
    {
        try {
            $scope?->detach();
        } catch (Throwable) {
        }
    }

    /** @return array<string, mixed> */
    private function metricAttributes(?string $route, string $method, int $status): array
    {
        $attributes = [
            'http.request.method' => $method,
            'http.response.status_code' => $status,
        ];
        if ($route !== null) {
            $attributes['http.route'] = $route;
        }

        return $attributes;
    }
}
