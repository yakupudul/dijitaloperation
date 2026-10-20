<?php

namespace App\Services\Work;

use App\Jobs\SendReadyContentJob;
use App\Jobs\Site\TopUpContentPoolJob;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\Page;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\SeoTasks\SeoText;
use App\Services\Site\ArticleImagePicker;
use App\Services\Site\ContentPlanner;
use App\Services\Site\ContentPosts;
use App\Services\Site\SiteDiff;
use App\Services\Site\SiteOperations;
use App\Services\Site\SiteSuggestions;
use App\Services\Site\SiteSuggestionTypes;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Genel işler › Web site SEO içerikler (yakup, 2026-10-03): one box per website with its content ideas in four
 * steps — Yazılacak (title waits, or is being written), Okunacak (article written: read it, pick its image, send it),
 * Taslakta (sent, a draft or scheduled on the site) and Yayında (published, with its Search Console numbers); the
 * last two from the site itself (yakup, 2026-10-12). "Yaz" approves the title and starts the writer in one click; "Oku" opens the
 * article; "Taslak gönder" sends it (with its translations) as WordPress drafts. A site whose pages use several
 * languages shows them, and an idea can be written in any of them (the source language picked before writing,
 * further languages as linked translations).
 */
final class ContentBoard
{
    public const array STEPS = ['yazilacak' => 'Yazılacak', 'okunacak' => 'Gönderime hazır', 'taslakta' => 'Taslakta', 'yayinda' => 'Yayında'];

    /** Sent articles stay on Taslakta / Yayında this long. */
    public const int SENT_DAYS = 180;

    /** WordPress post states in the operator's words. */
    public const array POST_STATES = ['draft' => 'Sitede taslak', 'pending' => 'Sitede incelemede', 'future' => 'Yayın planlandı', 'publish' => 'Yayında',
        'trash' => 'Sitede çöpte', 'private' => 'Sitede gizli', 'missing' => 'Sitede bulunamadı'];

    public const array LANGUAGE_LABELS = [
        'tr' => 'Türkçe', 'en' => 'İngilizce', 'de' => 'Almanca', 'ar' => 'Arapça', 'ru' => 'Rusça', 'fr' => 'Fransızca', 'es' => 'İspanyolca',
        'nl' => 'Hollandaca', 'it' => 'İtalyanca', 'fa' => 'Farsça', 'az' => 'Azerice', 'uk' => 'Ukraynaca', 'pl' => 'Lehçe', 'ro' => 'Romence',
    ];

    /** Ideas shown per step in one box (the rest on the site's İçerik tab). */
    public const int PER_STEP = 8;

    /** Ideas per site in a Genel işler step (the rest on the site's İçerik tab). */
    public const int QUEUE_PER_SITE = 30;

    public const int WRITE_ALL_MAX = 25;

    private const int LIMIT = 2000;

    public function __construct(private readonly SiteSuggestions $siteSuggestions, private readonly ContentPlanner $planner, private readonly ContentScore $scorer,
        private readonly ContentPosts $posts, private readonly ArticleImagePicker $images) {}

