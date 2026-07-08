<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Propagation;

use OpenTelemetry\Context\Propagation\PropagationGetterInterface;

final class HeaderGetter implements PropagationGetterInterface
{
    /** @param mixed $carrier */
    public function keys($carrier): array
    {
        if (!is_array($carrier)) {
            return [];
        }

        return array_map('strval', array_keys($carrier));
    }

    /** @param mixed $carrier */
    public function get($carrier, string $key): ?string
    {
        if (!is_array($carrier)) {
            return null;
        }

        foreach ($carrier as $carrierKey => $value) {
            if (strcasecmp((string) $carrierKey, $key) === 0) {
                if (is_array($value)) {
                    return isset($value[0]) ? (string) $value[0] : null;
                }

                return is_scalar($value) ? (string) $value : null;
            }
        }

        return null;
    }
}
