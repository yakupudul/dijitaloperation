<?php

namespace App\Support\BrandIntelligence;

final class BusinessModelOptions
{
    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'services' => 'Services',
            'ecommerce' => 'E-commerce',
            'saas' => 'SaaS / software',
            'marketplace' => 'Marketplace',
            'healthcare_clinic' => 'Healthcare / clinic',
            'agency' => 'Agency',
            'other' => 'Other',
        ];
    }

    public static function label(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::options()[$value] ?? $value;
    }
}
