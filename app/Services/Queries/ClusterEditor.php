<?php

namespace App\Services\Queries;

use App\Enums\OfferingStatus;
use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\BrandOffering;
use App\Models\Cluster;
use App\Models\ClusterQuery;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Query;
use App\Services\Site\ClusterPageMapper;
use App\Services\Site\SiteScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one place for operator edits of clusters, used by Sorgular and by the brand website screen.
 *
 * Shared edits (fields, approve, split, merge, add / move / remove queries, delete) change the SECTOR + SERVICE library:
 * when brands use the cluster's service they need the "Ortak kütüphaneyi düzenle" confirmation. Every saved shared
 * edit locks the cluster ("AI ile kümele" never touches it again) and raises its version. Moves stay within one sector +
 * service. Brand-only edits ("Bu markaya özel düzenle") live on brand_cluster_pages — target query override, URL,
 * excluded for the brand — and never change the shared cluster.
 */
final class ClusterEditor
{
    private const int MAX_LIST = 12;

    private const int MAX_REPRESENTATIVES = 3;

    public function __construct(
        private readonly QueryNormalizer $normalizer,
        private readonly QueryPipeline $pipeline,
    ) {}

    /**
     * Brands whose active services include the cluster's service (they see every shared edit).
     *
     * @return Collection<int, Brand>
     */
    public function affectedBrands(Cluster $cluster): Collection
    {
        $ids = BrandOffering::query()->where('service_catalog_item_id', $cluster->service_id)
            ->where('status', OfferingStatus::Active->value)->distinct()->pluck('brand_id');

        return Brand::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name', 'sector_id', 'languages']);
    }

    public function rename(Cluster $cluster, string $name, bool $confirmed = false): Cluster
    {
        return $this->update($cluster, ['name' => $name], $confirmed);
    }

    /**
     * Shared fields: name, intent, page_type, user_need, main_query_id, representative_query_ids, subtopics, exclusions
     * (lists as arrays or one item per line). Only the given keys change.
     *
     * @param  array<string, mixed>  $fields
     */
    public function update(Cluster $cluster, array $fields, bool $confirmed = false): Cluster
    {
        $values = [];
        if (array_key_exists('name', $fields)) {
            $name = trim((string) $fields['name']);
            if (mb_strlen($name) < 2 || mb_strlen($name) > 200) {
                throw ValidationException::withMessages(['clusterForm.name' => 'Küme adı 2–200 karakter olmalı.']);
            }
            $values['name'] = $name;
        }
        if (array_key_exists('intent', $fields)) {
            if (! in_array($fields['intent'], Cluster::INTENTS, true)) {
                throw ValidationException::withMessages(['clusterForm.intent' => 'Geçersiz niyet.']);
            }
            $values['intent'] = $fields['intent'];
        }
        if (array_key_exists('page_type', $fields)) {
            if (! in_array($fields['page_type'], Cluster::PAGE_TYPES, true)) {
                throw ValidationException::withMessages(['clusterForm.page_type' => 'Geçersiz sayfa tipi.']);
            }
            $values['page_type'] = $fields['page_type'];
        }
        if (array_key_exists('user_need', $fields)) {
            $need = trim((string) $fields['user_need']);
            if (mb_strlen($need) > 500) {
                throw ValidationException::withMessages(['clusterForm.user_need' => 'Kullanıcı ihtiyacı en fazla 500 karakter.']);
            }
            $values['user_need'] = $need !== '' ? $need : null;
        }
        foreach (['subtopics', 'exclusions'] as $key) {
            if (array_key_exists($key, $fields)) {
                $values[$key] = self::lines($fields[$key]);
            }
        }
        $members = ClusterQuery::query()->where('cluster_id', $cluster->id)->pluck('query_id')->map(fn ($id): int => (int) $id)->all();
        if (array_key_exists('main_query_id', $fields)) {
            $main = is_numeric($fields['main_query_id']) ? (int) $fields['main_query_id'] : null;
            if ($main === null || ! in_array($main, $members, true)) {
                throw ValidationException::withMessages(['clusterForm.main_query_id' => 'Ana sorgu kümedeki bir sorgu olmalı.']);
            }
            $values['main_query_id'] = $main;
        }
        if (array_key_exists('representative_query_ids', $fields)) {
            $main = $values['main_query_id'] ?? (int) $cluster->main_query_id;
            $ids = array_values(array_unique(array_map('intval', array_filter((array) $fields['representative_query_ids'], 'is_numeric'))));
            if (array_diff($ids, $members) !== []) {
                throw ValidationException::withMessages(['clusterForm.representative_query_ids' => 'Temsil sorguları kümedeki sorgular olmalı.']);
            }
            $values['representative_query_ids'] = array_slice(array_values(array_diff($ids, [$main])), 0, self::MAX_REPRESENTATIVES);
        }
        $this->guard($cluster, $confirmed);

        return $this->saved($cluster, $values);
    }

