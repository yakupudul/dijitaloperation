<?php

namespace App\Services\Work;

use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Site\ContentPlanner;
use App\Services\Site\SiteOperations;
use App\Services\Site\SiteSuggestions;
use App\Services\Site\SiteSuggestionTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Genel işler › Web site SEO içerikler (yakup, 2026-10-03): one box per website with its content ideas in three
 * steps — Yazılacak (title waits, or is being written), Okunacak (article written: read it, send it), Gönderildi
 * (WordPress draft sent, 30 days). "Yaz" approves the title and starts the writer in one click; "Oku" opens the
 * article; "Taslak gönder" sends it (with its translations) as WordPress drafts. A site whose pages use several
 * languages shows them, and an idea can be written in any of them (the source language picked before writing,
 * further languages as linked translations).
 */
final class ContentBoard
{
    public const array STEPS = ['yazilacak' => 'Yazılacak', 'okunacak' => 'Okunacak', 'gonderildi' => 'Gönderildi'];

    public const array LANGUAGE_LABELS = [
        'tr' => 'Türkçe', 'en' => 'İngilizce', 'de' => 'Almanca', 'ar' => 'Arapça', 'ru' => 'Rusça', 'fr' => 'Fransızca', 'es' => 'İspanyolca',
        'nl' => 'Hollandaca', 'it' => 'İtalyanca', 'fa' => 'Farsça', 'az' => 'Azerice', 'uk' => 'Ukraynaca', 'pl' => 'Lehçe', 'ro' => 'Romence',
    ];

    /** Ideas shown per step in one box (the rest on the site's İçerik tab). */
    public const int PER_STEP = 8;

    private const int LIMIT = 2000;

    public function __construct(private readonly SiteSuggestions $siteSuggestions, private readonly ContentPlanner $planner) {}

    /**
     * @return Collection<int, array{site: DigitalAsset, brand: ?string, languages: array<string, int>, steps: array<string, list<array<string, mixed>>>, counts: array<string, int>, urgent: int, url: string}>
     */
    public function boxes(?int $brandId = null): Collection
    {
        $items = $this->query($brandId)->with('brand:id,name')->orderBy('priority')->orderByDesc('id')->limit(self::LIMIT)->get();
        $siteIds = $items->map(fn (Suggestion $s): int => (int) data_get($s->action, 'site_id'))->filter()->unique()->values();
        $sites = DigitalAsset::query()->whereIn('id', $siteIds->all() ?: [0])->get()->keyBy('id');
        $languages = DB::table('pages')->whereIn('website_asset_id', $siteIds->all() ?: [0])->whereNotNull('language')
            ->groupBy('website_asset_id', 'language')->selectRaw('website_asset_id, language, count(*) as n')->orderByDesc('n')->get()
            ->groupBy('website_asset_id')->map(fn (Collection $rows): array => $rows->mapWithKeys(fn (object $r): array => [strtolower((string) $r->language) => (int) $r->n])->all());

        return $items->groupBy(fn (Suggestion $s): int => (int) data_get($s->action, 'site_id'))
            ->filter(fn (Collection $rows, int $siteId): bool => $sites->has($siteId))
            ->map(function (Collection $rows, int $siteId) use ($sites, $languages): array {
                $site = $sites->get($siteId);
                $siteLanguages = ContentPlanner::siteLanguages($site);
                $steps = array_fill_keys(array_keys(self::STEPS), []);
                foreach ($rows as $suggestion) {
                    $item = $this->item($suggestion, $site, $siteLanguages);
                    if ($item['step'] === 'gonderildi' && ($item['sent_at'] === null || $item['sent_at']->lt(now()->subDays(WorkDesk::DONE_DAYS))) && $item['unsent'] === []) {
                        continue; // sent more than 30 days ago
                    }
                    $steps[$item['step']][] = $item;
                }
                $counts = array_map('count', $steps);
                $steps['gonderildi'] = collect($steps['gonderildi'])->sortByDesc('sent_at')->values()->all();

                return [
                    'site' => $site, 'brand' => $rows->first()->brand?->name, 'languages' => $languages->get($siteId, []) ?: array_fill_keys($siteLanguages, 0),
                    'steps' => array_map(fn (array $list): array => array_slice($list, 0, self::PER_STEP), $steps), 'counts' => $counts,
                    'urgent' => collect($steps['yazilacak'])->where('rank', '<=', 1)->count() + $counts['okunacak'],
                    'url' => route('operator.website', ['assetId' => $site->id, 'tab' => 'icerik']),
                ];
            })
            ->sortBy([['urgent', 'desc'], [fn (array $box): int => $box['counts']['yazilacak'], 'desc']])->values();
    }

