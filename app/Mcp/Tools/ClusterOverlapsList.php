<?php

namespace App\Mcp\Tools;

use App\Models\Brand;
use App\Models\Cluster;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Site\ClusterOverlaps;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Onarım masası küme çakışmaları ("301 ile birleştir", "Ayrıştır", "Ana sayfayı gözden geçir"): per overlap the cluster, the recommendation and its basis, the overlapping page and the main page (url, title, H1, category, words, summary), the page\'s share of the cluster\'s Search Console impressions, a 301 error, and the Ayrıştır rewrite state. With `id` the full current and proposed page versions. Check each before overlap-act. Read-only.')]
#[IsReadOnly]
class ClusterOverlapsList extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'brand_id' => ['nullable', 'integer'], 'recommendation' => ['nullable', 'in:redirect,differentiate,review'],
            'status' => ['nullable', 'in:open,working,all'], 'id' => ['nullable', 'integer'], 'limit' => ['nullable', 'integer', 'min:1', 'max:300'],
        ]);
        $query = Suggestion::query()->where('decision_key', ClusterOverlaps::DECISION)->orderBy('brand_id')->orderBy('id');
        if (isset($validated['id'])) {
            $query->whereKey((int) $validated['id']);
        } else {
            match ($validated['status'] ?? 'open') {
                'open' => $query->whereIn('status', [Suggestion::OPEN, Suggestion::RECHECK, Suggestion::SNOOZED]),
                'working' => $query->where('status', Suggestion::APPROVED),
                default => null,
            };
        }
        if (isset($validated['brand_id'])) {
            $query->where('brand_id', (int) $validated['brand_id']);
        }
        $rows = $query->get()->filter(fn (Suggestion $s): bool => ! isset($validated['recommendation']) || data_get($s->action, 'recommendation') === $validated['recommendation'])->values();
        $total = $rows->count();
        $rows = $rows->take((int) ($validated['limit'] ?? 100));
        $full = isset($validated['id']);
        $pageIds = $rows->flatMap(fn (Suggestion $s): array => [(int) $s->page_id, (int) data_get($s->action, 'main_page_id')])->filter()->unique()->all();
        $pages = Page::query()->whereIn('id', $pageIds ?: [0])->get(['id', 'url', 'title', 'h1', 'category', 'word_count', 'content_summary', 'wp_post_id', 'website_asset_id'])->keyBy('id');
        $brands = Brand::query()->whereIn('id', $rows->pluck('brand_id')->unique()->all() ?: [0])->pluck('name', 'id');
        $sites = DigitalAsset::query()->whereIn('id', $rows->map(fn (Suggestion $s): int => (int) data_get($s->action, 'site_id'))->unique()->all() ?: [0])->pluck('name', 'id');
        $clusters = Cluster::query()->whereIn('id', $rows->pluck('cluster_id')->filter()->unique()->all() ?: [0])->pluck('name', 'id');
        $page = fn (?Page $p): ?array => $p === null ? null : ['id' => $p->id, 'url' => $p->url, 'title' => $p->title, 'h1' => $p->h1, 'category' => $p->category,
            'words' => $p->word_count, 'from_wordpress' => $p->wp_post_id !== null, 'summary' => mb_substr((string) $p->content_summary, 0, $full ? 1200 : 300)];

        return Response::json([
            'total' => $total,
            'by_recommendation' => $rows->countBy(fn (Suggestion $s): string => (string) data_get($s->action, 'recommendation')),
            'items' => $rows->map(function (Suggestion $s) use ($pages, $brands, $sites, $clusters, $page, $full): array {
                $action = (array) $s->action;
                $proposal = (array) ($action['proposal'] ?? []);

                return array_filter([
                    'id' => $s->id, 'status' => $s->status, 'brand' => $brands[$s->brand_id] ?? null, 'brand_id' => $s->brand_id,
                    'site' => $sites[(int) ($action['site_id'] ?? 0)] ?? null, 'cluster' => $clusters[(int) $s->cluster_id] ?? null,
                    'recommendation' => $action['recommendation'] ?? null, 'basis' => $action['basis'] ?? null,
                    'impression_share' => round((float) ($action['share'] ?? 0), 3), 'reason' => $s->reason,
                    'page' => $page($pages->get((int) $s->page_id)), 'main_page' => $page($pages->get((int) ($action['main_page_id'] ?? 0))),
                    'merge_error' => $action['merge_error'] ?? null, 'merge_sent_at' => $action['merge_sent_at'] ?? null,
                    'rewrite' => $action['rewrite'] ?? null,
                    'proposal' => $proposal === [] ? null : array_filter([
                        'note' => $proposal['note'] ?? null, 'written_by' => $proposal['written_by'] ?? null,
                        'seo_title' => data_get($proposal, 'new.seo_title'), 'meta_description' => data_get($proposal, 'new.meta_description'),
                        'internal_links' => data_get($proposal, 'new.internal_links'),
                        'body_rewritten' => filled(data_get($proposal, 'new.html')),
                        'current_seo_title' => data_get($proposal, 'current.seo_title'), 'current_meta_description' => data_get($proposal, 'current.meta_description'),
                        'current_html' => $full ? data_get($proposal, 'current.html') : null, 'new_html' => $full ? data_get($proposal, 'new.html') : null,
                    ], fn (mixed $v): bool => $v !== null),
                    'proposal_blocked' => $action['proposal_blocked'] ?? null,
                ], fn (mixed $v): bool => $v !== null);
            })->values()->all(),
        ]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'brand_id' => $schema->integer()->description('Only this brand.'),
            'recommendation' => $schema->string()->enum(['redirect', 'differentiate', 'review'])->description('redirect = 301 ile birleştir, differentiate = Ayrıştır, review = Ana sayfayı gözden geçir.'),
            'status' => $schema->string()->enum(['open', 'working', 'all'])->description('open (default): waiting on the desk; working: approved and still being written; all.'),
            'id' => $schema->integer()->description('One overlap, with the full current and proposed page versions.'),
            'limit' => $schema->integer()->description('Rows (default 100, max 300).'),
        ];
    }
}
