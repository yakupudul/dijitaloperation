<?php

namespace App\Services\Analysis\Support;

final class CollectedFactsJson
{
    /**
     * @return array<string, mixed>
     */
    public static function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
