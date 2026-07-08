<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Tests\Unit;

use Elven\Observability\Php\Config\EnvConfigResolver;
use PHPUnit\Framework\TestCase;

final class EnvConfigResolverTest extends TestCase
{
    public function testExplicitConfigWinsOverEnv(): void
    {
        $_SERVER['OTEL_SERVICE_NAME'] = 'from-env';

        $config = (new EnvConfigResolver())->resolve([
            'service_name' => 'from-explicit',
            'enabled' => true,
        ]);

        self::assertSame('from-explicit', $config->serviceName);
        unset($_SERVER['OTEL_SERVICE_NAME']);
    }

    public function testDefaultIsDisabledUntilExplicitOptIn(): void
    {
        $config = (new EnvConfigResolver())->resolve([]);

        self::assertFalse($config->enabled);
    }

    public function testSdkDisabledKillsEverything(): void
    {
        $_SERVER['OTEL_SDK_DISABLED'] = 'true';

        $config = (new EnvConfigResolver())->resolve(['enabled' => true]);

        self::assertFalse($config->enabled);
        unset($_SERVER['OTEL_SDK_DISABLED']);
    }

    public function testResourceAttributesAreParsed(): void
    {
        $_SERVER['OTEL_RESOURCE_ATTRIBUTES'] = 'client=kontik,tenant=demo%20tenant';

        $config = (new EnvConfigResolver())->resolve([]);

        self::assertSame('kontik', $config->resourceAttributes['client']);
        self::assertSame('demo tenant', $config->resourceAttributes['tenant']);
        unset($_SERVER['OTEL_RESOURCE_ATTRIBUTES']);
    }

    public function testServiceNameCanComeFromResourceAttributes(): void
    {
        $_SERVER['OTEL_RESOURCE_ATTRIBUTES'] = 'service.name=checkout-api,client=demo';

        $config = (new EnvConfigResolver())->resolve([]);

        self::assertSame('checkout-api', $config->serviceName);
        unset($_SERVER['OTEL_RESOURCE_ATTRIBUTES']);
    }

    public function testBatchAndTimeoutAliasesAreParsed(): void
    {
        $_SERVER['ELVEN_OTEL_EXPORT_TIMEOUT_MS'] = '250';
        $_SERVER['OTEL_BSP_MAX_QUEUE_SIZE'] = '32';
        $_SERVER['OTEL_BSP_MAX_EXPORT_BATCH_SIZE'] = '64';
        $_SERVER['OTEL_BLRP_MAX_QUEUE_SIZE'] = '16';
        $_SERVER['OTEL_BLRP_MAX_EXPORT_BATCH_SIZE'] = '8';
        $_SERVER['OTEL_PROPAGATORS'] = 'tracecontext';

        $config = (new EnvConfigResolver())->resolve([]);

        self::assertSame(250, $config->timeoutMillis);
        self::assertSame(32, $config->spanProcessorMaxQueueSize);
        self::assertSame(32, $config->spanProcessorMaxExportBatchSize);
        self::assertSame(16, $config->logProcessorMaxQueueSize);
        self::assertSame(8, $config->logProcessorMaxExportBatchSize);
        self::assertSame(['tracecontext'], $config->propagators);

        unset(
            $_SERVER['ELVEN_OTEL_EXPORT_TIMEOUT_MS'],
            $_SERVER['OTEL_BSP_MAX_QUEUE_SIZE'],
            $_SERVER['OTEL_BSP_MAX_EXPORT_BATCH_SIZE'],
            $_SERVER['OTEL_BLRP_MAX_QUEUE_SIZE'],
            $_SERVER['OTEL_BLRP_MAX_EXPORT_BATCH_SIZE'],
            $_SERVER['OTEL_PROPAGATORS'],
        );
    }
}
