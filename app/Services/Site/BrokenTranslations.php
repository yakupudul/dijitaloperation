<?php

namespace App\Services\Site;

use App\Ai\Agents\Site\TranslationFixAgent;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\Page;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\BrandSetup\LanguageServices;
use App\Services\Compliance\ForbiddenTerms;
use App\Services\ExternalWrites\ArticleDraft;
use App\Services\ExternalWrites\ContentComplianceGate;
use App\Services\ExternalWrites\WordPressDraftWriter;
use App\Services\Integrations\WordPress\WordPressConnectorClient;
use App\Services\Repair\RepairDesk;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Bozuk çeviri onarımı (2026-10-19: OPC Klinik's Italian treatment pages, published 17 Feb 2026, were written in game
 * slang: "Gameplay", "Win Rate", "Dev Team", "noob", "respawn"). By rules, weekly: a language version of a page whose
 * text is full of game / gamer words while its own source-language version has none is broken. The AI
 * (`site.translation_fix`) writes a faithful translation of the source version (same structure, numbers and links),
 * checked by rules and the sector gate; it waits on the Onarım masası. Approving the row is the operator's one click:
 * the new text goes to WordPress as a draft copy and, once WordPress confirmed it, replaces the page (undoable).
 */
final class BrokenTranslations
{
    public const string TYPE = 'translation_fix';

    public const string DECISION = 'translation-fix';

    /** Game / gamer words a clinic's or a shop's page never needs (whole words, any language version). */
    public const array JARGON = [
        'gameplay', 'win rate', 'dev team', 'noob', 'noobs', 'respawn', 'nerf', 'nerfa', 'nerfare', 'lag', 'laggare', 'cooldown', 'quest', 'overpowered',
        'walkthrough', 'glitch', 'glitches', 'buff', 'buffare', 'debuff', 'spawn', 'spawnino', 'hard reset', 'early access', 'daily grind', 'pre-rendering',
        'junk files', 'level up', 'loot', 'boss fight', 'player', 'players', 'speedrun', 'patch note', 'game over', 'checkpoint', 'check-point',
    ];

    /** A page is broken from this many jargon words (OPC's mildest broken page had 5, every sound page 0-3). */
    public const int MIN_HITS = 5;

    public const int MAX_HTML = 60000;

    public function __construct(private readonly SiteAi $ai) {}

