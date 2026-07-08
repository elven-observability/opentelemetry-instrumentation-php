<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Logs;

use Elven\Observability\Php\Config\ObservabilityConfig;
use Elven\Observability\Php\Privacy\AttributeRedactor;
use Monolog\Processor\ProcessorInterface;
use OpenTelemetry\API\Logs\Severity;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use Throwable;

final class LogFacade
{
    public function __construct(
        private readonly ObservabilityConfig $config,
        private readonly ?LoggerProviderInterface $loggerProvider,
        private readonly AttributeRedactor $redactor,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function correlate(array $context = []): array
    {
        if (!$this->config->logCorrelationEnabled) {
            return $context;
        }

        $spanContext = Span::getCurrent()->getContext();
        if ($spanContext->isValid()) {
            $context['trace_id'] = $spanContext->getTraceId();
            $context['span_id'] = $spanContext->getSpanId();
            $context['trace_flags'] = sprintf('%02x', $spanContext->getTraceFlags());
        }

        $context['service_name'] = $this->config->serviceName;
        $context['environment'] = $this->config->environment;
        $context['hostname'] = gethostname() ?: php_uname('n');

        return $this->redactor->attributes($context);
    }

    public function monologProcessor(): ProcessorInterface
    {
        return new MonologTraceProcessor($this);
    }

    /** @param array<string, mixed> $attributes */
    public function emit(string $message, string $level = 'info', array $attributes = []): void
    {
        if ($this->loggerProvider === null) {
            return;
        }

        try {
            $severity = Severity::fromPsr3($level);
            $this->loggerProvider
                ->getLogger('elven-observability-php')
                ->logRecordBuilder()
                ->setTimestamp((int) (microtime(true) * 1_000_000_000))
                ->setObservedTimestamp((int) (microtime(true) * 1_000_000_000))
                ->setSeverityNumber($severity)
                ->setSeverityText(strtoupper($level))
                ->setBody($this->redactor->redactString($message))
                ->setAttributes($this->redactor->attributes($this->correlate($attributes)))
                ->emit();
        } catch (Throwable) {
        }
    }

    public function loggerProvider(): ?LoggerProviderInterface
    {
        return $this->loggerProvider;
    }
}
