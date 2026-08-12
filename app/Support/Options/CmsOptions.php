<?php

namespace App\Support\Options;

final class CmsOptions
{
    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'wordpress' => 'WordPress',
            'shopify' => 'Shopify',
            'woocommerce' => 'WooCommerce',
            'webflow' => 'Webflow',
            'wix' => 'Wix',
            'squarespace' => 'Squarespace',
            'magento' => 'Magento',
            'laravel' => 'Laravel',
            'nextjs' => 'Next.js',
            'custom' => 'Custom',
            'unknown' => 'Unknown / Other',
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