    /**
     * @return Collection<int, array{site: DigitalAsset, brand: ?string, languages: array<string, int>, steps: array<string, list<array<string, mixed>>>, counts: array<string, int>, urgent: int, url: string}>
     */
    public function boxes(?int $brandId = null, ?int $siteId = null, int $perStep = self::PER_STEP): Collection
    {
        $items = $this->query($brandId)->when($siteId !== null, fn (Builder $q): Builder => $q->where('action->site_id', $siteId))->with(['brand:id,name', 'cluster:id,name', 'page:id,title,url'])->orderBy('priority')->orderByDesc('id')->limit(self::LIMIT)->get();
        $scores = $this->scorer->forSuggestions($items);
        $postIds = $this->posts->postIds($items->filter(fn (Suggestion $s): bool => isset(((array) $s->action)['article_write_id'])));
        $siteIds = $items->map(fn (Suggestion $s): int => (int) data_get($s->action, 'site_id'))->filter()->unique()->values();
        $sites = DigitalAsset::query()->whereIn('id', $siteIds->all() ?: [0])->get()->keyBy('id');
        $languages = DB::table('pages')->whereIn('website_asset_id', $siteIds->all() ?: [0])->whereNotNull('language')
            ->groupBy('website_asset_id', 'language')->selectRaw('website_asset_id, language, count(*) as n')->orderByDesc('n')->get()
            ->groupBy('website_asset_id')->map(fn (Collection $rows): array => $rows->mapWithKeys(fn (object $r): array => [strtolower((string) $r->language) => (int) $r->n])->all());

        return $items->groupBy(fn (Suggestion $s): int => (int) data_get($s->action, 'site_id'))
            ->filter(fn (Collection $rows, int $siteId): bool => $sites->has($siteId))
            ->map(function (Collection $rows, int $siteId) use ($sites, $languages, $perStep, $scores, $postIds): array {
                $site = $sites->get($siteId);
                $siteLanguages = ContentPlanner::siteLanguages($site);
                $steps = array_fill_keys(array_keys(self::STEPS), []);
                $states = $this->posts->states($siteId, array_values(array_unique(array_merge([], ...array_map('array_values', array_values(array_intersect_key($postIds, $rows->keyBy('id')->all())))))));
                foreach ($rows as $suggestion) {
                    $item = $this->item($suggestion, $site, $siteLanguages, $scores[(int) $suggestion->id] ?? null, $postIds[(int) $suggestion->id] ?? [], $states);
                    if (in_array($item['step'], ['taslakta', 'yayinda'], true) && ($item['sent_at'] === null || $item['sent_at']->lt(now()->subDays(self::SENT_DAYS))) && $item['unsent'] === []) {
                        continue; // sent long ago
                    }
                    $steps[$item['step']][] = $item;
                }
                $counts = array_map('count', $steps);
                $steps['taslakta'] = collect($steps['taslakta'])->sortByDesc('sent_at')->values()->all();
                $steps['yayinda'] = $this->withMetrics(collect($steps['yayinda'])->sortByDesc(fn (array $i): int => $i['post_date']?->getTimestamp() ?? 0)->values()->all());
                $steps['yazilacak'] = collect($steps['yazilacak'])->sortByDesc('score')->values()->all();

                return [
                    'site' => $site, 'brand' => $rows->first()->brand?->name, 'languages' => $languages->get($siteId, []) ?: array_fill_keys($siteLanguages, 0),
                    'steps' => array_map(fn (array $list): array => array_slice($list, 0, $perStep), $steps), 'counts' => $counts,
                    'waiting' => collect($steps['yazilacak'])->where('writing', false)->where('approved', false)->count(),
                    'top_score' => (int) collect($steps['yazilacak'])->where('writing', false)->where('approved', false)->max('score'),
                    'urgent' => collect($steps['yazilacak'])->where('rank', '<=', 1)->count() + $counts['okunacak'],
                    'url' => route('operator.website', ['assetId' => $site->id, 'tab' => 'icerik']),
                ];
            })
            ->sortBy([['top_score', 'desc'], ['urgent', 'desc'], [fn (array $box): int => $box['counts']['yazilacak'], 'desc']])->values();
    }

