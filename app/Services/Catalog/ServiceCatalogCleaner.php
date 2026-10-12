<?php

namespace App\Services\Catalog;

use App\Enums\OfferingStatus;
use App\Models\ServiceCatalogItem;
use App\Models\User;
use App\Services\BrandSetup\BrandAutofill;
use App\Services\Queries\KeywordInsights;
use App\Services\Queries\QueryPipeline;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-time catalog clean-up (`moxdop:services:clean`, idempotent):
 *  1. Services whose name fails ServiceNameQuality are archived (never hard-deleted) unless an operator created them
 *     (created_by is a person, not the nightly autofill) or a brand locked them; their queries go back to triage.
 *  2. Duplicates of one sector and one language are merged by a fold key (Turkish fold, no spaces / punctuation,
 *     a trailing "tedavisi / treatment / uygulaması / hizmeti" dropped while ≥2 characters stay) into the service
 *     with the most brands, then queries, then keywords. Queries, clusters, brand offerings, keywords, pending /
 *     review lines and brand candidates move to it; the other names become its aliases. No cross-sector or TR↔EN merge.
 */
final class ServiceCatalogCleaner
{
    private const array TRAILING = ['tedavisi', 'treatment', 'uygulamasi', 'hizmeti'];

    private const array ENGLISH = ['treatment', 'therapy', 'surgery', 'removal', 'service', 'services', 'transplant', 'whitening', 'the', 'and', 'of', 'for', 'with', 'teeth', 'tooth', 'hair', 'lift', 'filling', 'cleaning', 'care'];

    public function __construct(private readonly ServiceCatalogService $catalog) {}

    /**
     * @return array{checked: int, archived: int, protected: int, groups: int, merged: int, released: int, archived_names: list<string>, protected_names: list<string>, merges: list<array{into: string, from: list<string>}>}
     */
    public function clean(bool $dryRun = false): array
    {
        $summary = ['checked' => 0, 'archived' => 0, 'protected' => 0, 'groups' => 0, 'merged' => 0, 'released' => 0,
            'archived_names' => [], 'protected_names' => [], 'merges' => []];
        $systemUser = User::query()->where('email', BrandAutofill::SYSTEM_EMAIL)->value('id');
        $services = $this->activeServices();
        $summary['checked'] = $services->count();
        $locked = DB::table('brand_offerings')->where('locked', true)->whereNotNull('service_catalog_item_id')
            ->distinct()->pluck('service_catalog_item_id')->map(fn ($id): int => (int) $id)->flip();

        foreach ($services as $id => $service) {
            if (ServiceNameQuality::problem($service['label']) === null) {
                continue;
            }
            $operator = $service['created_by'] !== null && $service['created_by'] !== ($systemUser !== null ? (int) $systemUser : null);
            if ($operator || isset($locked[$id])) {
                $summary['protected']++;
                $summary['protected_names'][] = $service['label'];

                continue;
            }
            $summary['archived']++;
            $summary['archived_names'][] = $service['label'];
            $services->forget($id);
            if (! $dryRun) {
                DB::table('brand_offerings')->where('service_catalog_item_id', $id)->where('locked', false)
                    ->update(['status' => OfferingStatus::Archived->value, 'updated_at' => now()]);
                $this->catalog->setStatus(ServiceCatalogItem::query()->findOrFail($id), 'archived');
            }
        }

        $groups = $services->groupBy(fn (array $service): string => ($service['sector'] ?? '').'|'.$service['language'].'|'.self::foldKey($service['label']))
            ->filter(fn (Collection $group, string $key): bool => $group->count() > 1 && ! str_ends_with($key, '|'));
        foreach ($groups as $group) {
            $ranked = $group->sortBy([['brands', 'desc'], ['queries', 'desc'], ['keywords', 'desc'], ['id', 'asc']])->values();
            $survivor = $ranked->first();
            $losers = $ranked->slice(1)->values();
            $summary['groups']++;
            $summary['merged'] += $losers->count();
            $summary['merges'][] = ['into' => $survivor['label'], 'from' => $losers->pluck('label')->all()];
            if (! $dryRun) {
                foreach ($losers as $loser) {
                    $this->merge((int) $loser['id'], (int) $survivor['id']);
                }
            }
        }
        if (! $dryRun) {
            $summary['released'] = QueryPipeline::releaseServices();
            KeywordInsights::forgetSuggestions();
        }
        Log::info('services.clean', ['dry_run' => $dryRun] + $summary);

        return $summary;
    }

    /** "İmplant Tedavisi", "implant-tedavisi", "Implant" → "implant"; "Saç Ekimi" → "sacekimi". */
    public static function foldKey(string $label): string
    {
        $folded = SeoText::fold($label);
        foreach (self::TRAILING as $suffix) {
            if (str_ends_with($folded, ' '.$suffix) && mb_strlen(str_replace(' ', '', mb_substr($folded, 0, -mb_strlen($suffix) - 1))) >= 2) {
                $folded = mb_substr($folded, 0, -mb_strlen($suffix) - 1);
                break;
            }
        }

        return str_replace(' ', '', $folded);
    }

