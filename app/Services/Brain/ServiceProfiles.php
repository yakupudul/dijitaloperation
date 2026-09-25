<?php

namespace App\Services\Brain;

use App\Models\ServiceCatalogItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the Brain knows about each global service: its names, matching expressions and the queries operators (or
 * approved proposals) already put under it. Used as the "meaning" of a service for similarity and AI prompts.
 */
final class ServiceProfiles
{
    /** Provenances an operator decided or approved; automatic keyword hits are not treated as ground truth. */
    public const array TRUSTED_PROVENANCE = ['operator', 'brain_review', 'operator_alias', 'keyword_match'];

    /**
     * @param  list<string>|null  $sectors  limit to these sector codes
     * @return Collection<int, array{id: int, sector: string, name: string, names: list<string>, keywords: list<string>, description: string, examples: list<string>}>
     */
    public function all(?array $sectors = null, int $examples = 20): Collection
    {
        $services = ServiceCatalogItem::query()->where('status', 'active')
            ->when($sectors !== null, fn ($q) => $q->whereIn('sector', $sectors))
            ->with(['names', 'matchingKeywords'])->orderBy('id')->get();
        if ($services->isEmpty()) {
            return collect();
        }
        $exampleRows = DB::table('search_query_library_item_service as s')
            ->join('search_query_library_items as q', 'q.id', '=', 's.search_query_library_item_id')
            ->whereIn('s.service_catalog_item_id', $services->pluck('id'))->whereIn('s.provenance', self::TRUSTED_PROVENANCE)
            ->where('q.status', 'active')->whereNull('q.deleted_at')->orderByDesc('s.id')
            ->get(['s.service_catalog_item_id', 'q.canonical_text'])->groupBy('service_catalog_item_id');

        return $services->mapWithKeys(function (ServiceCatalogItem $service) use ($exampleRows, $examples): array {
            $names = $service->names->pluck('raw_label')->map(fn ($n): string => (string) $n)->filter()->unique()->values()->all();
            $primary = (string) ($service->names->firstWhere('is_primary', true)?->raw_label ?? ($names[0] ?? '#'.$service->id));

            return [(int) $service->id => [
                'id' => (int) $service->id,
                'sector' => (string) $service->sector,
                'name' => $primary,
                'names' => $names,
                'keywords' => $service->matchingKeywords->pluck('label')->map(fn ($k): string => (string) $k)->all(),
                'description' => (string) ($service->description ?? ''),
                'examples' => collect($exampleRows->get($service->id, []))->pluck('canonical_text')->map(fn ($t): string => (string) $t)->unique()->take($examples)->values()->all(),
            ]];
        });
    }

    /**
     * The text that represents a service for embeddings: names, expressions, description and examples.
     *
     * @param  array{name: string, names: list<string>, keywords: list<string>, description: string, examples: list<string>}  $profile
     * @return list<string>
     */
    public static function texts(array $profile): array
    {
        return array_values(array_unique(array_filter([
            $profile['name'],
            ...$profile['names'],
            ...array_slice($profile['keywords'], 0, 20),
            ...$profile['examples'],
            $profile['description'] !== '' ? mb_substr($profile['name'].': '.$profile['description'], 0, 400) : null,
        ])));
    }
}
