<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Integrations\Symfony;

use Elven\Observability\Php\Observability;
use Elven\Observability\Php\Tracing\SafeSpan;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

final class HttpKernelSubscriber implements EventSubscriberInterface
{
    private ?SafeSpan $span = null;
    private ?ScopeInterface $scope = null;
    private int $start = 0;
    private ?string $route = null;
    private string $method = 'GET';

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 4096],
            KernelEvents::RESPONSE => ['onResponse', -4096],
            KernelEvents::EXCEPTION => ['onException', 0],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $this->finish(null);
        $request = $event->getRequest();
        $headers = [];
        foreach ($request->headers->all() as $key => $values) {
            $headers[$key] = implode(',', $values);
        }
        try {
            $this->scope = Observability::propagator()->extract($headers)->activate();
        } catch (Throwable) {
            $this->scope = null;
        }
        $route = $request->attributes->get('_route');
        $this->route = is_string($route) && $route !== '' ? $route : null;
        $this->method = strtoupper($request->getMethod());
        $this->start = hrtime(true);
        $attributes = [
            'http.request.method' => $this->method,
            'url.path' => $request->getPathInfo(),
            'url.scheme' => $request->getScheme(),
            'server.address' => $request->getHost(),
            'user_agent.original' => (string) $request->headers->get('user-agent', ''),
        ];
        if ($this->route !== null) {
            $attributes['http.route'] = $this->route;
        }
        $this->span = Observability::tracer()->span(
            $this->route === null ? $this->method : $this->method . ' ' . $this->route,
            SpanKind::KIND_SERVER,
            $attributes,
        );
    }

    public function onException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        $this->span?->recordException($throwable);
        $this->span?->setStatus(StatusCode::STATUS_ERROR, $throwable::class);
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $status = $event->getResponse()->getStatusCode();
        $this->finish($status);
    }

    private function finish(?int $status): void
    {
        if ($this->span === null && $this->scope === null) {
            return;
        }
        $status ??= 500;
        $this->span?->setAttribute('http.response.status_code', $status);
        if ($status >= 500) {
            $this->span?->setStatus(StatusCode::STATUS_ERROR, (string) $status);
        }
        Observability::metrics()->histogram('http.server.request.duration', 's')->record(
            $this->start > 0 ? (hrtime(true) - $this->start) / 1_000_000_000 : 0.0,
            $this->metricAttributes($status),
        );
        $this->span?->end();
        $this->span = null;
        try {
            $this->scope?->detach();
        } catch (Throwable) {
        }
        $this->scope = null;
        $this->route = null;
        $this->start = 0;
    }

    /** @return array<string, mixed> */
    private function metricAttributes(int $status): array
    {
        $attributes = [
            'http.request.method' => $this->method,
            'http.response.status_code' => $status,
        ];
        if ($this->route !== null) {
            $attributes['http.route'] = $this->route;
        }

        return $attributes;
    }
}
