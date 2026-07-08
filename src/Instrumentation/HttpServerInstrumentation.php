<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Instrumentation;

use Elven\Observability\Php\Observability;
use Elven\Observability\Php\Privacy\UrlSanitizer;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final class HttpServerInstrumentation
{
    public function __construct(private readonly UrlSanitizer $urlSanitizer = new UrlSanitizer())
    {
    }

    /**
     * @template T of ResponseInterface
     * @param callable(): T $next
     * @return T
     */
    public function handle(ServerRequestInterface $request, callable $next, ?string $route = null): ResponseInterface
    {
        $headers = $this->headers($request);
        $scope = $this->activateInboundContext($headers);
        $route ??= $this->route($request);
        $method = strtoupper($request->getMethod());
        $name = $route === null ? $method : $method . ' ' . $route;
        $uri = (string) $request->getUri();
        $start = hrtime(true);
        $attributes = [
            'http.request.method' => $method,
            'url.path' => $this->urlSanitizer->path($uri),
            'url.scheme' => $request->getUri()->getScheme(),
            'server.address' => $request->getUri()->getHost(),
            'server.port' => $request->getUri()->getPort(),
            'user_agent.original' => $request->getHeaderLine('user-agent') ?: null,
        ];
        if ($route !== null) {
            $attributes['http.route'] = $route;
        }

        $span = Observability::tracer()->span($name, SpanKind::KIND_SERVER, $attributes);

        try {
            $response = $next();
            $status = $response->getStatusCode();
            $span->setAttribute('http.response.status_code', $status);
            if ($status >= 500) {
                $span->setStatus(StatusCode::STATUS_ERROR, (string) $status);
            }
            $this->recordMetrics($route, $method, $status, $start);

            return $response;
        } catch (Throwable $throwable) {
            $span->recordException($throwable);
            $span->setStatus(StatusCode::STATUS_ERROR, $throwable::class);
            $errorAttributes = [
                'http.request.method' => $method,
                'error.type' => $this->errorType($throwable),
            ];
            if ($route !== null) {
                $errorAttributes['http.route'] = $route;
            }
            Observability::metrics()->counter('http.server.request.errors')->add(1, $errorAttributes);
            $this->recordMetrics($route, $method, 500, $start);
            throw $throwable;
        } finally {
            $span->end();
            $this->detachScope($scope);
        }
    }

    private function recordMetrics(?string $route, string $method, int $status, int $start): void
    {
        $durationSeconds = (hrtime(true) - $start) / 1_000_000_000;
        $attributes = [
            'http.request.method' => $method,
            'http.response.status_code' => $status,
        ];
        if ($route !== null) {
            $attributes['http.route'] = $route;
        }
        Observability::metrics()->histogram('http.server.request.duration', 's')->record($durationSeconds, $attributes);
    }

    private function route(ServerRequestInterface $request): ?string
    {
        foreach (['http.route', 'route', 'route_pattern', 'routePattern'] as $key) {
            $value = $request->getAttribute($key);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private function headers(ServerRequestInterface $request): array
    {
        $headers = [];
        foreach ($request->getHeaders() as $key => $values) {
            $headers[$key] = implode(',', $values);
        }

        return $headers;
    }

    private function errorType(Throwable $throwable): string
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
}