    /**
     * Genel işler: the operator's steps across every site — Onay bekleyen başlıklar, Okunacak yazılar, Gönderildi —
     * each grouped by site (most urgent site first). A title being written stays under its site with a marker.
     *
     * @return array{counts: array<string, int>, groups: array<string, list<array<string, mixed>>>}
     */
    public function queue(?int $brandId = null): array
    {
        $boxes = $this->boxes($brandId, null, self::QUEUE_PER_SITE);
        $groups = [];
        foreach (array_keys(self::STEPS) as $step) {
            $groups[$step] = $boxes->filter(fn (array $box): bool => $box['counts'][$step] > 0)
                ->sortByDesc(fn (array $box): int => $step === 'yazilacak' ? $box['top_score'] * 1000 + $box['waiting'] : $box['counts'][$step])
                ->map(fn (array $box): array => ['site' => $box['site'], 'brand' => $box['brand'], 'languages' => $box['languages'], 'url' => $box['url'],
                    'items' => $box['steps'][$step], 'total' => $box['counts'][$step], 'waiting' => $box['waiting'], 'top_score' => $box['top_score']])->values()->all();
        }

        return ['counts' => [
            'yazilacak' => (int) $boxes->sum('waiting'), 'okunacak' => (int) $boxes->sum(fn (array $box): int => $box['counts']['okunacak']),
            'taslakta' => (int) $boxes->sum(fn (array $box): int => $box['counts']['taslakta']),
            'yayinda' => (int) $boxes->sum(fn (array $box): int => $box['counts']['yayinda']),
        ], 'groups' => $groups];
    }

    /** "Hepsini onayla ve yazdır": every waiting title of the site, highest score first (at most WRITE_ALL_MAX per click). */
    public function writeAll(int $siteId, User $user): string
    {
        $waiting = $this->query(null)->where('action->site_id', $siteId)->actionable()->orderBy('priority')->orderBy('id')->get()
            ->reject(fn (Suggestion $s): bool => is_array(data_get($s->action, 'article')) || isset(((array) $s->action)['article_blocked']));
        $scores = $this->scorer->forSuggestions($waiting);
        $waiting = $waiting->sortByDesc(fn (Suggestion $s): int => $scores[(int) $s->id]['score'] ?? 0)->take(self::WRITE_ALL_MAX);
        if ($waiting->isEmpty()) {
            throw ValidationException::withMessages(['work' => 'Bu sitede onay bekleyen başlık yok.']);
        }
        $waiting->each(fn (Suggestion $s): string => $this->write((int) $s->id, $user));

        return $waiting->count().' başlık onaylandı ve yazdırılıyor; bitince Okunacak\'a düşer.';
    }

    /** "Yaz": the title is approved (when open) and the writer starts; with a language of the site, its translation. */
    public function write(int $id, User $user, ?string $language = null): string
    {
        $suggestion = $this->suggestion($id);
        $action = (array) $suggestion->action;
        $site = DigitalAsset::query()->findOrFail((int) ($action['site_id'] ?? 0));
        if (isset($action['article_write_id']) || $suggestion->status === Suggestion::APPLIED) {
            throw ValidationException::withMessages(['work' => 'Bu yazı zaten gönderildi.']);
        }
        if ($language !== null && ! in_array($language, ContentPlanner::siteLanguages($site), true)) {
            throw ValidationException::withMessages(['work' => 'Bu dil sitede yok.']);
        }
        if (in_array($suggestion->status, [Suggestion::OPEN, Suggestion::RECHECK, Suggestion::SNOOZED], true)) {
            $this->siteSuggestions->approve($suggestion->forceFill(['status' => Suggestion::OPEN]), $user);
            // The title left the pool: a new one comes in its place (yakup, 2026-10-12).
            TopUpContentPoolJob::dispatch((int) $site->id)->delay(now()->addMinute());
        }
        // The article is written in the idea's language (the site's main one); another language is only ever its
        // translation (yakup, 2026-10-07), which starts by itself once the article is written.
        $translation = $language !== null && is_array($action['article'] ?? null) && $language !== ContentPlanner::articleLanguage($suggestion, $site);
        $language = $translation ? $language : null;
        SiteOperations::dispatch((int) $site->id, SiteOperations::WRITE_ARTICLE, ['suggestion_id' => (int) $suggestion->id] + ($translation ? ['language' => $language] : []));
        $label = self::LANGUAGE_LABELS[$language ?? ''] ?? null;

        return $translation ? ($label ?? strtoupper((string) $language)).' çevirisi yazılıyor; bitince Okunacak\'ta.'
            : 'Yazılıyor'.($label !== null ? ' ('.$label.')' : '').'; Claude yazınca Okunacak\'a düşer.';
    }