    /** How many jargon words the text holds. */
    public static function hits(string $text): int
    {
        $text = mb_strtolower(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $hits = 0;
        foreach (self::JARGON as $word) {
            $hits += preg_match_all('/(?<![\p{L}\d])'.preg_quote($word, '/').'(?![\p{L}\d])/u', $text);
        }

        return $hits;
    }

    /**
     * The site's broken language versions become desk suggestions (one per page, kept while the page stays broken).
     *
     * @return list<int> suggestion ids that still need their translation
     */
    public function scan(DigitalAsset $site): array
    {
        $main = LanguageServices::mainLanguage($site);
        $pages = Page::query()->where('website_asset_id', $site->id)->whereNotNull('wp_post_id')->whereNotNull('language')
            ->where('language', '!=', $main)->get(['id', 'website_asset_id', 'url', 'language', 'title', 'content_text', 'wp_post_id']);
        $out = [];
        foreach ($pages as $page) {
            $hits = self::hits((string) $page->content_text);
            if ($hits < self::MIN_HITS) {
                continue;
            }
            $source = $this->source($site, $page, $main);
            // The source-language version saying the same is the business's own wording, not a broken translation.
            if ($source === null || self::hits((string) $source->content_text) * 3 >= $hits) {
                continue;
            }
            $suggestion = $this->store($site, $page, $source, $hits);
            if ($suggestion !== null && data_get($suggestion->action, 'proposal') === null && ! isset($suggestion->action['writes'])) {
                $out[] = (int) $suggestion->id;
            }
        }

        return $out;
    }

    /** The page's version in the site's main language (else in English), from the connector's Polylang translations. */
    public function source(DigitalAsset $site, Page $page, string $main): ?Page
    {
        if (! Schema::hasTable('website_cms_object_snapshot')) {
            return null;
        }
        $meta = DB::table('website_cms_object_snapshot')->where('digital_asset_id', $site->id)->where('object_id', (string) $page->wp_post_id)->value('metadata');
        $translations = (array) (json_decode((string) $meta, true)['translations'] ?? []);
        foreach (array_unique([$main, 'en']) as $language) {
            $id = (int) ($translations[$language] ?? 0);
            if ($id > 0 && $id !== (int) $page->wp_post_id && $language !== $page->language) {
                $source = Page::query()->where('website_asset_id', $site->id)->where('wp_post_id', $id)->first();
                if ($source !== null) {
                    return $source;
                }
            }
        }

        return null;
    }

    private function store(DigitalAsset $site, Page $page, Page $source, int $hits): ?Suggestion
    {
        $fingerprint = hash('sha256', self::TYPE.'|'.$page->id);
        $existing = Suggestion::query()->where('brand_id', $site->brand_id)->where('fingerprint', $fingerprint)->first();
        if ($existing !== null && in_array($existing->status, [Suggestion::APPLIED, Suggestion::DISMISSED], true)) {
            return null;
        }
        $suggestion = $existing ?? new Suggestion(['brand_id' => $site->brand_id, 'fingerprint' => $fingerprint, 'first_seen_at' => now(), 'status' => Suggestion::OPEN]);
        $suggestion->fill([
            'channel' => 'search', 'decision_key' => self::DECISION, 'material_hash' => hash('sha256', (string) $page->content_text), 'action_type' => self::TYPE, 'target_type' => 'page', 'target_id' => (int) $page->id, 'page_id' => (int) $page->id,
            'title' => 'Bozuk çeviri: '.strtoupper((string) $page->language).' sayfası', 'priority' => 2,
            'reason' => 'Sayfanın '.strtoupper((string) $page->language).' metni oyun / yazılım argosuyla yazılmış ('.$hits.' kelime: "gameplay", "lag", "noob" gibi); '
                .strtoupper((string) $source->language).' sürümünden düzgün çevirisi hazırlanır.',
            'evidence' => [['kind' => 'jargon', 'source' => (string) $page->url, 'value' => $hits], ['kind' => 'source_page', 'source' => (string) $source->url, 'value' => (string) $source->language]],
            'action' => array_merge((array) ($existing?->action ?? []), ['site_id' => (int) $site->id, 'language' => (string) $page->language, 'source_page_id' => (int) $source->id, 'hits' => $hits]),
            'last_seen_at' => now(),
        ])->save();

        return $suggestion;
    }

    /**
     * The translation, inside a resumable job: "queued" while Claude (MCP) has not answered.
     *
     * @return array{status: string, message?: string} ready | queued | blocked | invalid | not_applicable | error
     */
    public function prepare(Suggestion $suggestion): array
    {
        $page = Page::query()->find($suggestion->page_id);
        $source = Page::query()->find((int) data_get($suggestion->action, 'source_page_id'));
        $site = $page?->website;
        $brand = Brand::query()->find($suggestion->brand_id);
        if ($page === null || $source === null || $site === null || $brand === null || $suggestion->action_type !== self::TYPE || ! SiteScope::aiAllowed($brand)) {
            return ['status' => 'not_applicable'];
        }
        $html = $this->liveHtml($site, [(int) $page->wp_post_id, (int) $source->wp_post_id]);
        $current = $html[(int) $page->wp_post_id] ?? null;
        $from = $html[(int) $source->wp_post_id] ?? null;
        if ($current === null || $from === null) {
            return ['status' => 'not_applicable', 'message' => 'Sayfanın ya da kaynağının metni WordPress\'ten okunamadı (Elementor sayfası olabilir).'];
        }
        $sitePages = Page::query()->where('website_asset_id', $site->id)->where('is_indexable', true)->where('language', $page->language)->orderBy('path')->limit(300)->get(['url', 'title']);
        $terms = ForbiddenTerms::forBrand($brand);
        $result = $this->ai->run(new TranslationFixAgent, [
            'language' => (string) $page->language,
            'page' => ['url' => (string) $page->url, 'title' => $page->title, 'meta_description' => $page->meta_description, 'html' => mb_substr($current, 0, self::MAX_HTML)],
            'source' => ['language' => (string) $source->language, 'url' => (string) $source->url, 'title' => $source->title, 'meta_description' => $source->meta_description,
                'html' => mb_substr($from, 0, self::MAX_HTML)],
            'site_pages' => $sitePages->map(fn (Page $p): array => ['url' => (string) $p->url, 'title' => $p->title])->all(),
            'forbidden' => $terms->phrases(),
        ], 600, 'translation-fix-'.$suggestion->id);
        if ($result['status'] !== 'ready') {
            return ['status' => $result['status']];
        }
        $known = Page::query()->where('website_asset_id', $site->id)->pluck('url')->map(fn ($u): string => (string) $u)->all();
        $new = $this->validated((array) $result['data'], $from, $current, $known, (string) $page->url);
        if ($new === null) {
            return ['status' => 'invalid', 'message' => 'Çeviri kaynağıyla doğrulanamadı (yeni sayı, eksik bölüm ya da hâlâ argo).'];
        }
        $action = (array) $suggestion->action;
        $violations = ContentComplianceGate::blocking(app(ContentComplianceGate::class)->violations($brand, ArticleDraft::fromArray([
            'title' => $new['seo_title'] ?? ($page->title ?: 'Sayfa'), 'html' => $new['html'], 'reference' => 'suggestion-'.$suggestion->id,
            'meta_title' => (string) ($new['seo_title'] ?? ''), 'meta_description' => (string) ($new['meta_description'] ?? ''),
        ])));
        if ($violations !== []) {
            unset($action['proposal']);
            $suggestion->forceFill(['action' => $action + ['proposal_blocked' => ContentComplianceGate::summary($violations)]])->save();

            return ['status' => 'blocked', 'message' => ContentComplianceGate::summary($violations)];
        }
        unset($action['proposal_blocked']);
        $action['proposal'] = [
            'kind' => 'content',
            'current' => array_filter(['seo_title' => $page->title, 'meta_description' => $page->meta_description, 'html' => $current], fn ($v): bool => $v !== null),
            'new' => $new, 'note' => mb_substr(trim((string) data_get($result, 'data.note', '')), 0, 300),
            'prepared_at' => now()->toIso8601String(), 'prompt_version_id' => $result['prompt_version_id'] ?? null,
        ];
        $suggestion->forceFill(['action' => $action])->save();

        return ['status' => 'ready'];
    }

    /**
     * The translation is kept when it is a translation: no slang left, every number in it stands in the source, it is
     * about as long as the source, its links point to the site or the source's own targets, no new scripts.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $siteUrls
     * @return array{html: string, seo_title?: string, meta_description?: string}|null
     */
    public function validated(array $data, string $source, string $current, array $siteUrls, string $pageUrl): ?array
    {
        $html = trim((string) ($data['html'] ?? ''));
        $html = (string) preg_replace_callback('#<(script|style|iframe)\b[^>]*>.*?</\1>#is', fn (array $m): string => str_contains($source, $m[0]) || str_contains($current, $m[0]) ? $m[0] : '', $html);
        $origin = (string) preg_replace('#^(https?://[^/]+).*$#i', '$1', $pageUrl);
        $known = array_flip(array_map(fn (string $u): string => rtrim(mb_strtolower($u), '/'), $siteUrls));
        $html = (string) preg_replace_callback('#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', function (array $m) use ($source, $current, $known, $origin): string {
            $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $absolute = str_starts_with($url, '/') && ! str_starts_with($url, '//') ? $origin.$url : $url;

            return str_starts_with($url, '#') || str_starts_with($url, 'tel:') || str_starts_with($url, 'mailto:') || isset($known[rtrim(mb_strtolower($absolute), '/')])
                || str_contains($source, $m[1]) || str_contains($current, $m[1]) ? $m[0] : $m[2];
        }, $html);
        $text = trim(strip_tags($html));
        $sourceText = trim(strip_tags($source));
        if ($text === '' || self::hits($html) >= 2 || mb_strlen($text) < mb_strlen($sourceText) * 0.6 || mb_strlen($text) > mb_strlen($sourceText) * 1.6) {
            return null;
        }
        $digits = ' '.(string) preg_replace('/[^\d]+/', ' ', str_replace(['.', ','], '', $sourceText)).' ';
        preg_match_all('/\d+(?:[.,]\d+)*/u', $text, $numbers);
        foreach ($numbers[0] as $number) {
            if (! str_contains($digits, ' '.str_replace(['.', ','], '', $number).' ')) {
                return null;
            }
        }
        $new = ['html' => $html];
        foreach (['seo_title' => 70, 'meta_description' => 320] as $field => $max) {
            $value = trim((string) ($data[$field] ?? ''));
            if ($value !== '' && self::hits($value) === 0) {
                $new[$field] = mb_substr($value, 0, $max);
            }
        }

        return $new;
    }

    /**
     * Desk approval (Onarım masası, the operator's one click): SEO fields at once, the text as a WordPress draft copy
     * that replaces the page as soon as WordPress confirmed it (draftFinished).
     *
     * @return list<int> write ids
     */
    public function approve(Suggestion $suggestion, User $user): array
    {
        $ids = app(ChangeApplier::class)->approve($suggestion, $user);
        $suggestion->forceFill(['action' => array_merge((array) $suggestion->fresh()?->action, ['go_live_by' => (int) $user->id])])->save();

        return $ids;
    }

    /** The draft copy of an approved translation fix reached WordPress: it goes live; a failed one returns to the desk. */
    public function draftFinished(ExternalWriteAction $draft): void
    {
        $suggestion = $draft->suggestion_id !== null ? Suggestion::query()->find($draft->suggestion_id) : null;
        if ($suggestion === null || $suggestion->action_type !== self::TYPE || $suggestion->status !== Suggestion::APPROVED) {
            return;
        }
        $action = (array) $suggestion->action;
        if (in_array($draft->status, ['failed', 'partial'], true)) {
            unset($action['writes'], $action['go_live_by']);
            $action[RepairDesk::DESK_ERROR] = mb_substr('Taslak kopya yazılamadı: '.(string) $draft->error, 0, 200);
            $suggestion->forceFill(['status' => Suggestion::OPEN, 'applied_at' => null, 'resolved_at' => null, 'action' => $action])->save();

            return;
        }
        $user = User::query()->find((int) ($action['go_live_by'] ?? $draft->requested_by));
        if ($draft->status !== 'succeeded' || $user === null || isset($action['live_write_id'])) {
            return;
        }
        try {
            $id = app(ChangeApplier::class)->publishDraft($suggestion, $user);
        } catch (Throwable $exception) {
            report($exception);

            return;
        }
        $suggestion->forceFill(['status' => Suggestion::APPLIED, 'action' => array_merge($action, ['live_write_id' => $id])])->save();
    }

    /** @return Collection<int, Suggestion> open broken-translation rows still without a translation */
    public static function unprepared(): Collection
    {
        return Suggestion::query()->where('action_type', self::TYPE)->whereIn('status', [Suggestion::OPEN, Suggestion::RECHECK])
            ->whereNull('action->proposal')->whereNull('action->proposal_blocked')->get();
    }

    /**
     * @param  list<int>  $postIds
     * @return array<int, string> post id → its live body (classic posts and pages only)
     */
    private function liveHtml(DigitalAsset $site, array $postIds): array
    {
        try {
            $connection = app(WordPressDraftWriter::class)->connection((int) $site->id);
            $payload = app(WordPressConnectorClient::class)->snapshot($connection, 'content', 1, count($postIds), $postIds);
        } catch (Throwable) {
            return [];
        }
        $out = [];
        foreach ((array) ($payload['records'] ?? []) as $record) {
            // An Elementor page shows its builder data, not post_content: a rewritten body would never appear.
            if (is_array($record) && in_array((int) ($record['object_id'] ?? 0), $postIds, true) && data_get($record, 'builder.provider') !== 'elementor') {
                $html = trim((string) ($record['content_raw'] ?? $record['content_rendered'] ?? ''));
                if ($html !== '') {
                    $out[(int) $record['object_id']] = $html;
                }
            }
        }

        return $out;
    }
}
