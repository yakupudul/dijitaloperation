<?php

namespace App\Support\Options;

final class ContactRoleOptions
{
    public const string OTHER = 'other';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'owner' => 'Owner / Founder',
            'general_manager' => 'General Manager',
            'marketing' => 'Marketing',
            'sales' => 'Sales',
            'finance' => 'Finance',
            'it' => 'IT / Technical',
            'operations' => 'Operations',
            'assistant' => 'Assistant',
            self::OTHER => 'Other',
        ];
    }

    public static function label(?string $code): string
    {
        if ($code === null || $code === '') {
            return '—';
        }

        return self::options()[$code] ?? $code;
    }
}
