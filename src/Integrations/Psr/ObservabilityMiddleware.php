<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Integrations\Psr;

use Elven\Observability\Php\Instrumentation\HttpServerInstrumentation;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ObservabilityMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly HttpServerInstrumentation $instrumentation = new HttpServerInstrumentation())
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->instrumentation->handle($request, static fn (): ResponseInterface => $handler->handle($request));
    }
}