    public function approve(Cluster $cluster, bool $confirmed = false): Cluster
    {
        $this->guard($cluster, $confirmed);

        return $this->saved($cluster, ['approved' => true]);
    }

    /**
     * Adds one query to the cluster by its text: an existing query (unclustered or from another cluster of the same
     * service) moves in; an unknown text becomes an operator query marked "önerilen" until real data arrives. The query
     * takes the cluster's service (manual, locked).
     */
    public function addQuery(Cluster $cluster, string $text, bool $confirmed = false): int
    {
        $normalized = $this->normalizer->normalize($text, (int) $cluster->sector_id);
        if (mb_strlen($normalized) < 2) {
            throw ValidationException::withMessages(['addQueryText' => 'Sorgu yazın (filtre sepetinden sonra en az 2 karakter).']);
        }
        $this->guard($cluster, $confirmed);

        return DB::transaction(function () use ($cluster, $normalized): int {
            $hash = QueryNormalizer::hash($normalized);
            $query = Query::query()->where('text_hash', $hash)->first();
            if ($query === null) {
                $query = Query::query()->create([
                    'text' => $normalized, 'text_hash' => $hash, 'sector_id' => $cluster->sector_id, 'service_id' => $cluster->service_id,
                    'assignment' => 'manual', 'locked' => true, 'is_suggested' => true,
                ]);
            } else {
                $query->forceFill(['service_id' => $cluster->service_id, 'sector_id' => $query->sector_id ?? $cluster->sector_id,
                    'assignment' => 'manual', 'locked' => true, 'hidden' => false])->save();
            }
            $link = ClusterQuery::query()->where('query_id', $query->id)->first();
            if ($link?->cluster_id === $cluster->id) {
                throw ValidationException::withMessages(['addQueryText' => 'Sorgu zaten bu kümede.']);
            }
            $source = $link !== null ? Cluster::query()->find($link->cluster_id) : null;
            if ($link !== null) {
                $link->forceFill(['cluster_id' => $cluster->id])->save();
            } else {
                ClusterQuery::query()->create(['cluster_id' => $cluster->id, 'query_id' => $query->id, 'is_suggested' => (bool) $query->is_suggested]);
            }
            if ($source !== null) {
                $this->saved($source, []);
            }
            $this->saved($cluster, []);

            return (int) $query->id;
        });
    }

    /**
     * Removes queries from the cluster: real queries stay (kümesiz), suggested ones go.
     *
     * @param  list<int>  $queryIds
     */
    public function removeQueries(Cluster $cluster, array $queryIds, bool $confirmed = false): int
    {
        $links = ClusterQuery::query()->where('cluster_id', $cluster->id)->whereIn('query_id', $queryIds)->get();
        if ($links->isEmpty()) {
            throw ValidationException::withMessages(['selectedClusterQueries' => 'Çıkarmak için kümeden sorgu seçin.']);
        }
        $this->guard($cluster, $confirmed);

        return DB::transaction(function () use ($cluster, $links): int {
            ClusterQuery::query()->whereIn('id', $links->pluck('id'))->delete();
            Query::query()->whereIn('id', $links->where('is_suggested', true)->pluck('query_id'))->where('is_suggested', true)->delete();
            $this->saved($cluster, []);

            return $links->count();
        });
    }

