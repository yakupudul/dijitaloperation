<?php

namespace App\Services\Operator;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\GbpReview;
use App\Models\Suggestion;
use App\Services\Ads\SectorPlaybook;
use App\Services\Goals\BrandTargets;
use App\Services\Goals\TargetImpact;
use App\Services\Integrations\BrandAccountCandidates;
use App\Services\Repair\RepairDesk;
use App\Services\Work\ContentWeekPlan;
use App\Support\SchemaState;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Komuta (Aşama 5; prototype approved 2026-10-22): one decision box for every brand. Work of the same kind is one
 * group across brands (323 merges of three brands are one group, a package per brand inside it), the groups that bring
 * the most demand first; a low-risk package is approved in one click, the rest open where they are decided. Brand goals
 * waiting for approval, review replies, accounts that may belong to a brand and the manual lane ride along.
 */
final class KomutaReader
{
    /** Plain group names (the desk's kind names are for its own tabs). */
    public const array GROUP_TITLES = [
        RepairDesk::SITE_FIELDS => 'Başlığı ya da açıklaması zayıf sayfalar',
        RepairDesk::SITE_CONTENT => 'Sayfa metni önerileri',
        RepairDesk::REDIRECT => 'Aynı konuyu anlatan sayfalar birleşsin',
        RepairDesk::IMAGE_ALT => 'Alt metni boş görseller',
        RepairDesk::WEB_FIX => 'Sitenin teknik düzeltmeleri',
        RepairDesk::WEB_TASK => 'Sitede yapılacak küçük işler',
        RepairDesk::GBP_DESCRIPTION => 'İşletme profili açıklamaları',
        RepairDesk::GBP_FIELDS => 'İşletme profili bilgileri',
        RepairDesk::BRAND_DATA => 'Marka bilgisi düzeltmeleri',
    ];

    /** Group icon family (the Akış icons). */
    public const array GROUP_KIND = [
        RepairDesk::GBP_DESCRIPTION => 'gbp', RepairDesk::GBP_FIELDS => 'gbp', RepairDesk::ADS_NEGATIVE => 'ads', RepairDesk::ADS_CHANGE => 'ads',
        RepairDesk::SITE_CONTENT => 'icerik',
    ];

    public function __construct(private readonly RepairDesk $desk, private readonly TargetImpact $impact, private readonly BrandTargets $targets) {}

    /**
     * @return array{groups: list<array{key: string, kind: string, title: string, line: string, count: int, brands: int, impact: ?float, bulk: bool, items: list<array{key: string, brand_id: int, brand: string, logo: ?string, line: string, count: int, impact: ?float, approve: bool, url: string}>}>, decisions: int, manual: int, failed?: bool}
     */
    public function box(): array
    {
        $groups = [];
        $manual = 0;
        $failed = false;
        try {
            $rows = $this->desk->rows();
            $manual = $rows->filter(fn (array $r): bool => RepairDesk::lane($r) === RepairDesk::LANE_MANUAL)->count();
            $packages = $this->desk->packages($rows->reject(fn (array $r): bool => RepairDesk::lane($r) === RepairDesk::LANE_MANUAL)->values());
            $logos = Brand::query()->pluck('logo_url', 'id');
            foreach ($packages->groupBy('kind') as $kind => $kindPackages) {
                $items = $kindPackages->map(function (array $p) use ($logos): array {
                    $impact = $this->impact->forPackage($p);

                    return [
                        'key' => 'pkg:'.$p['key'],
                        'brand_id' => (int) $p['brand_id'],
                        'brand' => (string) $p['brand'],
                        'logo' => $logos[$p['brand_id']] ?? null,
                        'line' => $p['count'].' iş'.($p['lane'] === RepairDesk::LANE_REVIEW ? ' · bir göz at' : '').($p['track'] !== '' ? ' · '.$p['track'] : ''),
                        'count' => (int) $p['count'],
                        'impact' => $impact,
                        'approve' => $p['lane'] === RepairDesk::LANE_READY,
                        'url' => route('operator.repair', ['marka' => $p['brand_id'], 'tur' => $p['kind'], 'paket' => $p['key']]),
                    ];
                })->sortByDesc(fn (array $i): float => ($i['impact'] ?? 0) * 1000 + $i['count'])->values();
                $impact = $items->sum(fn (array $i): float => (float) ($i['impact'] ?? 0));
                $groups[] = [
                    'key' => 'kind:'.$kind,
                    'kind' => self::GROUP_KIND[$kind] ?? 'web',
                    'title' => self::GROUP_TITLES[$kind] ?? (RepairDesk::KINDS[$kind] ?? (string) $kind),
                    'line' => $items->count().' marka · '.number_format($items->sum('count'), 0, ',', '.').' iş',
                    'count' => (int) $items->sum('count'),
                    'brands' => $items->count(),
                    'impact' => $impact > 0 ? round($impact, 1) : null,
                    'bulk' => $items->contains('approve', true),
                    'items' => $items->all(),
                ];
            }
        } catch (Throwable $error) {
            report($error);
            $failed = true;
        }
        usort($groups, fn (array $a, array $b): int => [($b['impact'] ?? 0), $b['count']] <=> [($a['impact'] ?? 0), $a['count']]);
        $groups = [...$this->targetGroup(), ...$this->nameGroup(), ...$this->planGroup(), ...$groups, ...$this->sectorGroup()];

        return ['groups' => $groups, 'decisions' => (int) array_sum(array_map(fn (array $g): int => count($g['items']), $groups)), 'manual' => $manual] + ($failed ? ['failed' => true] : []);
    }

