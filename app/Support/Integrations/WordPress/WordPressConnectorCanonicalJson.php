<?php

namespace App\Support\Integrations\WordPress;

final class WordPressConnectorCanonicalJson
{
    public static function encode(mixed $value): string
    {
        return json_encode(
            self::normalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            // A decoded JSON object: empty stays {}, otherwise it is signed like an associative array.
            $value = (array) $value;
            if ($value === []) {
                return new \stdClass;
            }
            ksort($value, SORT_STRING);

            return (object) array_map(self::normalize(...), $value);
        }
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }

        return $value;
    }
}
