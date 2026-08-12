<?php

namespace App\Support\Options;

final class WebsiteTypeOptions
{
    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'corporate' => 'Corporate Website',
            'lead_generation' => 'Lead Generation',
            'ecommerce' => 'E-commerce',
            'blog' => 'Blog / Publisher',
            'landing_page' => 'Landing Page',
            'marketplace' => 'Marketplace',
            'portal' => 'Portal',
            'other' => 'Other',
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