    /**
     * "Hepsini siteye gönder" (yakup 2026-10-20): every article Claude's check passed goes to WordPress as a draft with
     * its translations and image, off the web request (writes queue). Held ones stay with their reason.
     */
    public function sendAll(User $user, ?int $brandId = null, ?int $siteId = null): string
    {
        $ready = $this->readyCount($brandId, $siteId);
        if ($ready === 0) {
            throw ValidationException::withMessages(['work' => 'Kontrolden geçmiş, gönderilecek yazı yok.']);
        }
        SendReadyContentJob::dispatch((int) $user->id, $brandId, $siteId);

        return $ready.' yazı WordPress\'e taslak olarak gidiyor (geri alınabilir); bitince Taslakta\'da.';
    }

    /** Articles the one click would send now (connected sites only). */
    public function readyCount(?int $brandId = null, ?int $siteId = null): int
    {
        $bySite = $this->readyBySite($brandId);

        return $siteId === null ? array_sum($bySite) : (int) ($bySite[$siteId] ?? 0);
    }

    /** @return array<int, int> site id => checked articles the one click would send now (connected sites only) */
    public function readyBySite(?int $brandId = null): array
    {
        $paired = ContentAutopilot::pairedSiteIds();

        return app(ContentAutopilot::class)->ready($brandId)->map(fn (Suggestion $s): int => (int) data_get($s->action, 'site_id'))
            ->filter(fn (int $siteId): bool => in_array($siteId, $paired, true))->countBy()->all();
    }

    public function send(int $id, User $user): string
    {
        $suggestion = $this->suggestion($id);
        $first = data_get($suggestion->action, 'article_write_id') === null;
        $this->planner->sendDraft($suggestion, $user);

        return $first ? 'WordPress taslağı kuyruğa alındı (geri alınabilir); Taslakta\'da.' : 'Yeni dil WordPress\'e taslak olarak gönderildi.';
    }

    /**
     * The page an update replaces, as last collected (yakup, 2026-10-12: "eski sayfanın başlığı ve içeriği de
     * önizlemeye eklense"): its title, H1, SEO description, length and text, plus which sections the new version adds
     * and which it leaves out.
     *
     * @param  array<string, mixed>|null  $article
     * @return array{title: string, h1: string, meta_description: string, words: int, collected: ?CarbonImmutable, blocks: list<array{text: string, tag: string}>, added: list<string>, dropped: list<string>, new_words: int}|null
     */
    public static function currentPage(?int $pageId, ?array $article): ?array
    {
        $page = $pageId !== null ? Page::query()->find($pageId, ['id', 'title', 'h1', 'meta_description', 'word_count', 'content_outline', 'content_text', 'updated_at']) : null;
        if ($page === null) {
            return null;
        }
        $blocks = self::outlineBlocks($page->aiText(40000));
        $newBlocks = SiteDiff::blocks((string) ($article['html'] ?? ''));
        $headings = fn (array $list): array => array_values(array_map(fn (array $b): string => $b['text'], array_filter($list, fn (array $b): bool => str_starts_with($b['tag'], 'h'))));
        $key = fn (string $text): string => SeoText::fold($text);
        // The page's own heading repeated as the first section is the title, not a section.
        $old = array_values(array_filter($headings($blocks), fn (string $h): bool => ! in_array($key($h), [$key((string) $page->h1), $key((string) $page->title)], true)));
        $new = $headings($newBlocks);
        $oldKeys = array_map($key, $old);
        $newKeys = array_map($key, $new);

        return [
            'title' => trim((string) $page->title), 'h1' => trim((string) $page->h1), 'meta_description' => trim((string) $page->meta_description),
            'words' => (int) $page->word_count, 'collected' => $page->updated_at !== null ? CarbonImmutable::parse($page->updated_at)->timezone('Europe/Istanbul') : null,
            'blocks' => $blocks,
            'added' => array_values(array_filter($new, fn (string $h): bool => ! in_array($key($h), $oldKeys, true))),
            'dropped' => array_values(array_filter($old, fn (string $h): bool => ! in_array($key($h), $newKeys, true))),
            'new_words' => count(preg_split('/\\s+/u', trim(strip_tags(str_replace('<', ' <', (string) ($article['html'] ?? ''))))) ?: []),
        ];
    }

