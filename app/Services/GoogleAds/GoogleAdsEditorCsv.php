<?php

namespace App\Services\GoogleAds;

use App\Models\Suggestion;

/**
 * Google Ads Editor import file (CSV, one sheet) from approved drafts: new campaigns (paused, with their daily budget,
 * ad groups and keywords), responsive search ads and campaign / ad group negatives. The operator imports it in
 * Google Ads Editor (Hesap › İçe aktar › Dosyadan) and posts the changes after review; MoxDOP writes nothing here.
 */
final class GoogleAdsEditorCsv
{
    public const int HEADLINES = 15;

    public const int DESCRIPTIONS = 4;

    private const array MATCH = ['EXACT' => 'Exact', 'PHRASE' => 'Phrase', 'BROAD' => 'Broad'];

    /** @return list<string> */
    public static function header(): array
    {
        $header = ['Campaign', 'Campaign Type', 'Networks', 'Campaign Daily Budget', 'Campaign Status', 'Ad Group', 'Ad Group Status', 'Keyword', 'Criterion Type', 'Ad type'];
        for ($i = 1; $i <= self::HEADLINES; $i++) {
            $header[] = 'Headline '.$i;
        }
        for ($i = 1; $i <= self::DESCRIPTIONS; $i++) {
            $header[] = 'Description '.$i;
        }

        return [...$header, 'Path 1', 'Path 2', 'Final URL'];
    }

    /**
     * @param  iterable<Suggestion>  $suggestions
     * @return list<array<string, string>>
     */
    public static function rows(iterable $suggestions): array
    {
        $rows = [];
        foreach ($suggestions as $suggestion) {
            $a = (array) $suggestion->action;
            if ($suggestion->action_type === 'ads_campaign') {
                $rows[] = ['Campaign' => (string) $a['name'], 'Campaign Type' => 'Search', 'Networks' => 'Google search',
                    'Campaign Daily Budget' => number_format((float) $a['daily_budget'], 2, '.', ''), 'Campaign Status' => 'Paused'];
                foreach ((array) ($a['ad_groups'] ?? []) as $group) {
                    $rows[] = ['Campaign' => (string) $a['name'], 'Ad Group' => (string) $group['name'], 'Ad Group Status' => 'Enabled'];
                    foreach ((array) ($group['keywords'] ?? []) as $keyword) {
                        $rows[] = ['Campaign' => (string) $a['name'], 'Ad Group' => (string) $group['name'], 'Keyword' => (string) $keyword['text'],
                            'Criterion Type' => self::MATCH[strtoupper((string) $keyword['match_type'])] ?? 'Phrase', 'Final URL' => (string) ($group['landing_url'] ?? '')];
                    }
                }
            } elseif ($suggestion->action_type === 'ads_rsa') {
                $row = ['Campaign' => (string) $a['campaign'], 'Ad Group' => (string) $a['ad_group'], 'Ad type' => 'Responsive search ad',
                    'Path 1' => (string) ($a['path1'] ?? ''), 'Path 2' => (string) ($a['path2'] ?? ''), 'Final URL' => (string) $a['final_url']];
                foreach (array_values((array) $a['headlines']) as $i => $text) {
                    $row['Headline '.($i + 1)] = (string) $text;
                }
                foreach (array_values((array) $a['descriptions']) as $i => $text) {
                    $row['Description '.($i + 1)] = (string) $text;
                }
                $rows[] = $row;
            } elseif ($suggestion->action_type === 'ads_negative' && in_array($a['scope'] ?? '', ['campaign', 'ad_group'], true)) {
                $match = self::MATCH[strtoupper((string) $a['match_type'])] ?? 'Phrase';
                $rows[] = ['Campaign' => (string) $a['campaign'], 'Ad Group' => $a['scope'] === 'ad_group' ? (string) $a['ad_group'] : '',
                    'Keyword' => (string) $a['text'], 'Criterion Type' => ($a['scope'] === 'campaign' ? 'Campaign Negative ' : 'Negative ').$match];
            }
        }

        return $rows;
    }

    /** @param  iterable<Suggestion>  $suggestions */
    public static function build(iterable $suggestions): string
    {
        $header = self::header();
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $header, ',', '"', '');
        foreach (self::rows($suggestions) as $row) {
            fputcsv($out, array_map(fn (string $column): string => $row[$column] ?? '', $header), ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
