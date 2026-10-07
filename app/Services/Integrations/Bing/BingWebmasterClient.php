<?php

namespace App\Services\Integrations\Bing;

use App\Models\AgencySetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Bing Webmaster Tools API (JSON), read only: the verified sites of the key's Bing user and each site's search
 * queries. The agency API key (Bing Webmaster › Ayarlar › API erişimi) is stored encrypted in agency settings.
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
        $data = $response->json('d');

        return is_array($data) ? array_values($data) : [];
    }
}