    /**
     * The stored Markdown outline as reader blocks: "#" headings, "- " / "1. " list items, paragraphs.
     *
     * @return list<array{text: string, tag: string}>
     */
    private static function outlineBlocks(string $outline): array
    {
        $out = [];
        foreach (preg_split('/\n+/u', $outline) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(#{1,6})\s+(.+)$/u', $line, $m) === 1) {
                $out[] = ['text' => trim($m[2]), 'tag' => 'h'.strlen($m[1])];
            } elseif (preg_match('/^(?:[-*•]|\d+[.)])\s+(.+)$/u', $line, $m) === 1) {
                $out[] = ['text' => trim($m[1]), 'tag' => 'li'];
            } else {
                $out[] = ['text' => $line, 'tag' => 'p'];
            }
        }

        return $out;
    }

    /** "Sayfaya uygula": the strengthened page's new version goes live (ADR-070, undoable). */
    public function applyPage(int $id, User $user): string
    {
        $this->planner->applyPageUpdate($this->suggestion($id), $user);

        return 'Sayfanın yeni sürümü canlıya alınıyor (geri alınabilir).';
    }

    /** "Görseli değiştir" / "Görselsiz": the featured image the draft will carry (only before it is sent). */
    public function setImage(int $id, ?int $mediaId): string
    {
        $suggestion = $this->suggestion($id);
        $action = (array) $suggestion->action;
        $site = DigitalAsset::query()->findOrFail((int) ($action['site_id'] ?? 0));
        if (isset($action['article_write_id'])) {
            throw ValidationException::withMessages(['work' => 'Yazı gönderildi; görseli sitede değiştir.']);
        }
        $image = null;
        if ($mediaId !== null) {
            $image = collect($this->images->candidates($suggestion, $site, 20))->firstWhere('media_id', $mediaId)
                ?? throw ValidationException::withMessages(['work' => 'Bu görsel sitenin medya kütüphanesinde bulunamadı.']);
        }
        $suggestion->forceFill(['action' => array_merge($action, ['image' => $image])])->save();

        return $image === null ? 'Yazı görselsiz gidecek.' : 'Yazının görseli seçildi.';
    }

    /**
     * "Yayın tarihi planla" (a draft: WordPress publishes it at that time) or "Tarihi değiştir" (a published article,
     * past dates only). Every language post of the article gets the date; admin click, logged, undoable.
     */
    public function changeDate(int $id, string $date, User $user): string
    {
        $suggestion = $this->suggestion($id);
        $site = DigitalAsset::query()->findOrFail((int) data_get($suggestion->action, 'site_id'));
        $posts = $this->posts->postIds(collect([$suggestion]))[(int) $suggestion->id] ?? [];
        $state = $this->posts->states((int) $site->id, array_values($posts))[reset($posts) ?: 0] ?? null;
        try {
            $when = CarbonImmutable::parse($date, 'Europe/Istanbul');
        } catch (Throwable) {
            throw ValidationException::withMessages(['work' => 'Tarih okunamadı.']);
        }
        $published = ($state['status'] ?? null) === 'publish';
        app(ExternalWriteService::class)->requestPostDate($user, $site, $suggestion, $posts, $when, schedule: ! $published);

        return $published ? 'Yayın tarihi '.$when->format('d.m.Y H:i').' olarak değiştiriliyor (geri alınabilir).'
            : 'Yazı '.$when->format('d.m.Y H:i').' tarihinde yayınlanmak üzere planlanıyor (geri alınabilir).';
    }