    /** tr | en: the name's locale when set, else English marker words without Turkish letters → en, otherwise tr. */
    public static function language(string $label, ?string $locale): string
    {
        $locale = strtolower((string) $locale);
        if (str_starts_with($locale, 'en')) {
            return 'en';
        }
        if (str_starts_with($locale, 'tr') || preg_match('/[çğıöşüÇĞİÖŞÜ]/u', $label) === 1) {
            return 'tr';
        }

        return array_intersect(explode(' ', SeoText::fold($label)), self::ENGLISH) !== [] ? 'en' : 'tr';
    }

    /** @return Collection<int, array{id: int, label: string, sector: ?string, language: string, created_by: ?int, brands: int, queries: int, keywords: int}> */
    private function activeServices(): Collection
    {
        $brands = DB::table('brand_offerings')->whereNotNull('service_catalog_item_id')->where('status', OfferingStatus::Active->value)
            ->groupBy('service_catalog_item_id')->selectRaw('service_catalog_item_id as id, count(distinct brand_id) as total')->pluck('total', 'id');
        $queries = DB::table('queries')->whereNotNull('service_id')->groupBy('service_id')->selectRaw('service_id as id, count(*) as total')->pluck('total', 'id');
        $keywords = DB::table('service_matching_keywords')->groupBy('service_catalog_item_id')->selectRaw('service_catalog_item_id as id, count(*) as total')->pluck('total', 'id');

        return DB::table('service_catalog_items as s')
            ->join('service_catalog_names as n', fn ($join) => $join->on('n.service_catalog_item_id', '=', 's.id')->where('n.is_primary', true)->where('n.is_active', true))
            ->where('s.status', 'active')->whereNull('s.deleted_at')->orderBy('s.id')
            ->get(['s.id', 's.sector', 's.created_by', 'n.raw_label', 'n.locale'])
            ->unique('id')
            ->mapWithKeys(fn (object $row): array => [(int) $row->id => [
                'id' => (int) $row->id, 'label' => (string) $row->raw_label, 'sector' => $row->sector !== null && $row->sector !== '' ? (string) $row->sector : null,
                'language' => self::language((string) $row->raw_label, $row->locale), 'created_by' => $row->created_by !== null ? (int) $row->created_by : null,
                'brands' => (int) ($brands[$row->id] ?? 0), 'queries' => (int) ($queries[$row->id] ?? 0), 'keywords' => (int) ($keywords[$row->id] ?? 0),
            ]]);
    }

    private function merge(int $loserId, int $survivorId): void
    {
        $brandIds = [];
        DB::transaction(function () use ($loserId, $survivorId, &$brandIds): void {
            $now = now();
            DB::table('queries')->where('service_id', $loserId)->update(['service_id' => $survivorId, 'cluster_checked_at' => null, 'updated_at' => $now]);
            DB::table('clusters')->where('service_id', $loserId)->update(['service_id' => $survivorId, 'updated_at' => $now]);
            DB::table('pending_queries')->where('service_id', $loserId)->update(['service_id' => $survivorId]);
            DB::table('query_review_items')->where('from_service_id', $loserId)->update(['from_service_id' => $survivorId]);
            DB::table('query_review_items')->where('to_service_id', $loserId)->update(['to_service_id' => $survivorId]);
            DB::table('brand_service_candidates')->where('service_catalog_item_id', $loserId)->update(['service_catalog_item_id' => $survivorId]);
            $survivorBrands = DB::table('brand_offerings')->where('service_catalog_item_id', $survivorId)->pluck('brand_id')->map(fn ($id): int => (int) $id)->flip();
            foreach (DB::table('brand_offerings')->where('service_catalog_item_id', $loserId)->get(['id', 'brand_id']) as $offering) {
                $brandIds[] = (int) $offering->brand_id;
                isset($survivorBrands[(int) $offering->brand_id])
                    ? DB::table('brand_offerings')->where('id', $offering->id)->update(['status' => OfferingStatus::Archived->value, 'updated_at' => $now])
                    : DB::table('brand_offerings')->where('id', $offering->id)->update(['service_catalog_item_id' => $survivorId, 'updated_at' => $now]);
            }
            $taken = DB::table('service_matching_keywords')->where('service_catalog_item_id', $survivorId)->pluck('normalized_key')->flip();
            foreach (DB::table('service_matching_keywords')->where('service_catalog_item_id', $loserId)->get(['id', 'normalized_key']) as $keyword) {
                isset($taken[$keyword->normalized_key])
                    ? DB::table('service_matching_keywords')->where('id', $keyword->id)->delete()
                    : DB::table('service_matching_keywords')->where('id', $keyword->id)->update(['service_catalog_item_id' => $survivorId, 'updated_at' => $now]);
            }
            // The merged label stays findable: it becomes an alias of the survivor.
            DB::table('service_catalog_names')->where('service_catalog_item_id', $loserId)
                ->update(['service_catalog_item_id' => $survivorId, 'is_primary' => false, 'name_kind' => 'alias', 'updated_at' => $now]);
            DB::table('service_catalog_items')->where('id', $loserId)->update(['status' => 'archived', 'deleted_at' => $now, 'updated_at' => $now]);
        });
        $survivor = ServiceCatalogItem::query()->find($survivorId);
        if ($survivor !== null && $brandIds !== []) {
            $this->catalog->refreshBrandContexts($survivor, array_values(array_unique($brandIds)));
        }
    }
}