    /**
     * Smaller things waiting elsewhere, counted (cached a few minutes): review replies and accounts that may belong to
     * a brand.
     *
     * @return array{reviews: int, accounts: int, account_brand: ?int}
     */
    public function elsewhere(): array
    {
        return Cache::remember('komuta.elsewhere', 300, function (): array {
            $out = ['reviews' => 0, 'accounts' => 0, 'account_brand' => null];
            try {
                $profiles = DigitalAsset::query()->operational()->where('type', 'google_business_profile')->pluck('digital_assets.id');
                $out['reviews'] = GbpReview::query()->whereIn('digital_asset_id', $profiles)->whereNull('review_reply')
                    ->where('create_time', '>=', now()->subDays(30))->count();
            } catch (Throwable $error) {
                report($error);
            }
            try {
                $strong = app(BrandAccountCandidates::class)->strongForBrands(Brand::query()->operational()->get());
                $out['accounts'] = (int) array_sum(array_map('count', $strong));
                $out['account_brand'] = $strong === [] ? null : (int) array_key_first($strong);
            } catch (Throwable $error) {
                report($error);
            }

            return $out;
        });
    }

    /** @return list<array<string, mixed>> the brand goals waiting for one click, as one group */
    private function targetGroup(): array
    {
        if (! SchemaState::hasTable('brand_targets')) {
            return [];
        }
        $waiting = $this->targets->waiting();
        if ($waiting->isEmpty()) {
            return [];
        }

        return [[
            'key' => 'targets',
            'kind' => 'marka',
            'title' => 'Marka amaçları onayını bekliyor',
            'line' => $waiting->count().' marka · son 3 ayın talebinden önerildi',
            'count' => $waiting->count(),
            'brands' => $waiting->count(),
            'impact' => null,
            'bulk' => true,
            'items' => $waiting->map(fn ($t): array => [
                'key' => 'target:'.$t->id,
                'brand_id' => (int) $t->brand_id,
                'brand' => (string) $t->brand?->name,
                'logo' => $t->brand?->logo_url ?: null,
                'line' => 'Ayda '.$t->proposed_leads.' talep · '.self::splitLine((array) $t->proposed_split),
                'count' => 1,
                'impact' => null,
                'approve' => true,
                'url' => route('operator.portfolio', ['marka' => $t->brand_id]),
            ])->values()->all(),
        ]];
    }

    /**
     * Aşama 10: where each brand is behind in its sector (city × service × channel), one item per brand with its biggest
     * gap as "sen / ortası / lider" and the leader's way as the step (no other brand is named). Opens the brand.
     *
     * @return list<array<string, mixed>>
     */
    private function sectorGroup(): array
    {
        try {
            $all = app(SectorPlaybook::class)->all();
        } catch (Throwable $error) {
            report($error);

            return [];
        }
        $brands = Brand::query()->operational()->whereIn('brands.id', array_keys($all) ?: [0])->get(['brands.id', 'brands.name', 'brands.logo_url'])->keyBy('id');
        $items = collect($all)->filter(fn (array $rows, int $id): bool => $brands->has($id) && $rows !== [])
            ->map(fn (array $rows, int $id): array => [
                'key' => 'sector:'.$id,
                'brand_id' => $id,
                'brand' => (string) $brands[$id]->name,
                'logo' => $brands[$id]->logo_url ?: null,
                'line' => $rows[0]['line'].(count($rows) > 1 ? ' · '.(count($rows) - 1).' yerde daha geride' : ''),
                'solution' => $rows[0]['play'],
                'count' => count($rows),
                'impact' => null,
                'approve' => false,
                'url' => route('operator.brand', $id),
            ])->sortByDesc(fn (array $i): float => $all[$i['brand_id']][0]['gap'])->values();
        if ($items->isEmpty()) {
            return [];
        }

        return [[
            'key' => 'sector',
            'kind' => 'marka',
            'title' => 'Sektörde geride kaldığın yerler',
            'line' => $items->count().' marka · sen / sektör ortası / lider, liderin yolu adım olarak',
            'count' => (int) $items->sum('count'),
            'brands' => $items->count(),
            'impact' => null,
            'bulk' => false,
            'items' => $items->all(),
        ]];
    }

