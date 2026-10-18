<?php

namespace App\Services\Epicrisis;

use App\Models\Brand;
use App\Models\BrandEpicrisisItem;
use App\Models\BrandOffering;
use App\Services\Brand\BrandFacts;
use App\Services\Site\SiteScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Epikriz katman 2 — kanıt. By rules from the brand's own data, no AI: what searchers ask, what reviews complain about
 * and praise (the Marka bilgi kartı's automatic fields, or the operator's text where he wrote one), what counts as a
 * customer, which services bring results in Google Ads and Meta (last stored period), other brands in the same city
 * and service, the season of demand and the site languages. The counts of a line ("12 yorum") are kept in its detail,
 * not its value, so a line does not change every night.
 */
final class EpicrisisEvidence
{
    /** Bilgi kartı field → evidence kind. */
    private const array CARD = [
        'questions' => ['question', 'search'], 'objections' => ['objection', 'reviews'], 'praise' => ['praise', 'reviews'], 'conversions' => ['conversion', 'moxdop'],
        'competitors' => ['competitor', 'moxdop'], 'seasonality' => ['season', 'search'], 'languages' => ['site_language', 'site'],
    ];

    /** Services per channel kept as evidence. */
    private const int TOP_SERVICES = 5;

    public function __construct(private readonly EpicrisisStore $store, private readonly BrandFacts $facts) {}

    /** @return int evidence lines stored or seen */
    public function collect(Brand $brand): int
    {
        $rows = [];
        try {
            $card = $this->facts->card($brand)['fields'];
            foreach (self::CARD as $key => [$kind, $source]) {
                $field = $card[$key] ?? null;
                if ($field === null) {
                    continue;
                }
                $lines = $field['locked'] ? array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $field['manual']) ?: []))) : $field['items'];
                foreach (array_slice($lines, 0, 8) as $rank => $line) {
                    [$value, $count] = self::split((string) $line);
                    if ($value !== '') {
                        $rows[] = ['field' => $kind, 'value' => mb_substr($value, 0, 240), 'source_kind' => $field['locked'] ? 'operator' : $source,
                            'source_ref' => $field['locked'] ? 'Marka bilgi kartı' : mb_substr((string) $field['source'], 0, 400), 'confidence' => $field['locked'] ? BrandEpicrisisItem::VERIFIED : BrandEpicrisisItem::DATA,
                            'detail' => ['rank' => $rank, 'count' => $count]];
                    }
                }
            }
        } catch (Throwable $exception) {
            report($exception);
        }
        try {
            array_push($rows, ...$this->adResults($brand));
        } catch (Throwable $exception) {
            report($exception);
        }

        return count($this->store->sync($brand, BrandEpicrisisItem::EVIDENCE, $rows, 'rules'));
    }

    /** @return list<array<string, mixed>> */
    private function adResults(Brand $brand): array
    {
        if (! Schema::hasTable('ad_service_stats')) {
            return [];
        }
        $names = SiteScope::offerings($brand)->mapWithKeys(fn (BrandOffering $o): array => [(int) $o->id => trim($o->displayName())]);
        $rows = [];
        foreach (['google_ads' => ['Google Ads', 'ads'], 'meta' => ['Meta', 'meta']] as $channel => [$label, $source]) {
            $stats = DB::table('ad_service_stats')->where('brand_id', $brand->id)->where('channel', $channel)
                ->selectRaw('brand_offering_id, result_type, SUM(results) as results, SUM(spend) as spend, MAX(period_end) as period_end')
                ->groupBy('brand_offering_id', 'result_type')->get()
                ->filter(fn (object $r): bool => (float) $r->results > 0 && ($names[(int) $r->brand_offering_id] ?? '') !== '')
                ->sortByDesc(fn (object $r): float => (float) $r->results)->take(self::TOP_SERVICES)->values();
            foreach ($stats as $rank => $stat) {
                $rows[] = ['field' => 'ad_result', 'scope' => $names[(int) $stat->brand_offering_id], 'value' => $label.' · '.$names[(int) $stat->brand_offering_id].' sonuç getiriyor ('.$stat->result_type.')',
                    'source_kind' => $source, 'source_ref' => $label.' › hizmet sonuçları', 'detail' => ['rank' => $rank, 'results' => round((float) $stat->results, 1),
                        'spend' => round((float) $stat->spend, 2), 'period_end' => (string) $stat->period_end]];
            }
        }

        return $rows;
    }

    /** "Ağrı (12 yorum)" → ["Ağrı", "12 yorum"]. @return array{0: string, 1: ?string} */
    public static function split(string $line): array
    {
        $line = trim($line);
        if (preg_match('/^(.*?)\s*\(([^()]*\d[^()]*)\)\s*$/u', $line, $m) === 1 && trim($m[1]) !== '') {
            return [trim($m[1]), trim($m[2])];
        }

        return [$line, null];
    }
}
