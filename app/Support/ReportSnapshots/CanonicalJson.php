<?php

namespace App\Support\ReportSnapshots;

/**
 * Deterministic JSON canonicalization for fingerprints and checksums.
 */
final class CanonicalJson
{
    /**
     * @param  array<mixed>  $data
     */
    public static function encode(array $data): string
    {
        $normalized = self::normalize($data);
        $json = json_encode(
            $normalized,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        return $json;
    }

    public static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        if ($isList) {
            return array_map(static fn (mixed $item): mixed => self::normalize($item), $value);
        }

        ksort($value);
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = self::normalize($item);
        }

        return $out;
    }
}