    /** @return array<string, mixed>|null the article to read: source and translations */
    public function article(int $id): ?array
    {
        $suggestion = Suggestion::query()->whereKey($id)->where('action_type', SiteSuggestionTypes::CONTENT)
            ->whereHas('brand', fn (Builder $brand): Builder => $brand->operational())->first();
        if ($suggestion === null) {
            return null;
        }
        $action = (array) $suggestion->action;
        if (! is_array($action['article'] ?? null) && ! is_array($action['article_blocked_draft'] ?? null)) {
            return null;
        }

        $site = DigitalAsset::query()->find((int) ($action['site_id'] ?? 0));
        $written = [$site !== null ? self::sourceLanguage($suggestion, $site) : null, ...array_keys(array_filter((array) ($action['translations'] ?? []), 'is_array'))];
        $status = $site !== null ? SiteOperations::status((int) $site->id, SiteOperations::WRITE_ARTICLE, ['suggestion_id' => (int) $suggestion->id]) : null;

        return [
            'id' => (int) $suggestion->id, 'idea' => (string) $suggestion->title,
            'kind' => ($action['kind'] ?? null) === 'update' ? 'Güncelleme' : 'Yeni yazı', 'target_url' => is_string($action['target_url'] ?? null) ? $action['target_url'] : null,
            'target_title' => ($action['kind'] ?? null) === 'update' ? (trim((string) $suggestion->page?->title) ?: null) : null,
            'current' => ($action['kind'] ?? null) === 'update' ? self::currentPage($suggestion->page_id, is_array($action['article'] ?? null) ? $action['article'] : null) : null,
            'missing' => $site !== null && is_array($action['article'] ?? null) && ($action['kind'] ?? null) !== 'update' ? array_values(array_diff(ContentPlanner::siteLanguages($site), $written)) : [],
            'writing' => in_array($status['status'] ?? null, ['running', 'queued'], true)
                || ($site !== null && self::translating($suggestion, $site, array_diff(ContentPlanner::siteLanguages($site), $written)) !== []),
            'article' => $action['article'] ?? null, 'blocked' => $action['article_blocked'] ?? null, 'blocked_draft' => $action['article_blocked_draft'] ?? null,
            'warnings' => $action['article_warnings'] ?? null, 'seo' => array_key_exists('article_seo', $action) ? array_values(array_filter((array) $action['article_seo'], 'is_string')) : null, 'translations' => array_filter((array) ($action['translations'] ?? []), 'is_array'),
            'translations_blocked' => (array) ($action['translations_blocked'] ?? []), 'sent' => (array) ($action['sent_languages'] ?? []),
            // The featured image: the operator's pick (or none), else the best of the site's own media.
            ...$this->imageOf($suggestion, $site),
        ];
    }

    /**
     * The languages whose translation of the written article is being prepared now.
     *
     * @param  array<int, string>  $languages
     * @return list<string>
     */
    private static function translating(Suggestion $suggestion, DigitalAsset $site, array $languages): array
    {
        return array_values(array_filter($languages, fn (string $language): bool => in_array(SiteOperations::status((int) $site->id, SiteOperations::WRITE_ARTICLE,
            ['suggestion_id' => (int) $suggestion->id, 'language' => $language])['status'] ?? null, ['running', 'queued'], true)));
    }

    /** @return array{image: ?array<string, mixed>, image_picked: bool, images: list<array<string, mixed>>} */
    private function imageOf(Suggestion $suggestion, ?DigitalAsset $site): array
    {
        $action = (array) $suggestion->action;
        if ($site === null || isset($action['article_write_id'])) {
            return ['image' => is_array($action['image'] ?? null) ? $action['image'] : null, 'image_picked' => true, 'images' => []];
        }
        $choices = $this->images->candidates($suggestion, $site);
        $picked = array_key_exists('image', $action);

        return ['image' => $picked ? (is_array($action['image']) ? $action['image'] : null) : ($choices[0] ?? null), 'image_picked' => $picked, 'images' => $choices];
    }

