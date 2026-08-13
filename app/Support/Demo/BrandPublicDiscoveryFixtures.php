<?php

namespace App\Support\Demo;

/**
 * Deterministic Brand Public Discovery fixtures (Observe → Compare → Review → Apply).
 * No crawler/scraper expansion. Observed ≠ canonical Brand Context.
 */
final class BrandPublicDiscoveryFixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function workspace(): array
    {
        return [
            'overview' => self::overview(),
            'observed_facts' => self::observedFacts(),
            'candidates' => self::candidates(),
            'conflicts' => self::conflicts(),
            'sources' => self::sources(),
            'history' => self::history(),
            'existing_offerings' => [
                ['id' => 'offering_implant', 'label' => 'Implant Treatment'],
                ['id' => 'offering_post_bariatric', 'label' => 'Post-bariatric dentistry'],
                ['id' => 'offering_smile', 'label' => 'Smile design'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function overview(): array
    {
        $facts = self::observedFacts();
        $candidates = collect(self::candidates());
        $conflicts = self::conflicts();
        $history = self::history();

        return [
            'observed_facts' => count($facts),
            'awaiting_review' => $candidates->where('status', 'pending')->count(),
            'conflicts' => count($conflicts),
            'accepted_recently' => $candidates->where('status', 'accepted')->count()
                + collect($history)->where('action', 'accepted')->count(),
            'public_identity' => [
                ['field' => 'Business name', 'state' => 'Match'],
                ['field' => 'Website', 'state' => 'Match'],
                ['field' => 'Phone', 'state' => 'Conflict'],
                ['field' => 'Address', 'state' => 'Match'],
                ['field' => 'Hours', 'state' => 'Needs review'],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function observedFacts(): array
    {
        return [
            [
                'id' => 'of-phone-gbp',
                'field' => 'Primary phone',
                'value' => '+90 312 000 00 00',
                'source' => 'Google Business Profile',
                'source_type' => 'gbp',
                'source_asset' => 'Atlas Dental Ankara GBP',
                'observed_at' => 'Aug 13 · 04:12',
                'provenance' => 'Provider',
                'category' => 'Contact',
            ],
            [
                'id' => 'of-phone-web',
                'field' => 'Primary phone',
                'value' => '+90 312 555 01 01',
                'source' => 'Atlas Dental Website',
                'source_type' => 'website',
                'source_asset' => 'atlasdental.example',
                'observed_at' => 'Aug 13 · 04:10',
                'provenance' => 'Website observation',
                'category' => 'Contact',
            ],
            [
                'id' => 'of-name-gbp',
                'field' => 'Public business name',
                'value' => 'Atlas Dental Ankara',
                'source' => 'Google Business Profile',
                'source_type' => 'gbp',
                'source_asset' => 'Atlas Dental Ankara GBP',
                'observed_at' => 'Aug 13 · 04:12',
                'provenance' => 'Provider',
                'category' => 'Identity',
            ],
            [
                'id' => 'of-address-gbp',
                'field' => 'Address',
                'value' => 'Çankaya, Ankara',
                'source' => 'Google Business Profile',
                'source_type' => 'gbp',
                'source_asset' => 'Atlas Dental Ankara GBP',
                'observed_at' => 'Aug 13 · 04:12',
                'provenance' => 'Provider',
                'category' => 'Location',
            ],
            [
                'id' => 'of-hours-gbp',
                'field' => 'Opening hours',
                'value' => 'Mon–Sat 09:00–18:30',
                'source' => 'Google Business Profile',
                'source_type' => 'gbp',
                'source_asset' => 'Atlas Dental Ankara GBP',
                'observed_at' => 'Aug 13 · 04:12',
                'provenance' => 'Provider',
                'category' => 'Opening information',
            ],
            [
                'id' => 'of-hours-web',
                'field' => 'Opening hours',
                'value' => 'Mon–Sat 09:00–19:00',
                'source' => 'Atlas Dental Website',
                'source_type' => 'website',
                'source_asset' => 'atlasdental.example',
                'observed_at' => 'Aug 13 · 04:10',
                'provenance' => 'Website observation',
                'category' => 'Opening information',
            ],
            [
                'id' => 'of-implant-web',
                'field' => 'Service listed',
                'value' => 'Dental Implant',
                'source' => 'Atlas Dental Website',
                'source_type' => 'website',
                'source_asset' => 'atlasdental.example',
                'observed_at' => 'Aug 13 · 04:10',
                'provenance' => 'Website observation',
                'category' => 'Offerings',
                'path' => '/implant',
            ],
            [
                'id' => 'of-implant-gbp',
                'field' => 'Service representation',
                'value' => 'Dental Implant (category/service signal incomplete)',
                'source' => 'Google Business Profile',
                'source_type' => 'gbp',
                'source_asset' => 'Atlas Dental Ankara GBP',
                'observed_at' => 'Aug 13 · 04:12',
                'provenance' => 'Provider',
                'category' => 'Offerings',
            ],
            [
                'id' => 'of-smile-web',
                'field' => 'Service listed',
                'value' => 'Smile Design',
                'source' => 'Atlas Dental Website',
                'source_type' => 'website',
                'source_asset' => 'atlasdental.example',
                'observed_at' => 'Aug 13 · 04:10',
                'provenance' => 'Website observation',
                'category' => 'Offerings',
                'path' => '/smile-design',
            ],
            [
                'id' => 'of-location-gbp',
                'field' => 'Location label',
                'value' => 'Çankaya',
                'source' => 'Google Business Profile',
                'source_type' => 'gbp',
                'source_asset' => 'Atlas Dental Ankara GBP',
                'observed_at' => 'Aug 13 · 04:12',
                'provenance' => 'Provider',
                'category' => 'Location',
            ],
            [
                'id' => 'of-category-gbp',
                'field' => 'Primary category',
                'value' => 'Dental clinic',
                'source' => 'Google Business Profile',
                'source_type' => 'gbp',
                'source_asset' => 'Atlas Dental Ankara GBP',
                'observed_at' => 'Aug 13 · 04:12',
                'provenance' => 'Provider',
                'category' => 'Categories',
            ],
            [
                'id' => 'of-lang-web',
                'field' => 'Languages',
                'value' => 'Turkish + English',
                'source' => 'Atlas Dental Website',
                'source_type' => 'website',
                'source_asset' => 'atlasdental.example',
                'observed_at' => 'Aug 13 · 04:10',
                'provenance' => 'Website observation',
                'category' => 'Languages',
            ],
        ];
    }

    /**
     * Seed candidates for DemoState (statuses mutable).
     *
     * @return list<array<string, mixed>>
     */
    public static function candidates(): array
    {
        return [
            [
                'id' => 'dc-offering-implant',
                'kind' => 'offering',
                'kind_label' => 'Offering candidate',
                'action_label' => 'Add Offering',
                'value' => 'Dental Implant',
                'observed' => 'Dental Implant',
                'type' => 'Offering',
                'sources' => ['Website', 'GBP'],
                'source' => 'Website + GBP',
                'retrieved' => 'Today',
                'status' => 'pending',
                'provenance' => 'Derived',
                'provenance_detail' => 'Observed on Website /implant and GBP service signals → derived Offering candidate',
                'current_context' => 'No exact matching Offering label (closest: Implant Treatment)',
                'suggested_action' => 'Map to existing Offering · Implant Treatment',
                'map_target_id' => 'offering_implant',
                'map_target_label' => 'Implant Treatment',
                'confidence' => null,
                'ai_assisted' => false,
            ],
            [
                'id' => 'dc-offering-smile',
                'kind' => 'offering',
                'kind_label' => 'Offering candidate',
                'action_label' => 'Add Offering',
                'value' => 'Smile Design',
                'observed' => 'Smile Design',
                'type' => 'Offering',
                'sources' => ['Website'],
                'source' => 'Website',
                'retrieved' => 'Today',
                'status' => 'pending',
                'provenance' => 'Observed',
                'provenance_detail' => 'Website explicitly lists Smile Design',
                'current_context' => 'Brand Context already has Smile design',
                'suggested_action' => 'Map to existing Offering · Smile design',
                'map_target_id' => 'offering_smile',
                'map_target_label' => 'Smile design',
                'confidence' => null,
                'ai_assisted' => false,
            ],
            [
                'id' => 'dc-location-cankaya',
                'kind' => 'location',
                'kind_label' => 'Brand Location candidate',
                'action_label' => 'Add Brand Location',
                'value' => 'Çankaya',
                'observed' => 'Çankaya',
                'type' => 'Location',
                'sources' => ['GBP'],
                'source' => 'GBP',
                'retrieved' => 'Today',
                'status' => 'pending',
                'provenance' => 'Provider',
                'provenance_detail' => 'GBP location label Çankaya',
                'current_context' => 'Not configured as stable Brand Location entity',
                'suggested_action' => 'Accept into Brand Context location notes (stable Location ID deferred)',
                'map_target_id' => null,
                'map_target_label' => null,
                'confidence' => null,
                'ai_assisted' => false,
            ],
            [
                'id' => 'dc-positioning',
                'kind' => 'positioning',
                'kind_label' => 'Positioning candidate',
                'action_label' => 'Update positioning',
                'value' => 'Specialist implant / post-bariatric dental care',
                'observed' => 'Homepage + /post-bariatric emphasis',
                'type' => 'Positioning',
                'sources' => ['Website'],
                'source' => 'Website',
                'retrieved' => 'Today',
                'status' => 'pending',
                'provenance' => 'AI-derived',
                'provenance_detail' => 'AI-derived interpretation of repeated public positioning statements',
                'current_context' => 'Brand Context already has similar positioning',
                'suggested_action' => 'Review — may already be covered',
                'map_target_id' => null,
                'map_target_label' => null,
                'confidence' => null,
                'ai_assisted' => true,
            ],
            [
                'id' => 'dc-comp-nova',
                'kind' => 'competitor',
                'kind_label' => 'Competitor candidate',
                'action_label' => 'Add competitor',
                'value' => 'Nova Dental Ankara',
                'observed' => 'Nova Dental Ankara',
                'type' => 'Competitor',
                'sources' => ['GBP local presence'],
                'source' => 'GBP Demo competitors',
                'retrieved' => 'Today',
                'status' => 'ignored',
                'ignore_reason' => 'irrelevant',
                'provenance' => 'Derived',
                'provenance_detail' => 'Observed in local presence comparison — not auto-accepted',
                'current_context' => 'Not in Brand Context competitors',
                'suggested_action' => 'Ignore unless operator confirms',
                'map_target_id' => null,
                'map_target_label' => null,
                'confidence' => null,
                'ai_assisted' => false,
            ],
            [
                'id' => 'dc-lang-accepted',
                'kind' => 'fact',
                'kind_label' => 'Language fact',
                'action_label' => 'Confirm languages',
                'value' => 'Turkish + English',
                'observed' => 'Turkish + English language switcher',
                'type' => 'Languages',
                'sources' => ['Website'],
                'source' => 'Website',
                'retrieved' => 'Yesterday',
                'status' => 'accepted',
                'provenance' => 'Website observation',
                'provenance_detail' => 'Accepted into Brand Context languages',
                'current_context' => 'Already listed in Brand Context',
                'suggested_action' => 'Accepted',
                'map_target_id' => null,
                'map_target_label' => null,
                'confidence' => null,
                'ai_assisted' => false,
                'accepted_by' => 'Yakup Udül',
                'accepted_at' => 'Aug 12 · 16:40',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function conflicts(): array
    {
        return [
            [
                'id' => 'conflict-phone',
                'field' => 'Primary phone',
                'state' => 'Conflict',
                'values' => [
                    ['source' => 'Brand Context', 'value' => '+90 312 555 01 01', 'role' => 'Canonical', 'observed_at' => 'Operator maintained'],
                    ['source' => 'Website', 'value' => '+90 312 555 01 01', 'role' => 'Observed', 'observed_at' => '2h ago'],
                    ['source' => 'GBP', 'value' => '+90 312 000 00 00', 'role' => 'Observed', 'observed_at' => '2h ago'],
                ],
                'status' => 'unresolved',
                'finding_id' => 'gf-phone-mismatch',
            ],
            [
                'id' => 'conflict-hours',
                'field' => 'Opening hours',
                'state' => 'Needs review',
                'values' => [
                    ['source' => 'Brand Context', 'value' => 'Not configured', 'role' => 'Canonical', 'observed_at' => '—'],
                    ['source' => 'Website', 'value' => 'Mon–Sat 09:00–19:00', 'role' => 'Observed', 'observed_at' => '2h ago'],
                    ['source' => 'GBP', 'value' => 'Mon–Sat 09:00–18:30', 'role' => 'Observed', 'observed_at' => '2h ago'],
                ],
                'status' => 'unresolved',
                'finding_id' => null,
            ],
            [
                'id' => 'conflict-implant-service',
                'field' => 'Implant offering representation',
                'state' => 'Partial',
                'values' => [
                    ['source' => 'Brand Context', 'value' => 'Dental implants (priority)', 'role' => 'Canonical', 'observed_at' => 'Operator maintained'],
                    ['source' => 'Website', 'value' => 'Dental Implant page present', 'role' => 'Observed', 'observed_at' => '2h ago'],
                    ['source' => 'GBP', 'value' => 'Not clearly listed in services', 'role' => 'Observed', 'observed_at' => '2h ago'],
                ],
                'status' => 'unresolved',
                'finding_id' => 'gf-implant-service-gap',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function sources(): array
    {
        return [
            [
                'id' => 'website',
                'label' => 'Website',
                'type' => 'website',
                'state' => 'Connected',
                'state_detail' => 'Observed 2h ago',
                'active' => true,
            ],
            [
                'id' => 'gbp',
                'label' => 'Google Business Profile',
                'type' => 'gbp',
                'state' => 'Connected',
                'state_detail' => 'Observed 2h ago',
                'active' => true,
            ],
            [
                'id' => 'instagram',
                'label' => 'Instagram',
                'type' => 'meta_ads',
                'state' => 'Not connected',
                'state_detail' => 'Future capability',
                'active' => false,
            ],
            [
                'id' => 'manual',
                'label' => 'Manual',
                'type' => 'website',
                'state' => 'Operator maintained',
                'state_detail' => 'Brand Context',
                'active' => true,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function history(): array
    {
        return [
            [
                'id' => 'hist-1',
                'when' => 'Aug 13 · 04:12',
                'event' => 'Phone conflict observed',
                'detail' => 'GBP +90 312 000 00 00 ≠ Brand Context +90 312 555 01 01',
                'actor' => 'System',
                'action' => 'observed',
            ],
            [
                'id' => 'hist-2',
                'when' => 'Aug 13 · 03:50',
                'event' => 'Offering candidate surfaced',
                'detail' => 'Dental Implant · Website + GBP',
                'actor' => 'System',
                'action' => 'observed',
            ],
            [
                'id' => 'hist-3',
                'when' => 'Aug 12 · 16:40',
                'event' => 'Yakup accepted Website languages as canonical',
                'detail' => 'Turkish + English',
                'actor' => 'Yakup Udül',
                'action' => 'accepted',
            ],
            [
                'id' => 'hist-4',
                'when' => 'Aug 12 · 15:10',
                'event' => 'Nova Dental competitor candidate ignored',
                'detail' => 'Reason · irrelevant',
                'actor' => 'Ayşe Demir',
                'action' => 'ignored',
            ],
            [
                'id' => 'hist-5',
                'when' => 'Aug 12 · 14:02',
                'event' => 'GBP difference retained for operational review',
                'detail' => 'Phone conflict kept open — no silent overwrite',
                'actor' => 'System',
                'action' => 'retained',
            ],
        ];
    }

    /**
     * @return list<array{field: string, state: string}>
     */
    public static function publicIdentity(): array
    {
        return self::overview()['public_identity'];
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public static function existingOfferingsForMap(): array
    {
        return self::workspace()['existing_offerings'];
    }
}
