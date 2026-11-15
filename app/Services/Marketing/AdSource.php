<?php

namespace App\Services\Marketing;

use App\Support\SchemaState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Hangi reklamdan geldi (yakup 2026-11-15): a Click-to-WhatsApp chat carries Meta's `referral` (ad id, headline, text,
 * link). The headline is often Meta's generic "Kampanyadan faydalanın", so the ad id is matched to the ad and campaign
 * names MoxDOP already stores from the ad account (meta_geo_results_daily). Read only; nothing is written to Meta.
 */
final class AdSource
{
    /** @var array<string, array{ad: string, campaign: string}> */
    private array $cache = [];

    /**
     * What the person saw: the ad's own name when the ad account knows it, else the headline Meta sent.
     *
     * @param  array<string, string>  $referral
     * @return array{ad: string, campaign: string, headline: string, text: string, url: string, id: string, type: string}|null
     */
    public function of(mixed $referral): ?array
    {
        if (! is_array($referral) || $referral === []) {
            return null;
        }
        $id = (string) ($referral['source_id'] ?? '');
        $names = $id !== '' ? ($this->names([$id])[$id] ?? null) : null;
        $headline = trim((string) ($referral['headline'] ?? ''));
        $ad = $names['ad'] ?? '';
        if ($ad === '') {
            $ad = $headline !== '' ? $headline : ($id !== '' ? 'Reklam '.$id : 'Reklam');
        }
        $url = (string) ($referral['source_url'] ?? '');

        return [
            'ad' => $ad, 'campaign' => $names['campaign'] ?? '', 'headline' => $headline, 'text' => trim((string) ($referral['body'] ?? '')),
            'url' => preg_match('#^https://#i', $url) ? $url : '', 'id' => $id,
            'type' => ($referral['source_type'] ?? '') === 'post' ? 'Gönderi' : 'Reklam',
        ];
    }

    /**
     * Ad and campaign names by ad id (the newest name the ad account reported).
     *
     * @param  list<string>  $ids
     * @return array<string, array{ad: string, campaign: string}>
     */
    public function names(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids), fn (string $id): bool => preg_match('/^[0-9]{5,40}$/', $id) === 1)));
        $missing = array_values(array_diff($ids, array_keys($this->cache)));
        if ($missing !== [] && SchemaState::hasTable('meta_geo_results_daily')) {
            foreach ($missing as $id) {
                $this->cache[$id] = ['ad' => '', 'campaign' => ''];
            }
            DB::table('meta_geo_results_daily')->whereIn('ad_id', $missing)->where('level', 'country')
                ->orderBy('reporting_date')->orderBy('id')->get(['ad_id', 'ad_name', 'campaign_name'])
                ->each(function (object $row): void {
                    $this->cache[(string) $row->ad_id] = [
                        'ad' => trim((string) $row->ad_name) ?: $this->cache[(string) $row->ad_id]['ad'],
                        'campaign' => trim((string) $row->campaign_name) ?: $this->cache[(string) $row->ad_id]['campaign'],
                    ];
                });
        }

        return array_intersect_key($this->cache, array_flip($ids));
    }

    /**
     * What Meta counted for these ads since a day: spend and messaging conversations started.
     *
     * @param  list<string>  $ids
     * @return array<string, array{spend: float, messages: int}>
     */
    public function totals(array $ids, CarbonImmutable $from): array
    {
        $ids = array_values(array_filter(array_map('strval', $ids), fn (string $id): bool => preg_match('/^[0-9]{5,40}$/', $id) === 1));
        if ($ids === [] || ! SchemaState::hasTable('meta_geo_results_daily')) {
            return [];
        }

        return DB::table('meta_geo_results_daily')->whereIn('ad_id', $ids)->where('level', 'country')
            ->where('reporting_date', '>=', $from->toDateString())->groupBy('ad_id')
            ->selectRaw('ad_id, coalesce(sum(spend), 0) as spend, coalesce(sum(messages), 0) as messages')->get()
            ->mapWithKeys(fn (object $r): array => [(string) $r->ad_id => ['spend' => round((float) $r->spend, 2), 'messages' => (int) round((float) $r->messages)]])
            ->all();
    }
}