    /**
     * Search Console clicks and impressions (30 and 90 days) of published articles.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function withMetrics(array $items): array
    {
        $metrics = $this->posts->metrics(array_values(array_filter(array_column($items, 'link'))));

        return array_map(fn (array $item): array => $item + ['metrics' => $metrics[$item['link'] ?? ''] ?? null], $items);
    }

    private static function sourceLanguage(Suggestion $suggestion, DigitalAsset $site): string
    {
        return (string) (data_get($suggestion->action, 'article.language') ?: ContentPlanner::articleLanguage($suggestion, $site));
    }

    /**
     * @param  list<string>  $siteLanguages
     * @param  array{score: int, parts: array<string, float>, notes: list<string>}|null  $scored  ContentScore of the idea
     * @param  array<string, int>  $posts  language => WordPress post id of a sent article
     * @param  array<int, array{status: string, date: ?CarbonImmutable, link: ?string, from: string}>  $states  post id => where it stands on the site
     * @return array<string, mixed>
     */
    private function item(Suggestion $s, DigitalAsset $site, array $siteLanguages, ?array $scored = null, array $posts = [], array $states = []): array
    {
        $action = (array) $s->action;
        $hasArticle = is_array($action['article'] ?? null);
        $sent = isset($action['article_write_id']) || $s->status === Suggestion::APPLIED;
        $language = self::sourceLanguage($s, $site);
        $translations = array_keys(array_filter((array) ($action['translations'] ?? []), 'is_array'));
        $sentLanguages = array_values((array) ($action['sent_languages'] ?? []));
        $status = SiteOperations::status((int) $site->id, SiteOperations::WRITE_ARTICLE, ['suggestion_id' => (int) $s->id]);
        $line = SiteOperations::line($status);
        $writing = in_array($status['status'] ?? null, ['running', 'queued'], true);
        $translating = self::translating($s, $site, array_diff($siteLanguages, [$language], $translations));
        // The card says why the idea is here: the writer's sentence, else the data line it was picked from.
        $post = $states[reset($posts) ?: 0] ?? null;
        $why = filled($s->reason) ? (string) $s->reason : (string) (collect((array) $s->evidence)
            ->first(fn ($e): bool => is_array($e) && ($e['kind'] ?? 'none') !== 'none' && filled($e['value'] ?? null))['value'] ?? '');

        $item = [
            'id' => (int) $s->id, 'title' => (string) $s->title, 'rank' => max(0, (int) $s->priority), 'status' => (string) $s->status,
            'step' => $sent ? (($post['status'] ?? null) === 'publish' ? 'yayinda' : 'taslakta') : ($hasArticle || isset($action['article_blocked']) ? 'okunacak' : 'yazilacak'),
            'kind' => ($action['kind'] ?? null) === 'update' ? 'Güncelleme' : 'Yeni yazı', 'page_type' => (string) ($action['page_type'] ?? 'blog'),
            'target_url' => is_string($action['target_url'] ?? null) ? $action['target_url'] : null,
            // An update names the page it strengthens (yakup, 2026-10-12: the card did not say which page it was).
            'target_title' => ($action['kind'] ?? null) === 'update' ? (trim((string) $s->page?->title) ?: null) : null,
            'language' => $language, 'translations' => $translations, 'sent_languages' => $sentLanguages,
            'cluster' => $s->cluster?->name,
            'angle' => ContentPlanner::ANGLES[$action['angle'] ?? ''] ?? (($action['out_of_cluster'] ?? false) ? 'Kümeler dışı fırsat' : null),
            'missing_languages' => array_values(array_diff($siteLanguages, [$language], $translations, $translating)), 'translating' => $translating,
            'unsent' => $sent ? array_values(array_diff($translations, $sentLanguages)) : [],
            'blocked' => is_string($action['article_blocked'] ?? null) ? $action['article_blocked'] : null,
            'writing' => $writing, 'line' => $writing ? $line : (in_array($status['status'] ?? null, [null, 'ready'], true) ? self::sendFailedLine($action, $sent) : $line),
            'approved' => $s->status === Suggestion::APPROVED, 'sent_at' => $sent ? ($s->resolved_at ?? $s->applied_at) : null,
            'post_status' => $post['status'] ?? null, 'post_line' => $sent ? self::postLine($post, $posts === [] && $s->status !== Suggestion::APPLIED) : null, 'post_date' => $post['date'] ?? null,
            'link' => $post['link'] ?? null, 'can_date' => $sent && in_array($post['status'] ?? null, ['draft', 'pending', 'future', 'publish'], true),
            'reason' => $why,
            'score' => $scored['score'] ?? 0, 'score_line' => $scored !== null ? ContentScore::explain($scored) : null,
            'can_apply' => false,
            // Claude's check of the written article (yakup 2026-10-20: every article is checked; reading it is optional).
            'check' => is_array(data_get($action, 'autopilot.review')) ? ['verdict' => (string) data_get($action, 'autopilot.review.verdict'), 'reason' => (string) data_get($action, 'autopilot.review.reason')] : null,
            'sendable' => ! $sent && ContentAutopilot::sendable($s),
        ];
        if (! empty($action['page_update'])) {
            // A strengthened page: its new version waits as a draft copy of the page until "Sayfaya uygula" puts it live.
            $applied = isset($action['page_apply_write_id']);
            $item = array_merge($item, [
                'step' => $applied ? 'yayinda' : 'taslakta', 'post_status' => $applied ? 'publish' : 'draft', 'can_date' => false, 'missing_languages' => [], 'unsent' => [],
                'post_line' => $applied ? 'Sayfaya uygulandı' : 'Sayfanın yeni sürümü WordPress\'te taslak; canlıdaki sayfa henüz değişmedi',
                'post_date' => $applied ? CarbonImmutable::parse($s->applied_at ?? now())->timezone('Europe/Istanbul') : null,
                'link' => $applied ? $item['target_url'] : null,
                'can_apply' => ! $applied && ExternalWriteAction::query()->whereKey((int) $action['article_write_id'])->where('status', 'succeeded')->exists(),
            ]);
        }

        return $item;
    }

