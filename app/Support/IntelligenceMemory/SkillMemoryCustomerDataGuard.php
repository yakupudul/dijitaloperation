<?php

namespace App\Support\IntelligenceMemory;

/**
 * Skill Memory must remain customer-free.
 */
final class SkillMemoryCustomerDataGuard
{
    /**
     * @var list<string>
     */
    private const FORBIDDEN_KEYS = [
        'customer_id',
        'brand_id',
        'customer_ids',
        'brand_ids',
        'customer_name',
        'brand_name',
        'domain',
        'url',
        'website_url',
        'campaign_name',
        'keyword',
        'performance_value',
        'spend',
        'leads',
        'notes',
        'request_text',
        'review_text',
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function assertNoCustomerOrBrandIdentifiers(array $payload): void
    {
        foreach (self::FORBIDDEN_KEYS as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== null && $payload[$key] !== '') {
                throw new \InvalidArgumentException(
                    "Skill Memory payload must not contain customer-specific key [{$key}]."
                );
            }
        }

        foreach ($payload as $value) {
            if (! is_string($value)) {
                continue;
            }
            if (preg_match('/\bcustomer_id\s*[:=]\s*\d+/i', $value) === 1
                || preg_match('/\bbrand_id\s*[:=]\s*\d+/i', $value) === 1) {
                throw new \InvalidArgumentException(
                    'Skill Memory payload must not embed customer_id/brand_id literals.'
                );
            }
        }
    }

    public function isForbiddenKey(string $key): bool
    {
        return in_array($key, self::FORBIDDEN_KEYS, true);
    }
}
