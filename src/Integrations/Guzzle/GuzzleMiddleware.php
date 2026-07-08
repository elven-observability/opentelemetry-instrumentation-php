<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Integrations\Guzzle;

use Elven\Observability\Php\Observability;
use Elven\Observability\Php\Privacy\UrlSanitizer;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class GuzzleMiddleware
{
    public static function middleware(): callable
    {
        $sanitizer = new UrlSanitizer();

        return static function (callable $handler) use ($sanitizer): callable {
            return static function (RequestInterface $request, array $options) use ($handler, $sanitizer): PromiseInterface {
                $url = (string) $request->getUri();
                $host = $request->getUri()->getHost() ?: 'unknown';
                $method = strtoupper($request->getMethod());
                $start = hrtime(true);
                $span = Observability::tracer()->span($method . ' ' . $host, SpanKind::KIND_CLIENT, [
                    'http.request.method' => $method,
                    'server.address' => $host,
                    'url.path' => $sanitizer->path($url),
                    'url.full' => $sanitizer->sanitize($url),
                ]);
                $headers = [];
                Observability::propagator()->inject($headers);
                foreach ($headers as $key => $value) {
                    $request = $request->withHeader($key, $value);
                }
                $span->detachScope();

                try {
                    return $handler($request, $options)->then(
                        static function (ResponseInterface $response) use ($span, $host, $method, $start): ResponseInterface {
                            $status = $response->getStatusCode();
                            $span->setAttribute('http.response.status_code', $status);
                            if ($status >= 400) {
                                $span->setStatus(StatusCode::STATUS_ERROR, (string) $status);
                                $span->setAttribute('error.type', 'http.status_code.' . $status);
                            }
                            self::recordHttpClientMetric($host, $method, $status, $start);
                            Observability::metrics()->histogram('elven.php.dependency.duration', 's')->record(
                                (hrtime(true) - $start) / 1_000_000_000,
                                [
                                    'dependency.type' => 'http',
                                    'dependency.name' => $host,
                                    'operation' => strtolower($method),
                                    'http.response.status_code' => $status,
                                ],
                            );
                            $span->end();

                            return $response;
                        },
                        static function (mixed $reason) use ($span, $host, $method, $start): mixed {
                            if ($reason instanceof Throwable) {
                                $span->recordException($reason);
                                $span->setStatus(StatusCode::STATUS_ERROR, $reason::class);
                            } else {
                                $span->setStatus(StatusCode::STATUS_ERROR, 'guzzle_request_rejected');
                            }
                            $errorType = $reason instanceof Throwable ? self::errorType($reason) : 'PromiseRejected';
                            self::recordHttpClientMetric($host, $method, null, $start, $errorType);
                            Observability::metrics()->histogram('elven.php.dependency.duration', 's')->record(
                                (hrtime(true) - $start) / 1_000_000_000,
                                [
                                    'dependency.type' => 'http',
                                    'dependency.name' => $host,
                                    'operation' => strtolower($method),
                                    'error.type' => $errorType,
                                ],
                            );
                            $span->end();

                            return Create::rejectionFor($reason);
                        },
                    );
                } catch (Throwable $throwable) {
                    $span->recordException($throwable);
                    $span->setStatus(StatusCode::STATUS_ERROR, $throwable::class);
                    $span->end();
                    throw $throwable;
                }
            };
        };
    }

    private static function errorType(Throwable $throwable): string
    {
        $parts = explode('\\', $throwable::class);

        return end($parts) ?: 'Throwable';
    }

    private static function recordHttpClientMetric(
        string $host,
        string $method,
        ?int $status,
        int $start,
        ?string $errorType = null,
    ): void {
        $attributes = [
            'server.address' => $host,
            'http.request.method' => $method,
        ];
        if ($status !== null) {
            $attributes['http.response.status_code'] = $status;
        }
        if ($errorType !== null) {
            $attributes['error.type'] = $errorType;
        }

        Observability::metrics()->histogram('http.client.request.duration', 's')->record(
            (hrtime(true) - $start) / 1_000_000_000,
            $attributes,
        );
    }
}
