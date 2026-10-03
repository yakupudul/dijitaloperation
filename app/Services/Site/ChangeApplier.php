<?php

namespace App\Services\Site;

use App\Ai\Agents\Site\ApplyChangeAgent;
use App\Models\Brand;
use App\Models\Cluster;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\Page;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Compliance\ForbiddenTerms;
use App\Services\ExternalWrites\ArticleDraft;
use App\Services\ExternalWrites\ContentComplianceGate;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\ExternalWrites\WordPressDraftWriter;
use App\Services\Integrations\WordPress\WordPressConnectorClient;
use App\Services\Outcomes\OutcomeTracker;
use App\Services\SeoTasks\SeoText;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * "AI ile yap": for an applicable suggestion the AI (`site.apply_change`) writes the new version of the affected fields
 * (SEO title / description, internal links, schema) or of the page HTML (section, FAQ block, rewrite). The proposal is
 * validated (links only to site pages, numbers from the page, valid schema JSON) and passes the sector compliance gate
 * before it is shown next to the current version. Onayla → the existing approved WordPress update path (SEO fixes, or
 * content draft copy → "Canlıya al"), undoable; applied_at + Search Console 28-day baseline are stored.
 */
final class ChangeApplier
{
    public const int MAX_HTML = 30000;

    public function __construct(
        private readonly SiteAi $ai,
        private readonly BrandMemoryService $memory,
    ) {}

    /** @return array{status: string, message?: string} status: ready | not_applicable | not_operational | invalid | blocked | no_provider | error */
    public function prepare(Suggestion $suggestion): array
    {
        $page = $suggestion->page_id !== null ? Page::query()->find($suggestion->page_id) : null;
        $site = $page?->website;
        $brand = Brand::query()->with('customer', 'sectorCategory')->find($suggestion->brand_id);
        if ($page === null || $site === null || ! SiteSuggestionTypes::applicable((string) $suggestion->action_type)) {
            return ['status' => 'not_applicable'];
        }
        if (! SiteScope::aiAllowed($brand)) {
            return ['status' => 'not_operational'];
        }
        // A competitor suggestion on an existing page is written like a missing topic (a section of the page).
        $type = $suggestion->action_type === 'rakip' ? 'missing_topic' : (string) $suggestion->action_type;
        $html = in_array($type, ['missing_topic', 'conversion', 'wrong_intent'], true) ? $this->currentHtml($site, $page) : null;
        $sitePages = Page::query()->where('website_asset_id', $site->id)->where('is_indexable', true)->whereKeyNot($page->id)->orderBy('path')->limit(300)->get(['url', 'title']);
        $context = $this->memory->contextFor($brand, [(int) $page->id], $suggestion->cluster_id !== null ? [(int) $suggestion->cluster_id] : []);
        $result = $this->ai->run(new ApplyChangeAgent, [
            'suggestion' => ['type' => $type, 'title' => $suggestion->title, 'reason' => $suggestion->reason, 'evidence' => $suggestion->evidence],
            'page' => ['url' => $page->url, 'title' => $page->title, 'meta_description' => $page->meta_description, 'h1' => $page->h1,
                'headings' => array_values((array) $page->headings), 'content' => $page->aiText(12000)],
            'current_html' => $html !== null ? mb_substr($html, 0, self::MAX_HTML) : null,
            'site_pages' => $sitePages->map(fn (Page $p): array => ['url' => (string) $p->url, 'title' => $p->title])->all(),
            'technical' => PageTechnical::of($page),
            'forbidden' => ForbiddenTerms::forBrand($brand)->phrases(),
            'brand' => $context['profile'], 'notes' => $context['notes'], 'standards' => $context['standards'], 'decisions' => $context['decisions'],
        ] + $this->clusterPack($suggestion, $brand), 300);
        if ($result['status'] !== 'ready') {
            return ['status' => $result['status']];
        }
        $proposal = $this->validated($result['data'], $page, $html, $sitePages->pluck('url')->map(fn ($u): string => (string) $u)->all());
        if ($proposal === null) {
            return ['status' => 'invalid', 'message' => 'AI çıktısı sayfa verisiyle doğrulanamadı.'];
        }
        // Sector compliance before anything is shown.
        $gate = app(ContentComplianceGate::class);
        $violations = ContentComplianceGate::blocking($gate->violations($brand, ArticleDraft::fromArray([
            'title' => $proposal['new']['seo_title'] ?? ($page->title ?: 'Sayfa'), 'html' => $proposal['new']['html'] ?? '<p>'.e((string) ($proposal['new']['meta_description'] ?? $page->title ?? 'Sayfa')).'</p>',
            'reference' => 'suggestion-'.$suggestion->id, 'meta_title' => (string) ($proposal['new']['seo_title'] ?? ''), 'meta_description' => (string) ($proposal['new']['meta_description'] ?? ''),
        ])));
        $action = (array) $suggestion->action;
        if ($violations === [] && isset($proposal['new']['html']) && $suggestion->cluster_id !== null) {
            // Kopya kontrolü: only what the AI added is compared with other brands' pages of the cluster.
            $copy = CopyCheck::check((string) $proposal['new']['html'], ClusterBenchmarks::otherBrandTexts((int) $suggestion->cluster_id, (int) $brand->id),
                (string) $page->content_text.' '.strip_tags((string) $html));
            if (! $copy['ok']) {
                unset($action['proposal']);
                $suggestion->forceFill(['action' => $action + ['proposal_blocked' => CopyCheck::message($copy)]])->save();

                return ['status' => 'blocked', 'message' => CopyCheck::message($copy)];
            }
        }
        if ($violations !== []) {
            unset($action['proposal']);
            $suggestion->forceFill(['action' => $action + ['proposal_blocked' => ContentComplianceGate::summary($violations)]])->save();

            return ['status' => 'blocked', 'message' => ContentComplianceGate::summary($violations)];
        }
        unset($action['proposal_blocked'], $action['proposal_warnings']);
        $warnings = ForbiddenTerms::forBrand($brand)->warnings(implode(' . ', [(string) ($proposal['new']['seo_title'] ?? ''), (string) ($proposal['new']['meta_description'] ?? ''),
            strip_tags((string) ($proposal['new']['html'] ?? ''))]));
        if ($warnings !== []) {
            $action['proposal_warnings'] = 'Uyarı (yasaklı ifade, uyar): «'.implode('», «', $warnings).'»';
        }
        $suggestion->forceFill(['action' => array_merge($action, ['proposal' => $proposal + ['prepared_at' => now()->toIso8601String(), 'prompt_version_id' => $result['prompt_version_id']]])])->save();

        return ['status' => 'ready'];
    }

