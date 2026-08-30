<?php

defined('ABSPATH') || exit;

final class MoxDOP_Connector_Canonical_JSON
{
    public static function encode($value)
    {
        $encoded = wp_json_encode(self::normalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($encoded)) {
            throw new RuntimeException('Unable to encode connector response.');
        }

        return $encoded;
    }

    private static function normalize($value)
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }

        return $value;
    }
}

