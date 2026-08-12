<?php

namespace App\Support\Options;

/**
 * BCP-47-ish language codes for Brand / Website MultiSelect fields.
 */
final class LanguageOptions
{
    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'tr' => 'Turkish',
            'en' => 'English',
            'de' => 'German',
            'fr' => 'French',
            'es' => 'Spanish',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'nl' => 'Dutch',
            'ru' => 'Russian',
            'ar' => 'Arabic',
            'fa' => 'Persian',
            'az' => 'Azerbaijani',
            'ku' => 'Kurdish',
            'zh' => 'Chinese',
            'ja' => 'Japanese',
            'ko' => 'Korean',
            'hi' => 'Hindi',
            'pl' => 'Polish',
            'ro' => 'Romanian',
            'bg' => 'Bulgarian',
            'el' => 'Greek',
            'sv' => 'Swedish',
            'no' => 'Norwegian',
            'da' => 'Danish',
            'fi' => 'Finnish',
            'cs' => 'Czech',
            'hu' => 'Hungarian',
            'uk' => 'Ukrainian',
        ];
    }

    public static function label(?string $code): string
    {
        if ($code === null || $code === '') {
            return '—';
        }

        return self::options()[$code] ?? $code;
    }

    /**
     * @param  list<string>|null  $codes
     * @return list<string>
     */
    public static function labels(?array $codes): array
    {
        if ($codes === null || $codes === []) {
            return [];
        }

        return array_values(array_map(
            static fn (string $code): string => self::label($code),
            $codes
        ));
    }
}
