<?php

namespace App\Support\Sales;

/**
 * Deterministic Intent Radar observations for PHPUnit / Playwright.
 * Never used in production unless MOXDOP_INTENT_SEARCH_FIXTURES=true.
 */
final class IntentSearchFixtures
{
    /**
     * @return list<array{
     *     query: string,
     *     source_url: string,
     *     source_title: string,
     *     observed_snippet: string,
     *     fetched_excerpt: ?string,
     *     verification: string
     * }>
     */
    public static function results(string $query): array
    {
        return [
            [
                'query' => $query,
                'source_url' => 'https://intent-fixture.moxdop-e2e.test/agency-wanted',
                'source_title' => 'Ajans arıyoruz — web sitesi',
                'observed_snippet' => 'Web sitesi yaptırmak için bir ajans arıyoruz.',
                'fetched_excerpt' => 'Manisa’da mobilya mağazamız var. Web sitesi yaptırmak için bir ajans arıyoruz.',
                'verification' => 'verified',
            ],
            [
                'query' => $query,
                'source_url' => 'https://intent-fixture.moxdop-e2e.test/how-to',
                'source_title' => 'Web sitesi nasıl yapılır?',
                'observed_snippet' => 'Web sitesi nasıl yapılır?',
                'fetched_excerpt' => 'Web sitesi nasıl yapılır? Ücretsiz öğretici.',
                'verification' => 'verified',
            ],
        ];
    }
}
