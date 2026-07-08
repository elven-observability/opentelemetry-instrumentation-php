<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Tests\Unit;

use Elven\Observability\Php\Config\EnvConfigResolver;
use Elven\Observability\Php\Privacy\AttributeRedactor;
use Elven\Observability\Php\Privacy\DbStatementSanitizer;
use Elven\Observability\Php\Privacy\UrlSanitizer;
use PHPUnit\Framework\TestCase;

final class PrivacyTest extends TestCase
{
    public function testRedactsSensitiveValues(): void
    {
        $config = (new EnvConfigResolver())->resolve([]);
        $redactor = new AttributeRedactor($config);

        $attrs = $redactor->attributes([
            'Authorization' => 'Bearer abc.def.ghi',
            'email' => 'person@example.com',
            'safe' => 'value',
        ]);

        self::assertSame('[REDACTED]', $attrs['Authorization']);
        self::assertSame('[REDACTED_EMAIL]', $attrs['email']);
        self::assertSame('value', $attrs['safe']);
    }

    public function testRedactionCanBeDisabled(): void
    {
        $config = (new EnvConfigResolver())->resolve(['redaction_enabled' => false]);
        $redactor = new AttributeRedactor($config);

        self::assertSame('Bearer abc.def.ghi', $redactor->value('Authorization', 'Bearer abc.def.ghi'));
    }

    public function testSanitizesUrlQuery(): void
    {
        $url = (new UrlSanitizer())->sanitize('/search?token=abc&origin=CGH');

        self::assertSame('/search?token=%5BREDACTED%5D&origin=CGH', $url);
    }

    public function testSanitizesDbStatement(): void
    {
        $sanitizer = new DbStatementSanitizer();

        self::assertSame('SELECT', $sanitizer->summary("select * from users where email='a@b.com' and id=123"));
        self::assertStringNotContainsString('a@b.com', $sanitizer->sanitize("select * from users where email='a@b.com' and id=123"));
    }
}
