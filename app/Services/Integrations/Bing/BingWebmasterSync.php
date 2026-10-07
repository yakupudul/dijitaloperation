<?php

namespace App\Services\Integrations\Bing;

use App\Models\DigitalAsset;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Bing Webmaster reading: every active MoxDOP website is matched to the verified Bing site of the same host (www and
 * scheme ignored), then each matched site's weekly search queries are stored (`bing_query_stats`). Read only; a failing
 * site keeps its error on its row and the rest go on.
 */
final class BingWebmasterSync
{
    public function __construct(private readonly BingWebmasterClient $client) {}

    /** @return array{sites: int, matched: int} Bing sites of the key and how many matched a MoxDOP website */
    public function match(): array
    {
        $sites = $this->client->sites();
        $byHost = [];
        foreach ($sites as $site) {
            $byHost[self::host($site['url'])] ??= $site;
        }
        $matched = 0;
        foreach (DigitalAsset::query()->where('type', 'website')->where('status', 'active')->get(['id', 'primary_url', 'domain']) as $asset) {
            $site = $byHost[self::host((string) ($asset->primary_url ?: $asset->domain))] ?? null;
            if ($site === null) {
                DB::table('bing_sites')->where('digital_asset_id', $asset->id)->delete();

                continue;
            }
            DB::table('bing_sites')->updateOrInsert(['digital_asset_id' => $asset->id],
                ['site_url' => $site['url'], 'verified' => $site['verified'], 'updated_at' => now(), 'created_at' => now()]);
            $matched++;
        }

        return ['sites' => count($sites), 'matched' => $matched];
    }

    /** @return array{sites: int, rows: int, failed: int} */
    public function collect(?int $assetId = null): array
    {
        $out = ['sites' => 0, 'rows' => 0, 'failed' => 0];
        foreach (DB::table('bing_sites')->where('verified', true)->when($assetId !== null, fn ($q) => $q->where('digital_asset_id', $assetId))->get() as $site) {
            $out['sites']++;
            try {
                $rows = $this->client->queryStats((string) $site->site_url);
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('bing_query_stats')->upsert(array_map(fn (array $r): array => [
                        'digital_asset_id' => (int) $site->digital_asset_id, 'query' => $r['query'], 'query_hash' => hash('sha256', mb_strtolower($r['query'])),
                        'week' => $r['week'], 'impressions' => $r['impressions'], 'clicks' => $r['clicks'], 'position' => $r['position'],
                        'created_at' => now(), 'updated_at' => now(),
                    ], $chunk), ['digital_asset_id', 'query_hash', 'week'], ['impressions', 'clicks', 'position', 'updated_at']);
                }
                $out['rows'] += count($rows);
                DB::table('bing_sites')->where('id', $site->id)->update(['collected_at' => now(), 'error' => null, 'updated_at' => now()]);
            } catch (Throwable $exception) {
                $out['failed']++;
                DB::table('bing_sites')->where('id', $site->id)->update(['error' => mb_substr($exception->getMessage(), 0, 500), 'updated_at' => now()]);
            }
        }

        return $out;
    }

    /**
     * The site's Bing numbers over the last weeks: totals and the most seen queries.
     *
     * @return array{site_url: string, verified: bool, error: ?string, collected_at: ?string, impressions: int, clicks: int, queries: list<array{query: string, impressions: int, clicks: int, position: ?float}>}|null
     */
    public static function summary(int $assetId, int $weeks = 4): ?array
    {
        $site = DB::table('bing_sites')->where('digital_asset_id', $assetId)->first();
        if ($site === null) {
            return null;
        }
        $from = now()->subWeeks($weeks)->startOfWeek()->toDateString();
        $rows = DB::table('bing_query_stats')->where('digital_asset_id', $assetId)->where('week', '>=', $from)
            ->selectRaw('query, sum(impressions) as impressions, sum(clicks) as clicks, avg(position) as position')->groupBy('query')
            ->orderByDesc('impressions')->get();

        return [
            'site_url' => (string) $site->site_url, 'verified' => (bool) $site->verified, 'error' => $site->error, 'collected_at' => $site->collected_at,
            'impressions' => (int) $rows->sum('impressions'), 'clicks' => (int) $rows->sum('clicks'),
            'queries' => $rows->take(10)->map(fn (object $r): array => ['query' => (string) $r->query, 'impressions' => (int) $r->impressions, 'clicks' => (int) $r->clicks,
                'position' => $r->position !== null ? round((float) $r->position, 1) : null])->values()->all(),
        ];
    }

    public static function host(string $url): string
    {
        $host = parse_url(str_contains($url, '://') ? $url : 'https://'.$url, PHP_URL_HOST);

        return is_string($host) ? (string) preg_replace('/^www\./', '', strtolower($host)) : '';
    }
}
