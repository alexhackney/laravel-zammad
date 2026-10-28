<?php

declare(strict_types=1);

namespace AlexHackney\Zammad\Data;

trait NormalizesJsonScalars
{
    private static function jsonInt(mixed $value): int
    {
        // Match weak scalar coercion without turning invalid strings into zero.
        if (is_string($value) && is_numeric($value)) {
            $value += 0;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_bool($value) || (is_float($value) && $value >= PHP_INT_MIN && $value < -PHP_INT_MIN)) {
            return (int) $value;
        }

        throw new \TypeError('Expected an integer or a scalar coercible to an integer.');
    }

    private static function jsonString(mixed $value): string
    {
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        throw new \TypeError('Expected a string, Stringable, or a scalar coercible to a string.');
    }

    private static function jsonBool(mixed $value): bool
    {
        if (is_scalar($value)) {
            return (bool) $value;
        }

        throw new \TypeError('Expected a boolean or a scalar coercible to a boolean.');
    }
}