    /**
     * "AI ile geliştir" (İçerik fikirleri): the cluster the gaps come from — gaps, the stored SEO analizi recipe, the
     * extra idea's title, top queries, AI questions and (local needs only) the brand's service areas.
     *
     * @return array<string, mixed>
     */
    private function clusterPack(Suggestion $suggestion, Brand $brand): array
    {
        $action = (array) $suggestion->action;
        $gaps = (array) ($action['gaps'] ?? []);
        $recipe = (array) ($action['recipe'] ?? []);
        $cluster = ($gaps !== [] || $recipe !== []) && $suggestion->cluster_id !== null ? Cluster::query()->with('clusterQueries.searchQuery')->find($suggestion->cluster_id) : null;
        if ($cluster === null) {
            return [];
        }

        return ['cluster' => array_filter([
            'name' => (string) $cluster->name,
            'idea' => $action['idea_title'] ?? null,
            'gaps' => array_values(array_map(fn (array $g): string => (string) ($g['text'] ?? ''), $gaps)),
            'recipe' => $recipe !== [] ? array_filter([
                'steps' => array_values(array_map(fn (array $s): string => trim(($s['where'] ?? '') !== '' ? $s['where'].': '.$s['action'] : (string) $s['action']), (array) ($recipe['steps'] ?? []))),
                'seo_title' => $recipe['seo_title'] ?? null, 'meta_description' => $recipe['meta_description'] ?? null,
            ]) : null,
            'queries' => $cluster->clusterQueries->where('is_suggested', false)->map(fn ($q): string => (string) $q->searchQuery?->text)->filter()->take(25)->values()->all(),
            'ai_questions' => ClusterAudit::aiQuestions($cluster, $brand),
            'service_areas' => ClusterAudit::serviceAreas($cluster, $brand) ?: null,
            'benchmarks' => app(ClusterBenchmarks::class)->for($cluster, (int) $brand->id) ?: null,
        ], fn (mixed $v): bool => $v !== null)];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $siteUrls
     * @return array{kind: string, current: array<string, mixed>, new: array<string, mixed>, note: string}|null
     */
    public function validated(array $data, Page $page, ?string $currentHtml, array $siteUrls): ?array
    {
        $evidence = new SiteEvidence([...$siteUrls, (string) $page->url], [], [(string) $page->content_text]);
        preg_match_all('/\d+(?:[.,]\d+)?/', (string) $page->content_text.' '.$page->title.' '.$page->meta_description, $numbers);
        foreach (array_slice($numbers[0], 0, 500) as $number) {
            $evidence->addNumber($number);
        }
        $new = [];
        $title = trim((string) ($data['seo_title'] ?? ''));
        if ($title !== '' && $title !== $page->title && $evidence->grounded($title)) {
            $new['seo_title'] = mb_substr($title, 0, 120);
        }
        $description = trim((string) ($data['meta_description'] ?? ''));
        if ($description !== '' && $description !== $page->meta_description && $evidence->grounded($description)) {
            $new['meta_description'] = mb_substr($description, 0, 320);
        }
        $links = [];
        foreach (array_slice((array) ($data['internal_links'] ?? []), 0, 5) as $link) {
            $url = is_array($link) ? trim((string) ($link['url'] ?? '')) : '';
            $anchor = is_array($link) ? trim((string) ($link['anchor'] ?? '')) : '';
            if ($anchor !== '' && $url !== '' && $evidence->knowsUrl($url) && SeoText::urlKey($url) !== SeoText::urlKey((string) $page->url)) {
                $links[] = ['anchor' => mb_substr($anchor, 0, 120), 'url' => $url];
            }
        }
        if ($links !== []) {
            $new['internal_links'] = $links;
        }
        $schema = trim((string) ($data['schema_json'] ?? ''));
        if ($schema !== '' && is_array(json_decode($schema, true))) {
            $new['schema_json'] = $schema;
        }
        $html = trim((string) ($data['html'] ?? ''));
        if ($html !== '' && $currentHtml !== null) {
            $html = (string) preg_replace('#<(script|style|iframe)\b[^>]*>.*?</\1>#is', '', $html);
            // Links to anything but the site's own pages are unlinked (text kept).
            $html = (string) preg_replace_callback('#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', fn (array $m): string => str_starts_with($m[1], '#') || $evidence->knowsUrl($m[1]) || str_starts_with($m[1], 'tel:') || str_starts_with($m[1], 'mailto:') ? $m[0] : $m[2], $html);
            $visible = trim(strip_tags($html));
            if ($visible !== '' && $evidence->grounded($visible) && $html !== $currentHtml) {
                $new['html'] = $html;
            }
        }
        if ($new === []) {
            return null;
        }

        return [
            'kind' => isset($new['html']) ? 'content' : 'fields',
            'current' => array_filter(['seo_title' => $page->title, 'meta_description' => $page->meta_description, 'html' => isset($new['html']) ? $currentHtml : null], fn ($v): bool => $v !== null),
            'new' => $new,
            'note' => mb_substr(trim((string) ($data['note'] ?? '')), 0, 300),
        ];
    }

    /**
     * Onayla: the approved proposal goes to WordPress through the existing approved-update path (undoable).
     *
     * @return list<int> external write action ids
     */
    public function approve(Suggestion $suggestion, User $user): array
    {
        $proposal = (array) data_get($suggestion->action, 'proposal', []);
        $page = $suggestion->page_id !== null ? Page::query()->find($suggestion->page_id) : null;
        $site = $page?->website;
        if ($proposal === [] || $page === null || $site === null) {
            throw ValidationException::withMessages(['write' => 'Önce "AI ile yap" ile yeni sürümü hazırla.']);
        }
        if ($suggestion->status === Suggestion::APPLIED || ($suggestion->applied_at !== null && data_get($suggestion->action, 'writes') !== null)) {
            throw ValidationException::withMessages(['write' => 'Bu öneri zaten gönderildi.']);
        }
        if ($page->wp_post_id === null) {
            throw ValidationException::withMessages(['write' => 'Sayfa WordPress’ten gelmiyor; değişikliği elle uygula.']);
        }
        $writes = app(ExternalWriteService::class);
        $reference = 'suggestion-'.$suggestion->id;
        $new = (array) ($proposal['new'] ?? []);
        $changes = [];
        foreach (['seo_title' => 'seo_title', 'meta_description' => 'seo_description'] as $field => $type) {
            if (filled($new[$field] ?? null)) {
                $changes[] = ['type' => $type, 'object_id' => (int) $page->wp_post_id, 'reference' => $reference.'-'.$type, 'value' => (string) $new[$field]];
            }
        }
        foreach ((array) ($new['internal_links'] ?? []) as $i => $link) {
            $changes[] = ['type' => 'internal_link', 'object_id' => (int) $page->wp_post_id, 'reference' => $reference.'-link-'.$i, 'value' => $link];
        }
        if (filled($new['schema_json'] ?? null)) {
            $changes[] = ['type' => 'schema', 'object_id' => (int) $page->wp_post_id, 'reference' => $reference.'-schema', 'value' => (string) $new['schema_json']];
        }
        $ids = [];
        if ($changes !== []) {
            $ids[] = (int) $writes->requestSiteFixes($user, $site, $changes, $suggestion)->id;
        }
        if (filled($new['html'] ?? null)) {
            $ids[] = (int) $writes->requestContentDraft($user, $site, ['object_id' => (int) $page->wp_post_id, 'title' => (string) ($page->h1 ?: $page->title ?: 'Sayfa'), 'html' => (string) $new['html'], 'reference' => $reference], $suggestion)->id;
        }
        $suggestion->forceFill([
            'status' => filled($new['html'] ?? null) ? Suggestion::APPROVED : Suggestion::APPLIED,
            'applied_at' => now(), 'outcome' => null, 'measured_at' => null, 'resolved_by' => $user->id, 'resolved_at' => now(),
            'action' => array_merge((array) $suggestion->action, ['writes' => [...(array) data_get($suggestion->action, 'writes', []), ...$ids]]),
        ]);
        $suggestion->forceFill(['baseline' => app(OutcomeTracker::class)->baseline($suggestion)])->save();
        $this->memory->recordDecision($suggestion, 'onaylandı', 'AI ile yap → WordPress');

        return $ids;
    }

    /** Content draft copy ready in WordPress → "Canlıya al" (second approval, undoable). */
    public function publishDraft(Suggestion $suggestion, User $user): int
    {
        $draft = ExternalWriteAction::query()->where('suggestion_id', $suggestion->id)->where('action', ExternalWriteAction::ACTION_CONTENT_DRAFT)
            ->where('status', 'succeeded')->orderByDesc('id')->first();
        $site = $draft !== null ? DigitalAsset::query()->find($draft->digital_asset_id) : null;
        if ($draft === null || $site === null) {
            throw ValidationException::withMessages(['write' => 'WordPress taslak kopyası henüz hazır değil.']);
        }
        $action = app(ExternalWriteService::class)->requestContentApply($user, $site, (int) ($draft->result['post_id'] ?? 0), $suggestion);
        app(OutcomeTracker::class)->apply($suggestion, $user);

        return (int) $action->id;
    }

    /** The live page HTML from the WordPress Connector (null when the site has none or it fails). */
    private function currentHtml(DigitalAsset $site, Page $page): ?string
    {
        if ($page->wp_post_id === null) {
            return null;
        }
        try {
            $connection = app(WordPressDraftWriter::class)->connection((int) $site->id);
            $payload = app(WordPressConnectorClient::class)->snapshot($connection, 'content', 1, 1, [(int) $page->wp_post_id]);
            foreach ((array) ($payload['records'] ?? []) as $record) {
                if (is_array($record) && (int) ($record['object_id'] ?? 0) === (int) $page->wp_post_id) {
                    $html = (string) ($record['content_raw'] ?? $record['content_rendered'] ?? '');

                    return trim($html) !== '' ? $html : null;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }
}
