<?php

namespace App\Support\Prospects;

/**
 * Deterministic crawl + intelligence fixtures for PHPUnit and Playwright E2E.
 * Never used in production unless MOXDOP_PROSPECT_RESEARCH_FIXTURES=true.
 */
final class ProspectResearchFixtures
{
    public static function isFixtureUrl(?string $url): bool
    {
        if ($url === null || trim($url) === '') {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && strtolower($host) === ProspectResearchConfig::FIXTURE_HOST;
    }

    /**
     * @return array{
     *     status: string,
     *     seed_url: string,
     *     pages: list<array<string, mixed>>,
     *     failures: list<array<string, mixed>>,
     *     pages_inspected: int,
     *     total_bytes: int
     * }
     */
    public static function crawl(string $seedUrl): array
    {
        $pageUrl = rtrim($seedUrl, '/').'/';

        return [
            'status' => 'succeeded',
            'seed_url' => $seedUrl,
            'pages' => [
                [
                    'requested_url' => $pageUrl,
                    'final_url' => $pageUrl,
                    'status_code' => 200,
                    'content_type' => 'text/html',
                    'bytes' => 2048,
                    'redirect_count' => 0,
                    'extracted' => [
                        'source_url' => $pageUrl,
                        'title' => 'ABC Dental Clinic',
                        'h1' => 'ABC Dental Clinic',
                        'meta_description' => 'Implant dentistry and cosmetic dental services.',
                        'html_lang' => 'tr',
                        'nav_labels' => ['Home', 'Services', 'About', 'Contact'],
                        'same_site_links' => [
                            ['label' => 'Implant Dentistry', 'url' => rtrim($seedUrl, '/').'/services/implants'],
                            ['label' => 'Google Ads', 'url' => rtrim($seedUrl, '/').'/services/marketing'],
                        ],
                        'phones' => ['+90 212 555 0101'],
                        'emails' => ['info@abcdental.example'],
                        'social_links' => [],
                        'address_candidates' => ['Istanbul, Turkey'],
                    ],
                ],
            ],
            'failures' => [],
            'pages_inspected' => 1,
            'total_bytes' => 2048,
        ];
    }

    /**
     * @param  list<int>  $evidenceIds
     * @return array<string, mixed>
     */
    public static function salesIntelligence(array $evidenceIds = []): array
    {
        $refs = array_map(static fn (int $id): array => ['evidence_id' => $id], $evidenceIds);

        return [
            'summary' => 'ABC Dental appears to be a local dental clinic with implant and cosmetic services. The inbound inquiry mentions website and Google Ads support.',
            'detected_needs' => [
                'Website conversion and lead capture improvements',
                'Google Ads performance and measurement',
            ],
            'recommended_services' => [
                [
                    'service_definition_code' => 'website_design',
                    'priority' => 'high',
                    'rationale' => 'Public site shows service pages but weak conversion signals; inquiry mentions website support.',
                    'evidence_refs' => $refs,
                    'confidence' => 'moderate',
                ],
                [
                    'service_definition_code' => 'google_ads',
                    'priority' => 'high',
                    'rationale' => 'Inbound inquiry explicitly requests Google Ads support.',
                    'evidence_refs' => [],
                    'confidence' => 'high',
                ],
            ],
            'not_recommended_services' => [
                [
                    'service_definition_code' => 'meta_ads',
                    'rationale' => 'No paid social signals or inquiry mention; defer until measurement baseline exists.',
                ],
            ],
            'sales_priorities' => [
                'Clarify current website lead flow',
                'Review Google Ads account structure and conversion tracking',
            ],
            'first_meeting_focus' => 'Understand current website performance and Google Ads goals before proposing a full channel mix.',
            'diagnostic_questions' => [
                'Which services drive the most revenue today?',
                'Do you have conversion tracking on the website?',
                'What is your monthly Google Ads budget and primary KPI?',
            ],
            'suggested_positioning' => 'Position MoxDOP as a measurement-first growth partner for local healthcare lead generation.',
            'uncertainties' => [
                'Actual Google Ads account health is unknown without access.',
                'Competitive positioning in local search is not verified.',
            ],
            'overall_confidence' => 'moderate',
        ];
    }
}
