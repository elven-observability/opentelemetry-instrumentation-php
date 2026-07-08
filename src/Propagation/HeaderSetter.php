<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Propagation;

use OpenTelemetry\Context\Propagation\PropagationSetterInterface;

final class HeaderSetter implements PropagationSetterInterface
{
    /** @param mixed $carrier */
    public function set(&$carrier, string $key, string $value): void
    {
        if (!is_array($carrier)) {
            $carrier = [];
        }

        $carrier[$key] = $value;
    }
}
