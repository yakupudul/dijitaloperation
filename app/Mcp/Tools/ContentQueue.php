<?php

namespace App\Mcp\Tools;

use App\Models\Suggestion;
use App\Services\Site\SiteOperations;
use App\Services\Site\SiteSuggestionTypes;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Lists content suggestions (article titles) of active brands, newest first: id, brand, site, title, status (open = waiting for the operator, approved = the operator approved the title), whether an article draft exists, is blocked by a sector rule, and the draft status line. Default: approved titles without a draft, the ones request-article can write.')]
#[IsReadOnly]
class ContentQueue extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'brand_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in([Suggestion::OPEN, Suggestion::APPROVED, Suggestion::APPLIED, 'all'])],
            'without_draft' => ['nullable', 'boolean'],
        ]);
        $status = $validated['status'] ?? Suggestion::APPROVED;
        $withoutDraft = (bool) ($validated['without_draft'] ?? $status === Suggestion::APPROVED);
        $items = Suggestion::query()->with('brand:id,name')->where('action_type', SiteSuggestionTypes::CONTENT)
            ->whereHas('brand', fn ($q) => $q->operational())
            ->when($validated['brand_id'] ?? null, fn ($q, int $id) => $q->where('brand_id', $id))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status), fn ($q) => $q->whereIn('status', [Suggestion::OPEN, Suggestion::APPROVED, Suggestion::APPLIED]))
            ->orderByDesc('id')->limit(200)->get()
            ->filter(fn (Suggestion $s): bool => ! $withoutDraft || ! isset(((array) $s->action)['article']))
            ->take(50);

        return Response::json(['suggestions' => $items->map(function (Suggestion $s): array {
            $action = (array) $s->action;
            $siteId = (int) ($action['site_id'] ?? 0);

            return [
                'id' => $s->id, 'brand_id' => $s->brand_id, 'brand' => $s->brand?->name, 'site_id' => $siteId, 'title' => $s->title, 'status' => $s->status,
                'page_type' => $action['page_type'] ?? 'blog', 'kind' => $action['kind'] ?? 'new', 'target_url' => $action['target_url'] ?? null,
                'has_draft' => isset($action['article']), 'blocked' => isset($action['article_blocked']),
                'draft_status' => $siteId > 0 ? SiteOperations::line(SiteOperations::status($siteId, SiteOperations::WRITE_ARTICLE, ['suggestion_id' => $s->id])) : null,
            ];
        })->values()->all()]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'brand_id' => $schema->integer()->description('Only this brand.'),
            'status' => $schema->string()->enum([Suggestion::OPEN, Suggestion::APPROVED, Suggestion::APPLIED, 'all'])->description('Default approved.'),
            'without_draft' => $schema->boolean()->description('Only titles without an article draft (default true for approved).'),
        ];
    }
}
