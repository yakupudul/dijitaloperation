<?php

namespace App\Support\Skills;

/**
 * Deterministic fingerprint over material Skill Definition contract fields.
 */
final class SkillDefinitionFingerprint
{
    /**
     * @param  array<string, mixed>  $material
     */
    public static function hash(array $material): string
    {
        $canonical = self::canonicalize($material);

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>|list<mixed>|string|int|float|bool|null
     */
    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map([self::class, 'canonicalize'], $value);
        }

        ksort($value);
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = self::canonicalize($item);
        }

        return $out;
    }
}