    /**
     * @param  array{status: string, date: ?CarbonImmutable}|null  $post
     * @param  bool  $onTheWay  no WordPress post yet: the write still waits for the site (a failed one returns the idea to "send")
     */
    private static function postLine(?array $post, bool $onTheWay = false): string
    {
        if ($post === null) {
            return $onTheWay ? 'Siteye gidiyor; site işi alınca WordPress\'te taslak oluşur' : 'Taslak gönderildi';
        }
        $label = self::POST_STATES[$post['status']] ?? $post['status'];

        return in_array($post['status'], ['future', 'publish'], true) && $post['date'] !== null ? $label.' · '.$post['date']->format('d.m.Y H:i') : $label;
    }

    /**
     * Why the last send did not reach WordPress (ContentPlanner::draftFailed), while the idea waits to be sent again.
     *
     * @param  array<string, mixed>  $action
     */
    private static function sendFailedLine(array $action, bool $sent): ?string
    {
        $error = data_get($action, 'send_failed.error');

        return is_string($error) && $error !== '' && ! $sent ? 'WordPress\'e gitmedi, yeniden gönderilebilir: '.$error : null;
    }

    /** @return Builder<Suggestion> */
    private function query(?int $brandId): Builder
    {
        return Suggestion::query()->where('channel', 'search')->where('action_type', SiteSuggestionTypes::CONTENT)
            ->whereHas('brand', fn (Builder $brand): Builder => $brand->operational())
            ->when($brandId !== null, fn (Builder $q): Builder => $q->where('brand_id', $brandId))
            ->where(fn (Builder $q): Builder => $q->where('status', Suggestion::APPROVED)
                ->orWhere(fn (Builder $open): Builder => $open->actionable())
                ->orWhere(fn (Builder $done): Builder => $done->where('status', Suggestion::APPLIED)->where('applied_at', '>=', now()->subDays(WorkDesk::DONE_DAYS))));
    }

    private function suggestion(int $id): Suggestion
    {
        return Suggestion::query()->whereKey($id)->where('action_type', SiteSuggestionTypes::CONTENT)
            ->whereHas('brand', fn (Builder $brand): Builder => $brand->operational())->firstOrFail();
    }
}
