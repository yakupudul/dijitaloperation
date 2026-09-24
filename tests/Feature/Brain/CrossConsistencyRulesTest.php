<?php

namespace Tests\Feature\Brain;

use App\Services\Advisor\Cross\CrossChannelRuleEngine;
use Tests\TestCase;

/**
 * Faz 7d: cross-asset consistency (Business Profile ↔ site, NAP phone, ad destinations) as advisor items.
 */
final class CrossConsistencyRulesTest extends TestCase
{
    /** @param array<string, mixed> $consistency */
    private function input(array $consistency): array
    {
        return [
            'bound' => true,
            'asset' => ['id' => 1, 'name' => 'ornekklinik.com', 'brand_name' => 'Örnek Klinik'],
            'currency' => 'TRY',
            'period' => ['end' => '2026-09-20', 'days' => 90],
            'ads_terms' => [],
            'gbp_keywords' => [],
            'gsc_available' => false,
            'gsc_queries' => [],
            'pages' => [],
            'consistency' => $consistency,
        ];
    }

    public function test_mismatches_become_items_and_allowed_hosts_are_ignored(): void
    {
        $items = collect((new CrossChannelRuleEngine)->evaluate($this->input([
            'site_hosts' => ['ornekklinik.com'],
            'site_phones' => ['2125550000'],
            'site_phones_read' => true,
            'gbp_profiles' => [
                ['name' => 'Örnek Klinik Kadıköy', 'website_uri' => 'https://eski-alan.com/', 'phones' => ['+90 212 555 99 99']],
            ],
            'ads_landing_hosts' => ['eski-alan.com' => 120.0, 'wa.me' => 50.0, 'www.ornekklinik.com' => 900.0],
            'meta_hosts' => ['instagram.com' => 2, 'kampanya.net' => 1],
        ]))['items'])->keyBy('rule_id');

        $this->assertSame('high', $items['gbp-website-mismatch']['severity']);
        $this->assertTrue($items->has('nap-phone-mismatch'));
        $this->assertSame(['eski-alan.com'], array_keys($items['ads-landing-offsite']['evidence']['hosts']));
        $this->assertEquals(120.0, $items['ads-landing-offsite']['impact_amount']);
        $this->assertSame(['kampanya.net'], array_keys($items['meta-destination-offsite']['evidence']['hosts']));
    }

    public function test_consistent_brand_produces_no_items(): void
    {
        $items = (new CrossChannelRuleEngine)->evaluate($this->input([
            'site_hosts' => ['ornekklinik.com'],
            'site_phones' => ['2125550000'],
            'site_phones_read' => true,
            'gbp_profiles' => [['name' => 'Örnek Klinik', 'website_uri' => 'https://www.ornekklinik.com/?utm_source=gbp', 'phones' => ['0212 555 00 00']]],
            'ads_landing_hosts' => ['ornekklinik.com' => 500.0],
            'meta_hosts' => ['m.me' => 3],
        ]))['items'];

        $this->assertSame([], $items);
    }

    public function test_missing_profile_website_is_medium_and_unread_phones_are_not_compared(): void
    {
        $items = collect((new CrossChannelRuleEngine)->evaluate($this->input([
            'site_hosts' => ['ornekklinik.com'],
            'site_phones' => [],
            'site_phones_read' => false,
            'gbp_profiles' => [['name' => 'Örnek Klinik', 'website_uri' => null, 'phones' => ['0212 555 00 00']]],
            'ads_landing_hosts' => [],
            'meta_hosts' => [],
        ]))['items'])->keyBy('rule_id');

        $this->assertSame('medium', $items['gbp-website-mismatch']['severity']);
        $this->assertFalse($items->has('nap-phone-mismatch'));
    }
}