    public const string NAME_STANDARD = 'gbp:name_no_keywords';

    /**
     * Aşama 9 (K1): Business Profile names carrying district or service words (suspension risk), one decision per brand:
     * "Onayla" = the name will be put back to the signage name on Google (the operator does it, the profile check closes
     * the card), "Gerek yok" = the signage really says this.
     *
     * @return list<array<string, mixed>>
     */
    private function nameGroup(): array
    {
        $rows = self::nameRows();
        if ($rows->isEmpty()) {
            return [];
        }
        $assets = DigitalAsset::query()->whereIn('id', $rows->pluck('target_id')->unique()->all())->pluck('name', 'id');
        $items = $rows->groupBy('brand_id')->map(fn ($brandRows, int $brandId): array => [
            'key' => 'gbpname:'.$brandId,
            'brand_id' => $brandId,
            'brand' => (string) $brandRows[0]->brand?->name,
            'logo' => $brandRows[0]->brand?->logo_url ?: null,
            'line' => $brandRows->count().' profil · '.$brandRows->take(2)->map(fn (Suggestion $s): string => (string) ($assets[$s->target_id] ?? ''))->filter()->implode(', ')
                .' · Onayla: adı tabeladaki hale getireceksin · Gerek yok: tabelada bu ad yazıyor',
            'count' => $brandRows->count(),
            'impact' => null,
            'approve' => true,
            'url' => route('operator.gbp', ['assetId' => (int) $brandRows[0]->target_id]),
        ])->sortByDesc('count')->values();

        return [[
            'key' => 'gbp-names',
            'kind' => 'gbp',
            'title' => 'İşletme adında semt ya da hizmet kelimesi',
            'line' => $items->count().' marka · '.$rows->count().' profil · Google profili askıya alabilir',
            'count' => $rows->count(),
            'brands' => $items->count(),
            'impact' => null,
            'bulk' => false,
            'items' => $items->all(),
        ]];
    }

    /** @return Collection<int, Suggestion> open name findings of served brands (one brand when given) */
    public static function nameRows(?int $brandId = null): Collection
    {
        return Suggestion::query()->with('brand:id,name,logo_url')->where('action_type', 'gbp_standard')->where('action->standard_id', self::NAME_STANDARD)
            ->where('status', Suggestion::OPEN)->whereHas('brand', fn ($q) => $q->operational())
            ->when($brandId !== null, fn ($q) => $q->where('brand_id', $brandId))->orderBy('id')->get();
    }

    /** @return list<array<string, mixed>> the week's content plan (Aşama 8), one package per brand */
    private function planGroup(): array
    {
        try {
            $rows = app(ContentWeekPlan::class)->waiting();
        } catch (Throwable $error) {
            report($error);

            return [];
        }
        if ($rows->isEmpty()) {
            return [];
        }
        $items = $rows->groupBy('brand_id')->map(function ($brandRows, int $brandId): array {
            $kinds = $brandRows->countBy('kind_label')->map(fn (int $n, string $label): string => $n.' '.mb_strtolower($label))->implode(', ');

            return [
                'key' => 'plan:'.$brandId,
                'brand_id' => $brandId,
                'brand' => (string) $brandRows[0]['brand'],
                'logo' => $brandRows[0]['logo'],
                'line' => $kinds,
                'count' => $brandRows->count(),
                'impact' => null,
                'approve' => true,
                'url' => route('operator.content', ['marka' => $brandId]),
            ];
        })->values();

        return [[
            'key' => 'content-plan',
            'kind' => 'icerik',
            'title' => 'Bu haftanın içerik planı',
            'line' => $items->count().' marka · '.$rows->count().' yazı ve güncelleme',
            'count' => $rows->count(),
            'brands' => $items->count(),
            'impact' => null,
            'bulk' => true,
            'items' => $items->all(),
        ]];
    }

    /** "web 30, Google Ads 15, İşletme profili 5" — the channels with a share. */
    public static function splitLine(array $split): string
    {
        $short = ['web' => 'web', 'google_ads' => 'Google Ads', 'meta' => 'Meta', 'gbp' => 'profil'];

        return collect($split)->filter(fn ($n): bool => (int) $n > 0)->map(fn ($n, string $c): string => ($short[$c] ?? $c).' '.(int) $n)->implode(', ');
    }

    /**
     * Desk ids of one package key ("pkg:BRAND.KIND.LANE"), read again (only open rows).
     *
     * @return list<int>
     */
    public function packageIds(string $key): array
    {
        $key = str_starts_with($key, 'pkg:') ? substr($key, 4) : $key;
        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = $this->desk->rows()->filter(fn (array $r): bool => RepairDesk::packageKey($r) === $key);

        return $rows->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
    }
}
