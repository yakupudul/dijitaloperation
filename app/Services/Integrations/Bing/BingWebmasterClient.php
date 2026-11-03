<?php

namespace App\Services\Integrations\Bing;

use App\Models\AgencySetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Bing Webmaster Tools API (JSON), read only: the verified sites of the key's Bing user, each site's search queries and
 * its inbound links (Backlink Faz 0: Google's Links report has no API, Bing's has). The agency API key (Bing Webmaster › Ayarlar › API erişimi) is stored encrypted in agency settings.
 */
final class BingWebmasterClient
{
    public const string BASE = 'https://ssl.bing.com/webmaster/api.svc/json/';

    public static function apiKey(): ?string
    {
        $key = AgencySetting::query()->first()?->bing_webmaster_api_key;

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    public static function configured(): bool
    {
        return self::apiKey() !== null;
    }

    /**
     * @return list<array{url: string, verified: bool}>
     */
    public function sites(?string $key = null): array
    {
        return array_values(array_filter(array_map(fn (mixed $row): ?array => is_array($row) && is_string($row['Url'] ?? null)
            ? ['url' => (string) $row['Url'], 'verified' => (bool) ($row['IsVerified'] ?? false)] : null, $this->get('GetUserSites', [], $key))));
    }

    /**
     * The site's search queries per week (Bing keeps about six months).
     *
     * @return list<array{query: string, week: string, impressions: int, clicks: int, position: ?float}>
     */
    public function queryStats(string $siteUrl): array
    {
        $out = [];
        foreach ($this->get('GetQueryStats', ['siteUrl' => $siteUrl]) as $row) {
            $date = is_array($row) ? self::date($row['Date'] ?? null) : null;
            if ($date === null || ! is_string($row['Query'] ?? null) || trim($row['Query']) === '') {
                continue;
            }
            $position = is_numeric($row['AvgImpressionPosition'] ?? null) && (float) $row['AvgImpressionPosition'] > 0 ? round((float) $row['AvgImpressionPosition'], 2) : null;
            $out[] = ['query' => mb_substr(trim($row['Query']), 0, 500), 'week' => $date->startOfWeek()->toDateString(),
                'impressions' => max(0, (int) ($row['Impressions'] ?? 0)), 'clicks' => max(0, (int) ($row['Clicks'] ?? 0)), 'position' => $position];
        }

        return $out;
    }

    /**
     * One page of the site's pages that other sites link to, with how many links each has (GetLinkCounts).
     *
     * @return array{links: list<array{url: string, count: int}>, pages: int}
     */
    public function linkCounts(string $siteUrl, int $page = 0): array
    {
        $data = $this->object('GetLinkCounts', ['siteUrl' => $siteUrl, 'page' => (string) $page]);
        $links = [];
        foreach ((array) ($data['Links'] ?? []) as $row) {
            if (is_array($row) && is_string($row['Url'] ?? null) && trim($row['Url']) !== '') {
                $links[] = ['url' => trim($row['Url']), 'count' => max(0, (int) ($row['Count'] ?? 0))];
            }
        }

        return ['links' => $links, 'pages' => max(0, (int) ($data['TotalPages'] ?? 0))];
    }

    /**
     * One page of the outside pages linking to one of the site's pages, with the link text (GetUrlLinks).
     *
     * @return array{links: list<array{url: string, anchor: ?string}>, pages: int}
     */
    public function urlLinks(string $siteUrl, string $link, int $page = 0): array
    {
        $data = $this->object('GetUrlLinks', ['siteUrl' => $siteUrl, 'link' => $link, 'page' => (string) $page]);
        $links = [];
        foreach ((array) ($data['Details'] ?? []) as $row) {
            if (is_array($row) && is_string($row['Url'] ?? null) && trim($row['Url']) !== '') {
                $anchor = trim((string) ($row['AnchorText'] ?? ''));
                $links[] = ['url' => trim($row['Url']), 'anchor' => $anchor !== '' ? mb_substr($anchor, 0, 500) : null];
            }
        }

        return ['links' => $links, 'pages' => max(0, (int) ($data['TotalPages'] ?? 0))];
    }

    /** "/Date(1696118400000-0700)/" (WCF JSON) → day, or null. */
    public static function date(mixed $value): ?CarbonImmutable
    {
        if (is_string($value) && preg_match('#/Date\((-?\d+)#', $value, $m) === 1) {
            return CarbonImmutable::createFromTimestampMs((int) $m[1], 'UTC');
        }

        return null;
    }

    /**
     * @param  array<string, string>  $query
     * @return list<mixed>
     */
    private function get(string $method, array $query, ?string $key = null): array
    {
        $data = $this->call($method, $query, $key);

        return is_array($data) ? array_values($data) : [];
    }

    /**
     * A method answering one object ("d": {...}) instead of a list.
     *
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function object(string $method, array $query): array
    {
        $data = $this->call($method, $query);

        return is_array($data) ? $data : [];
    }

    /** @param  array<string, string>  $query */
    private function call(string $method, array $query, ?string $key = null): mixed
    {
        $key ??= self::apiKey();
        if ($key === null) {
            throw new RuntimeException('Bing Webmaster API anahtarı girilmedi.');
        }
        $response = Http::timeout(30)->acceptJson()->get(self::BASE.$method, $query + ['apikey' => $key]);
        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException('Bing anahtarı reddetti (yetki yok); anahtarı Bing Webmaster › Ayarlar › API erişimi bölümünden yeniden alın.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('Bing Webmaster yanıt vermedi (HTTP '.$response->status().').');
        }

        return $response->json('d');
    }
}
