<?php

namespace App\Support\Options;

/**
 * Shared industry / sector catalog for Customer and Brand forms.
 * Stored values are stable codes; labels are operator-facing.
 */
final class IndustryOptions
{
    public const string OTHER = 'other';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'healthcare' => 'Healthcare',
            'dental' => 'Dental',
            'medical_aesthetics' => 'Medical Aesthetics / Plastic Surgery',
            'beauty' => 'Beauty / Personal Care',
            'retail' => 'Retail',
            'ecommerce' => 'E-commerce',
            'professional_services' => 'Professional Services',
            'real_estate' => 'Real Estate',
            'hospitality' => 'Hospitality / Travel',
            'fitness' => 'Fitness / Wellness',
            'education' => 'Education',
            'technology' => 'Technology / SaaS',
            'automotive' => 'Automotive',
            'food_beverage' => 'Food & Beverage',
            'finance' => 'Finance',
            'legal' => 'Legal',
            'manufacturing' => 'Manufacturing',
            'construction' => 'Construction',
            'media' => 'Media / Entertainment',
            'nonprofit' => 'Nonprofit',
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

    public static function isValid(?string $code): bool
    {
        return $code !== null && $code !== '' && array_key_exists($code, self::options());
    }
}