    /**
     * Moves queries (of clusters of the same sector + service) into the target cluster.
     *
     * @param  list<int>  $queryIds
     */
    public function move(array $queryIds, Cluster $target, bool $confirmed = false): int
    {
        $this->guard($target, $confirmed);

        return DB::transaction(function () use ($queryIds, $target): int {
            $links = ClusterQuery::query()->with('cluster')->whereIn('query_id', $queryIds)->get()
                ->filter(fn (ClusterQuery $link): bool => $link->cluster !== null && $link->cluster->sector_id === $target->sector_id
                    && $link->cluster->service_id === $target->service_id && $link->cluster_id !== $target->id);
            if ($links->isEmpty()) {
                return 0;
            }
            $sources = $links->pluck('cluster_id')->unique()->all();
            ClusterQuery::query()->whereIn('id', $links->pluck('id'))->update(['cluster_id' => $target->id, 'updated_at' => now()]);
            foreach (Cluster::query()->whereIn('id', $sources)->get() as $source) {
                $this->saved($source, []);
            }
            $this->saved($target, []);

            return $links->count();
        });
    }

    /**
     * Selected queries of the cluster become a new (locked) cluster with the same intent / page type.
     *
     * @param  list<int>  $queryIds
     */
    public function split(Cluster $cluster, array $queryIds, string $name, bool $confirmed = false): Cluster
    {
        $ids = ClusterQuery::query()->where('cluster_id', $cluster->id)->whereIn('query_id', $queryIds)->pluck('query_id')->map(fn ($id): int => (int) $id)->all();
        if ($ids === []) {
            throw ValidationException::withMessages(['selectedClusterQueries' => 'Ayırmak için kümeden sorgu seçin.']);
        }
        if (count($ids) === $cluster->clusterQueries()->count()) {
            throw ValidationException::withMessages(['selectedClusterQueries' => 'Tüm sorgular seçili; en az biri kümede kalmalı.']);
        }
        $name = trim($name) !== '' ? trim($name) : $cluster->name.' (2)';
        if (mb_strlen($name) > 200) {
            throw ValidationException::withMessages(['splitName' => 'Küme adı 2–200 karakter olmalı.']);
        }
        $this->guard($cluster, $confirmed);

        return DB::transaction(function () use ($cluster, $ids, $name): Cluster {
            $new = Cluster::query()->create([
                'sector_id' => $cluster->sector_id, 'service_id' => $cluster->service_id, 'name' => $name,
                'intent' => $cluster->intent, 'user_need' => $cluster->user_need, 'page_type' => $cluster->page_type, 'locked' => true,
            ]);
            $this->move($ids, $new, true);

            return $new->refresh();
        });
    }

    /**
     * Other clusters (same sector + service) are merged into the target and deleted.
     *
     * @param  list<int>  $otherIds
     */
    public function merge(Cluster $target, array $otherIds, bool $confirmed = false): int
    {
        $this->guard($target, $confirmed);

        return DB::transaction(function () use ($target, $otherIds): int {
            $others = Cluster::query()->whereIn('id', $otherIds)->whereKeyNot($target->id)
                ->where('sector_id', $target->sector_id)->where('service_id', $target->service_id)->get();
            $subtopics = (array) $target->subtopics;
            $exclusions = (array) $target->exclusions;
            foreach ($others as $other) {
                ClusterQuery::query()->where('cluster_id', $other->id)->update(['cluster_id' => $target->id, 'updated_at' => now()]);
                $subtopics = array_merge($subtopics, (array) $other->subtopics);
                $exclusions = array_merge($exclusions, (array) $other->exclusions);
                $other->delete();
            }
            $this->saved($target, ['subtopics' => self::lines($subtopics), 'exclusions' => self::lines($exclusions)]);

            return $others->count();
        });
    }

    public function delete(Cluster $cluster, bool $confirmed = false): void
    {
        $this->guard($cluster, $confirmed);
        $brands = $this->affectedBrands($cluster)->pluck('id')->all();
        DB::transaction(function () use ($cluster): void {
            $suggested = ClusterQuery::query()->where('cluster_id', $cluster->id)->where('is_suggested', true)->pluck('query_id');
            $cluster->delete();
            Query::query()->whereIn('id', $suggested)->where('is_suggested', true)->delete();
        });
        foreach ($brands as $brandId) {
            $this->pipeline->brandTargets((int) $brandId);
        }
    }

