<?php

namespace App\Services\Catalog;

use App\Models\ServiceCatalogItem;
use App\Models\ServiceMatchingKeyword;
use App\Services\SeoTasks\SeoText;
use App\Support\Options\LocationOptions;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ServiceKeywordService
{
    /** Folded words too generic to assign a query to one service on their own. */
    private const array GENERIC = ['fiyat', 'fiyati', 'fiyatlari', 'ucret', 'ucretleri', 'ameliyat', 'ameliyati', 'operasyon', 'tedavi', 'tedavisi', 'tedavileri',
        'estetik', 'estetigi', 'cerrahi', 'klinik', 'klinigi', 'doktor', 'doktoru', 'uzman', 'uzmani', 'hastane', 'merkez', 'merkezi', 'hizmet', 'hizmeti',
        'nedir', 'nasil', 'yorum', 'yorumlar', 'oncesi sonrasi', 'en iyi', 'surgery', 'clinic', 'cost', 'price', 'doctor', 'treatment', 'hospital'];

    public static function isGeneric(string $label): bool
    {
        return in_array(LocationOptions::fold($label), self::GENERIC, true);
    }

    public function replace(ServiceCatalogItem $service, string $text): void
    {
        $keywords = [];
        foreach (preg_split('/[\r\n,;]+/u', $text) ?: [] as $label) {
            $label = trim($label);
            $key = LocationOptions::fold($label);
            if ($key === '') {
                continue;
            }
            if (mb_strlen($label) > 255 || mb_strlen($key) > 255) {
                throw ValidationException::withMessages(['matching_words' => 'Her ifade en fazla 255 karakter olabilir.']);
            }
            $keywords[$key] = $label;
        }
        if (count($keywords) > 200) {
            throw ValidationException::withMessages(['matching_words' => 'Bir hizmete en fazla 200 ifade ekleyebilirsiniz.']);
        }
        DB::transaction(function () use ($service, $keywords): void {
            $this->lockScope($service);
            foreach ($this->conflicts($service, array_keys($keywords)) as $key => $other) {
                throw ValidationException::withMessages(['matching_words' => self::conflictMessage($keywords[$key], $other)]);
            }
            $service->matchingKeywords()->delete();
            foreach ($keywords as $key => $label) {
                ServiceMatchingKeyword::query()->create(['service_catalog_item_id' => $service->id, 'label' => $label, 'normalized_key' => $key]);
            }
        });
    }

    /**
     * Add matching expressions without touching the existing ones (operator edits stay). Locations
     * are stripped so the service stays reusable for brands in other places.
     *
     * @param  list<string>  $labels
     * @return list<string> labels actually added
     */
    public function append(ServiceCatalogItem $service, array $labels): array
    {
        $existing = $service->matchingKeywords()->pluck('label', 'normalized_key')->all();
        $added = [];
        foreach ($labels as $label) {
            $label = trim(LocationOptions::strip((string) $label)['text']);
            $key = LocationOptions::fold($label);
            if (mb_strlen($key) < 3 || mb_strlen($label) > 255 || isset($existing[$key]) || in_array($key, self::GENERIC, true)
                || $this->conflicts($service, [$key]) !== []) {
                continue;
            }
            $existing[$key] = $label;
            $added[] = $label;
        }
        if ($added === [] || count($existing) > 200) {
            return [];
        }
        $this->replace($service, implode("\n", $existing));

        return $added;
    }

    /**
     * Adds one matching keyword (Sorgular › Eşleme kelimeleri). A keyword is unique within the sector.
     *
     * @throws ValidationException
     */
    public function add(ServiceCatalogItem $service, string $label): ServiceMatchingKeyword
    {
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? '');
        $key = LocationOptions::fold($label);
        if ($key === '' || mb_strlen($label) > 255) {
            throw ValidationException::withMessages(['keyword' => 'Kelime 1–255 karakter olmalı.']);
        }

        return DB::transaction(function () use ($service, $label, $key): ServiceMatchingKeyword {
            $this->lockScope($service);
            if ($service->matchingKeywords()->where('normalized_key', $key)->exists()) {
                throw ValidationException::withMessages(['keyword' => 'Bu kelime bu hizmette zaten var.']);
            }
            foreach ($this->conflicts($service, [$key]) as $other) {
                throw ValidationException::withMessages(['keyword' => self::conflictMessage($label, $other)]);
            }
            if ($service->matchingKeywords()->count() >= 200) {
                throw ValidationException::withMessages(['keyword' => 'Bir hizmete en fazla 200 ifade ekleyebilirsiniz.']);
            }

            return ServiceMatchingKeyword::query()->create(['service_catalog_item_id' => $service->id, 'label' => $label, 'normalized_key' => $key]);
        });
    }

    /**
     * Keys already used by ANOTHER service of the same scope: key => that service's name. Scope = the sector plus the
     * services without a sector (global); a service without a sector is global and checked against every service.
     *
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    public function conflicts(ServiceCatalogItem $service, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        return ServiceMatchingKeyword::query()
            ->join('service_catalog_items as s', 's.id', '=', 'service_matching_keywords.service_catalog_item_id')
            ->whereNull('s.deleted_at')->where('s.id', '!=', $service->id)
            ->where(fn (Builder $q): Builder => $this->inScope($q, $service, 's.sector'))
            ->whereIn('service_matching_keywords.normalized_key', $keys)
            ->get(['service_matching_keywords.normalized_key', 's.id'])
            ->mapWithKeys(fn ($row): array => [(string) $row->normalized_key => (string) (ServiceCatalogItem::query()->with('primaryName')->find($row->id)?->primaryName?->raw_label ?? '#'.$row->id)])
            ->all();
    }

    /**
     * Race-safe uniqueness: every service of the scope is locked (id order, no deadlock) before the check, so two
     * concurrent adds in one sector run one after the other.
     */
    private function lockScope(ServiceCatalogItem $service): void
    {
        ServiceCatalogItem::query()->withTrashed()->where(fn (Builder $q): Builder => $this->inScope($q->orWhere('id', $service->id), $service, 'sector'))
            ->orderBy('id')->lockForUpdate()->pluck('id');
    }

    private function inScope(Builder $query, ServiceCatalogItem $service, string $column): Builder
    {
        if (blank($service->sector)) {
            return $query->orWhereRaw('1 = 1');
        }

        return $query->orWhere($column, $service->sector)->orWhereNull($column)->orWhere($column, '');
    }

    private static function conflictMessage(string $label, string $service): string
    {
        return sprintf('"%s" bu sektörde zaten "%s" hizmetinin eşleme kelimesi. Bir kelime sektörde tek hizmete ait olabilir.', $label, $service);
    }

    public function matches(string $text, array $ids, ?Collection $words = null): array
    {
        return ($words ?? ServiceMatchingKeyword::query()->whereIn('service_catalog_item_id', $ids)->get())
            ->filter(fn ($word): bool => SeoText::matchesPhrase($text, (string) $word->normalized_key))
            ->pluck('service_catalog_item_id')->unique()->values()->all();
    }
}
