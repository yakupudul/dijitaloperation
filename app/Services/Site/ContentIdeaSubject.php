<?php

namespace App\Services\Site;

use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\BrandContentIdea;
use App\Models\Cluster;
use App\Models\ContentIdea;
use App\Models\DigitalAsset;
use App\Models\Page;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * One row of the İçerik fikirleri tab: the main idea (the cluster, a brand cluster row) or an extra idea of the pool
 * (a brand content idea row) on one site — what the recipe, "AI ile geliştir" and "AI ile üret" need of either.
 */
final class ContentIdeaSubject
{
    public const string MAIN = 'main';

    public const string EXTRA = 'extra';

    private function __construct(
        public readonly string $kind,
        public readonly Model $row,
        public readonly DigitalAsset $site,
        public readonly Cluster $cluster,
        public readonly ?ContentIdea $idea,
    ) {}

    public static function find(int $siteId, string $kind, int $id): ?self
    {
        $site = DigitalAsset::query()->find($siteId);
        if ($site === null) {
            return null;
        }
        if ($kind === self::MAIN) {
            $row = BrandClusterPage::query()->with(['cluster.mainQuery', 'cluster.service.primaryName', 'page'])->where('website_asset_id', $siteId)->find($id);

            return $row?->cluster !== null ? new self(self::MAIN, $row, $site, $row->cluster, null) : null;
        }
        $row = BrandContentIdea::query()->with(['idea.cluster.mainQuery', 'idea.cluster.service.primaryName', 'page'])->where('website_asset_id', $siteId)->find($id);

        return $row?->idea?->cluster !== null ? new self(self::EXTRA, $row, $site, $row->idea->cluster, $row->idea) : null;
    }

    public function brand(): ?Brand
    {
        return Brand::query()->with('customer', 'sectorCategory')->find($this->row->brand_id);
    }

    public function page(): ?Page
    {
        return $this->row->page;
    }

    public function title(): string
    {
        return $this->idea?->title ?? (string) $this->cluster->name;
    }

    /** service | guide | faq | comparison | location */
    public function type(): string
    {
        return $this->idea?->type ?? (string) $this->cluster->page_type;
    }

    /** @return list<array{text: string, kind: string}> */
    public function gaps(): array
    {
        return array_values((array) $this->row->gaps);
    }

    /** The main idea's page (an extra idea links to it). */
    public function mainPage(): ?Page
    {
        if ($this->kind === self::MAIN) {
            return null;
        }
        $pageId = BrandClusterPage::query()->where('brand_id', $this->row->brand_id)->where('website_asset_id', $this->site->id)
            ->where('cluster_id', $this->cluster->id)->whereNotNull('page_id')->orderBy('id')->value('page_id');

        return $pageId !== null ? Page::query()->find($pageId) : null;
    }

    /** Nightly score of a main row (extra ideas are not scored). */
    public function score(): ?object
    {
        return $this->kind === self::MAIN ? DB::table('cluster_page_scores')->where('brand_cluster_page_id', $this->row->id)->first() : null;
    }

    /** @return array<string, mixed> status params of the row's operations */
    public function params(): array
    {
        return ['kind' => $this->kind, 'id' => (int) $this->row->id];
    }
}