    // ── Bu markaya özel ─────────────────────────────────────────────────────

    /**
     * Brand-only edit of a cluster (the shared cluster never changes): target query override (blank = automatic), URL
     * (a page of the brand's website; the row is locked) and excluded for the brand. Without a row yet, one is made on
     * the brand's (page's) website.
     *
     * @param  array{target_query_override?: ?string, page_id?: ?int, excluded?: bool}  $values
     */
    public function brandEdit(Cluster $cluster, Brand $brand, array $values): void
    {
        $page = null;
        if (($values['page_id'] ?? null) !== null) {
            $page = Page::query()->whereKey((int) $values['page_id'])
                ->whereIn('website_asset_id', DigitalAsset::query()->where('brand_id', $brand->id)->select('id'))->first();
            if ($page === null) {
                throw ValidationException::withMessages(['brandPage' => 'Sayfa bu markanın sitesinde değil.']);
            }
        }
        $siteId = $page?->website_asset_id ?? BrandClusterPage::query()->where('brand_id', $brand->id)->where('cluster_id', $cluster->id)->value('website_asset_id')
            ?? DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->orderBy('id')->value('id');
        if ($siteId === null) {
            throw ValidationException::withMessages(['brandId' => 'Markanın web sitesi yok.']);
        }
        $site = DigitalAsset::query()->findOrFail((int) $siteId);
        $language = $page !== null ? ($page->language ?? SiteScope::primaryLanguage($site)) : SiteScope::primaryLanguage($site);
        $rows = BrandClusterPage::query()->where('brand_id', $brand->id)->where('cluster_id', $cluster->id)->get();
        $pageRow = $page === null ? null : $rows->first(fn (BrandClusterPage $r): bool => $r->website_asset_id === $site->id && $r->language === $language);
        if ($rows->isEmpty() || ($page !== null && $pageRow === null)) {
            $pageRow = BrandClusterPage::query()->create(['brand_id' => $brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $site->id,
                'language' => $language, 'state' => $page !== null ? 'insufficient_data' : 'no_page']);
            $rows->push($pageRow);
        }
        DB::transaction(function () use ($rows, $pageRow, $page, $values): void {
            foreach ($rows as $row) {
                $this->brandRow($row, array_intersect_key($values, ['target_query_override' => true, 'excluded' => true])
                    + ($page !== null && $row->is($pageRow) ? ['page_id' => (int) $page->id] : []), false);
            }
        });
        $this->pipeline->brandTargets((int) $brand->id);
    }

    /**
     * One brand row (website screen › Kümeler & Sayfalar, and brandEdit): URL / state (locked, manual), target query
     * override, excluded for the brand.
     *
     * @param  array{page_id?: ?int, state?: string, target_query_override?: ?string, excluded?: bool}  $values
     */
    public function brandRow(BrandClusterPage $row, array $values, bool $refresh = true): BrandClusterPage
    {
        $fill = [];
        if (array_key_exists('page_id', $values) || array_key_exists('state', $values)) {
            $state = (string) ($values['state'] ?? $row->state);
            if (! in_array($state, BrandClusterPage::STATES, true)) {
                throw ValidationException::withMessages(['state' => 'Geçersiz durum.']);
            }
            $pageId = array_key_exists('page_id', $values) ? $values['page_id'] : $row->page_id;
            if ($pageId !== null && ! Page::query()->whereKey($pageId)->where('website_asset_id', $row->website_asset_id)->exists()) {
                throw ValidationException::withMessages(['page' => 'Sayfa bu sitede değil.']);
            }
            $fill += ['page_id' => $pageId, 'state' => $state, 'decided_by' => 'manual', 'locked' => true, 'reason' => 'Elle seçildi.'];
        }
        if (array_key_exists('target_query_override', $values)) {
            $override = trim((string) $values['target_query_override']);
            if (mb_strlen($override) > 300) {
                throw ValidationException::withMessages(['target' => 'Hedef sorgu en fazla 300 karakter.']);
            }
            $cluster = Cluster::query()->with('mainQuery')->find($row->cluster_id);
            $brand = Brand::query()->find($row->brand_id);
            $fill['target_query_override'] = $override !== '' ? $override : null;
            $fill['target_query'] = $override !== '' ? $override
                : ($cluster !== null && $brand !== null ? ClusterPageMapper::targetQuery($cluster, SiteScope::targetArea($brand)) : $row->target_query);
        }
        if (array_key_exists('excluded', $values)) {
            $fill['excluded'] = (bool) $values['excluded'];
        }
        if (array_key_exists('extra_page_ids', $values)) {
            // Additional URLs of the same cluster on this site (one target URL is the default, not a rule).
            $extra = array_values(array_diff(array_unique(array_map('intval', (array) $values['extra_page_ids'])), [(int) ($fill['page_id'] ?? $row->page_id), 0]));
            if ($extra !== [] && Page::query()->whereIn('id', $extra)->where('website_asset_id', $row->website_asset_id)->count() !== count($extra)) {
                throw ValidationException::withMessages(['page' => 'Sayfa bu sitede değil.']);
            }
            $fill['extra_page_ids'] = $extra !== [] ? $extra : null;
            $fill += ['decided_by' => 'manual', 'locked' => true];
        }
        $row->forceFill($fill)->save();
        if ($refresh) {
            $this->pipeline->brandTargets((int) $row->brand_id);
        }

        return $row;
    }