    /** "Yaz": the title is approved (when open) and the writer starts, in the language picked (one of the site's). */
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
        }
        $translation = $language !== null && is_array($action['article'] ?? null) && $language !== ContentPlanner::articleLanguage($suggestion, $site);
        if (! $translation && $language !== null && ! is_array($action['article'] ?? null)) {
            $suggestion->forceFill(['action' => array_merge($action, ['language' => $language])])->save();
        }
        SiteOperations::dispatch((int) $site->id, SiteOperations::WRITE_ARTICLE, ['suggestion_id' => (int) $suggestion->id] + ($translation ? ['language' => $language] : []));
        $label = self::LANGUAGE_LABELS[$language ?? ''] ?? null;

        return $translation ? ($label ?? strtoupper((string) $language)).' çevirisi yazılıyor; bitince Okunacak\'ta.'
            : 'Yazılıyor'.($label !== null ? ' ('.$label.')' : '').'; Claude yazınca Okunacak\'a düşer.';
    }

    public function send(int $id, User $user): string
    {
        $suggestion = $this->suggestion($id);
        $first = data_get($suggestion->action, 'article_write_id') === null;
        $this->planner->sendDraft($suggestion, $user);

        return $first ? 'WordPress taslağı kuyruğa alındı (geri alınabilir); Gönderildi\'de.' : 'Yeni dil WordPress\'e taslak olarak gönderildi.';
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
            'missing' => $site !== null && is_array($action['article'] ?? null) ? array_values(array_diff(ContentPlanner::siteLanguages($site), $written)) : [],
            'writing' => in_array($status['status'] ?? null, ['running', 'queued'], true),
            'article' => $action['article'] ?? null, 'blocked' => $action['article_blocked'] ?? null, 'blocked_draft' => $action['article_blocked_draft'] ?? null,
            'warnings' => $action['article_warnings'] ?? null, 'translations' => array_filter((array) ($action['translations'] ?? []), 'is_array'),
            'translations_blocked' => (array) ($action['translations_blocked'] ?? []), 'sent' => (array) ($action['sent_languages'] ?? []),
        ];
    }

    private static function sourceLanguage(Suggestion $suggestion, DigitalAsset $site): string
    {
        return (string) (data_get($suggestion->action, 'article.language') ?: ContentPlanner::articleLanguage($suggestion, $site));
    }

    /**
     * @param  list<string>  $siteLanguages
     * @return array<string, mixed>
     */
    private function item(Suggestion $s, DigitalAsset $site, array $siteLanguages): array
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

        return [
            'id' => (int) $s->id, 'title' => (string) $s->title, 'rank' => max(0, (int) $s->priority), 'status' => (string) $s->status,
            'step' => $sent ? 'gonderildi' : ($hasArticle || isset($action['article_blocked']) ? 'okunacak' : 'yazilacak'),
            'kind' => ($action['kind'] ?? null) === 'update' ? 'Güncelleme' : 'Yeni yazı', 'page_type' => (string) ($action['page_type'] ?? 'blog'),
            'target_url' => is_string($action['target_url'] ?? null) ? $action['target_url'] : null,
            'language' => $language, 'translations' => $translations, 'sent_languages' => $sentLanguages,
            'missing_languages' => array_values(array_diff($siteLanguages, [$language], $translations)),
            'unsent' => $sent ? array_values(array_diff($translations, $sentLanguages)) : [],
            'can_pick_language' => ! $hasArticle && count($siteLanguages) > 1,
            'blocked' => is_string($action['article_blocked'] ?? null) ? $action['article_blocked'] : null,
            'writing' => $writing, 'line' => $writing ? $line : (in_array($status['status'] ?? null, [null, 'ready'], true) ? null : $line),
            'approved' => $s->status === Suggestion::APPROVED, 'sent_at' => $sent ? ($s->resolved_at ?? $s->applied_at) : null,
            'reason' => (string) $s->reason,
        ];
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