    // ── İç ───────────────────────────────────────────────────────────────────

    /** A shared cluster used by brands changes only with the "Ortak kütüphaneyi düzenle" confirmation. */
    private function guard(Cluster $cluster, bool $confirmed): void
    {
        if ($confirmed) {
            return;
        }
        $brands = $this->affectedBrands($cluster);
        if ($brands->isNotEmpty()) {
            throw ValidationException::withMessages(['confirmShared' => sprintf('Ortak küme %d markayı etkiliyor (%s): "Ortak kütüphaneyi düzenle" onayı gerekli.',
                $brands->count(), $brands->pluck('name')->take(5)->implode(', '))]);
        }
    }

    /**
     * Saves a shared edit: locked, version +1, main / representative queries kept inside the cluster, brand targets of
     * the affected brands refreshed.
     *
     * @param  array<string, mixed>  $values
     */
    private function saved(Cluster $cluster, array $values): Cluster
    {
        $cluster->forceFill($values + ['locked' => true, 'version' => (int) $cluster->version + 1])->save();
        $this->repair($cluster);
        foreach ($this->affectedBrands($cluster) as $brand) {
            $this->pipeline->brandTargets((int) $brand->id);
        }

        return $cluster;
    }

    /** Main / representative queries must stay members of the cluster. */
    private function repair(Cluster $cluster): void
    {
        $members = ClusterQuery::query()->where('cluster_id', $cluster->id)->pluck('query_id')->map(fn ($id): int => (int) $id)->all();
        $main = in_array((int) $cluster->main_query_id, $members, true) ? (int) $cluster->main_query_id : null;
        $main ??= ($best = Query::query()->whereIn('id', $members)->where('is_suggested', false)->orderByDesc('impressions')->orderBy('id')->value('id')) !== null ? (int) $best : null;
        $current = array_map('intval', (array) $cluster->representative_query_ids);
        $representatives = array_values(array_diff(array_intersect($current, $members), [(int) $main]));
        if ($main !== ($cluster->main_query_id !== null ? (int) $cluster->main_query_id : null) || $representatives !== $current) {
            $cluster->forceFill(['main_query_id' => $main, 'representative_query_ids' => $representatives])->save();
        }
    }

    /**
     * Trimmed unique short items (max 12 × 160 characters) from a list or one item per line.
     *
     * @return list<string>
     */
    public static function lines(mixed $value): array
    {
        $items = is_array($value) ? $value : preg_split('/\R/u', (string) $value);
        $clean = [];
        foreach ((array) $items as $item) {
            $item = mb_substr(trim((string) $item), 0, 160);
            if ($item !== '' && ! in_array($item, $clean, true)) {
                $clean[] = $item;
            }
        }

        return array_slice($clean, 0, self::MAX_LIST);
    }
}
