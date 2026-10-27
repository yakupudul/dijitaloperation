<?php

namespace App\Services\Site\Showcase;

use App\Ai\Agents\SiteQaAnswerAgent;
use App\Ai\Agents\SiteQaWriteAgent;
use App\Jobs\Site\AnswerSiteQuestionJob;
use App\Jobs\Site\PlanSiteQuestionsJob;
use App\Jobs\Site\SyncSiteShowcaseJob;
use App\Models\Brand;
use App\Models\ContentTopic;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\SiteQuestion;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Brand\BrandPack;
use App\Services\Epicrisis\Epicrisis;
use App\Services\Gbp\GbpAssistant;
use App\Services\Site\BrandlessSites;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sorular ve yorumlar: the questions of the pages a site admin assigned (yakup, 2026-10-27).
 *
 * - A visitor's question arrives as the connector event `qa.asked` (no name or contact). Claude sorts it at once
 *   (publish / private / spam), cleans it of anything personal and writes a 1–3 sentence answer from the brand's
 *   epikriz (`site.qa_answer`).
 * - Pages with fewer than SHOWN questions get the decision questions of their own search demand (the page's clusters'
 *   queries and the site's topic map) with answers (`site.qa_write`), at most once a week per page.
 * - Every answer waits on the Onarım masası (kind "Sitedeki soru-cevap", AI-checked, in the daily send); the operator
 *   can change the question or the answer there. Approved → published → sent to the site; rejected → hidden.
 */
final class SiteQuestions
{
    public const string TYPE = 'site_qa';

    /** Questions a page shows on itself; the planner fills a page up to this. */
    public const int SHOWN = 10;

    /** Days between two planning rounds of one page. */
    public const int PLAN_DAYS = 7;

    /** Pages planned in one round per site. */
    public const int PLAN_PER_SITE = 20;

    public function __construct(
        private readonly AiTaskQueue $tasks,
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
    ) {}

    /**
     * Stores the questions of `qa.asked` events and asks their answers (only for a served site of a switched-on brand).
     *
     * @param  list<array<string, mixed>>  $events
     */
    public function fromEvents(int $siteId, array $events): int
    {
        $asked = array_filter($events, fn (array $e): bool => ($e['type'] ?? '') === 'qa.asked' && trim((string) ($e['question'] ?? '')) !== '');
        if ($asked === []) {
            return 0;
        }
        $site = DigitalAsset::query()->with('brand')->find($siteId);
        if ($site === null || $site->brand === null || ! $site->brand->showcase_enabled || ! BrandlessSites::serviced($site)) {
            return 0;
        }
        $made = 0;
        foreach ($asked as $event) {
            $localId = (int) ($event['object_id'] ?? 0);
            if ($localId < 1 || preg_match('/^(post|term):\d+$/', (string) ($event['target'] ?? '')) !== 1) {
                continue;
            }
            $question = SiteQuestion::query()->firstOrCreate(['website_asset_id' => $siteId, 'local_id' => $localId], [
                'brand_id' => $site->brand_id, 'target' => (string) $event['target'], 'target_title' => mb_substr((string) ($event['title'] ?? ''), 0, 250),
                'target_url' => (string) ($event['url'] ?? ''), 'source' => SiteQuestion::SOURCE_SITE, 'question' => mb_substr(trim((string) $event['question']), 0, 2000),
                'status' => SiteQuestion::NEW, 'asked_at' => $event['occurred_at'] ?? now(),
            ]);
            if ($question->wasRecentlyCreated) {
                AnswerSiteQuestionJob::dispatch((int) $question->id);
                $made++;
            }
        }

        return $made;
    }

    /** Whether an AI route can take these operations now (Claude's queue or a provider). */
    private function usable(string $operation): bool
    {
        return $this->tasks->delegated($operation) || ! $this->routes->resolve($operation)->isEmpty();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|string|null array = answer, 'queued' = Claude works on it, null = failed
     */
    private function ask(SiteQaAnswerAgent|SiteQaWriteAgent $agent, array $data, string $slot, string $label): array|string|null
    {
        $answer = $this->tasks->delegatedCall($agent, $data, $slot, $label);
        if ($answer === 'queued' || is_array($answer)) {
            return $answer;
        }
        if ($answer === 'error') {
            return null;
        }
        $route = $this->routes->resolve($agent->promptOperation());
        if ($route->isEmpty()) {
            return null;
        }
        $this->runtime->prepare(array_keys($route->providerModels));

        return (array) $agent->prompt($label."\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), provider: $route->providerModels, timeout: 120)->toArray();
    }

    /** Run by AnswerSiteQuestionJob: sorts and answers one visitor question. */
    public function answer(int $questionId): void
    {
        $question = SiteQuestion::query()->with(['brand', 'site'])->find($questionId);
        if ($question === null || $question->status !== SiteQuestion::NEW || $question->brand === null || $question->site === null) {
            return;
        }
        if (! $this->usable(AiRouteKeys::SITE_QA_ANSWER)) {
            $question->forceFill(['error' => 'Uygun AI sağlayıcısı yok; gece yeniden denenecek.'])->save();

            return;
        }
        $page = $this->page($question->site, $question->target, (string) $question->target_url);
        $data = [
            'business' => $question->brand->name,
            'page' => ['title' => $question->target_title, 'url' => $question->target_url, 'h1' => $page?->h1],
            'question' => (string) $question->question,
            'articles' => $this->articles($question->site, $page),
            'compliance' => BrandPack::rules($question->brand),
        ] + Epicrisis::input($question->brand, 'content');
        $out = $this->ask(new SiteQaAnswerAgent, $data, 'site-question:'.$question->id, 'QUESTION_JSON');
        if ($out === 'queued') {
            return;
        }
        if (! is_array($out)) {
            $question->forceFill(['error' => 'Cevap yazılamadı; gece yeniden denenecek.'])->save();

            return;
        }
        $verdict = (string) ($out['verdict'] ?? '');
        if ($verdict === 'spam' || $verdict === 'private') {
            $question->forceFill(['status' => $verdict === 'spam' ? SiteQuestion::SPAM : SiteQuestion::PRIVATE, 'error' => null])->save();

            return;
        }
        $this->ready($question, (string) ($out['question'] ?? ''), (string) ($out['answer'] ?? ''), (string) ($out['link_url'] ?? ''), $data['articles']);
    }

    /**
     * Puts an answer on the Onarım masası (or holds it with the reason).
     *
     * @param  list<array{title: string, url: string}>  $articles
     */
    private function ready(SiteQuestion $question, string $text, string $answer, string $link, array $articles): bool
    {
        $text = self::clean($text, 200);
        $answer = self::clean($answer, 1200);
        if ($text === '' || mb_strlen($answer) < 20) {
            $question->forceFill(['error' => 'Cevap boş ya da çok kısa geldi.'])->save();

            return false;
        }
        $blocking = GbpAssistant::blockingHits($question->brand, $text.' '.$answer);
        if ($blocking !== []) {
            $question->forceFill(['error' => 'Sektör uyum kuralına takıldı: '.implode(', ', $blocking)])->save();

            return false;
        }
        $article = collect($articles)->firstWhere('url', $link);
        $question->forceFill(['question' => $text, 'answer' => $answer, 'link_url' => $article !== null ? $article['url'] : null,
            'link_title' => $article !== null ? mb_substr((string) $article['title'], 0, 250) : null, 'status' => SiteQuestion::READY, 'error' => null])->save();
        $this->toDesk($question);

        return true;
    }

    private static function clean(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');

        return mb_substr($text, 0, $max);
    }

    private function toDesk(SiteQuestion $question): void
    {
        $site = $question->site;
        $action = ['site_id' => (int) $question->website_asset_id, 'question_id' => (int) $question->id, 'question' => (string) $question->question,
            'answer' => (string) $question->answer, 'target_url' => (string) $question->target_url, 'target_title' => (string) $question->target_title,
            'link_url' => (string) $question->link_url];
        $suggestion = Suggestion::openOnce([
            'brand_id' => $question->brand_id, 'fingerprint' => hash('sha256', 'site-qa|'.$question->id),
            'channel' => 'search', 'decision_key' => 'repair.'.self::TYPE, 'action_type' => self::TYPE,
            'title' => mb_substr(($question->source === SiteQuestion::SOURCE_SITE ? 'Ziyaretçi sorusu · ' : 'Sık sorulan soru · ').$question->target_title, 0, 160),
            'reason' => $question->source === SiteQuestion::SOURCE_SITE
                ? 'Bir ziyaretçi sitede sordu. Onaylanınca sayfanın soru bölümünde adsız yayınlanır, soran kişiye e-postayla haber gider.'
                : 'Sayfanın aramalarından: bu hizmeti almadan önce sorulan soru. Onaylanınca sayfanın soru bölümünde yayınlanır.',
            'priority' => $question->source === SiteQuestion::SOURCE_SITE ? 2 : 3,
            'target_type' => 'asset', 'target_id' => $site?->id, 'page_id' => $this->page($site, (string) $question->target, (string) $question->target_url)?->id,
            'status' => Suggestion::OPEN, 'first_seen_at' => now(), 'last_seen_at' => now(),
            'material_hash' => hash('sha256', $question->question.'|'.$question->answer), 'action' => $action,
        ]);
        $question->forceFill(['suggestion_id' => $suggestion->id])->save();
    }

    /**
     * Asks the questions of the assigned pages that have fewer than SHOWN (at most once a week per page).
     *
     * @param  list<array<string, mixed>>  $targets  SiteShowcase::targets()
     */
    public function plan(int $siteId, array $targets): int
    {
        $counts = SiteQuestion::query()->where('website_asset_id', $siteId)->whereIn('status', [SiteQuestion::READY, SiteQuestion::PUBLISHED, SiteQuestion::NEW])
            ->groupBy('target')->selectRaw('target, count(*) as n')->pluck('n', 'target');
        $asked = 0;
        foreach ($targets as $target) {
            if ($asked >= self::PLAN_PER_SITE || (int) ($counts[$target['key']] ?? 0) >= self::SHOWN) {
                continue;
            }
            if (! Cache::add('site-qa-plan:'.$siteId.':'.$target['key'], 1, now()->addDays(self::PLAN_DAYS))) {
                continue;
            }
            PlanSiteQuestionsJob::dispatch($siteId, ['key' => (string) $target['key'], 'title' => (string) ($target['title'] ?? ''), 'url' => (string) ($target['url'] ?? '')]);
            $asked++;
        }

        return $asked;
    }

    /**
     * Run by PlanSiteQuestionsJob: the decision questions of one page from its search demand.
     *
     * @param  array{key: string, title: string, url: string}  $target
     */
    public function write(int $siteId, array $target): void
    {
        $site = DigitalAsset::query()->with('brand')->find($siteId);
        $brand = $site?->brand;
        if ($site === null || $brand === null || ! $brand->showcase_enabled || ! BrandlessSites::serviced($site) || ! $this->usable(AiRouteKeys::SITE_QA_WRITE)) {
            return;
        }
        $existing = SiteQuestion::query()->where('website_asset_id', $siteId)->where('target', $target['key'])
            ->whereNotIn('status', [SiteQuestion::SPAM, SiteQuestion::PRIVATE])->pluck('question')->map(fn ($q): string => (string) $q)->all();
        $live = SiteQuestion::query()->where('website_asset_id', $siteId)->where('target', $target['key'])
            ->whereIn('status', [SiteQuestion::READY, SiteQuestion::PUBLISHED, SiteQuestion::NEW])->count();
        $count = self::SHOWN - $live;
        if ($count < 1) {
            return;
        }
        $page = $this->page($site, $target['key'], $target['url']);
        $queries = $this->queries($brand, $site, $page);
        $topics = $this->topics($site, $page);
        $data = [
            'business' => $brand->name,
            'page' => ['title' => $target['title'], 'url' => $target['url'], 'h1' => $page?->h1, 'summary' => mb_substr((string) $page?->content_summary, 0, 1500)],
            'count' => $queries === [] && $topics === [] ? min($count, 6) : $count,
            'queries' => $queries, 'topics' => $topics, 'existing' => $existing,
            'compliance' => BrandPack::rules($brand),
        ] + Epicrisis::input($brand, 'content');
        $out = $this->ask(new SiteQaWriteAgent, $data, 'site-qa-write:'.$siteId.':'.$target['key'], 'PAGE_JSON');
        if (! is_array($out)) {
            return;
        }
        $articles = array_values(array_filter(array_map(fn (array $t): ?array => $t['url'] !== '' ? ['title' => $t['question'], 'url' => $t['url']] : null, $topics)));
        $seen = array_map(fn (string $q): string => mb_strtolower(trim($q)), $existing);
        $position = (int) SiteQuestion::query()->where('website_asset_id', $siteId)->where('target', $target['key'])->max('position');
        foreach (array_slice((array) ($out['items'] ?? []), 0, $count) as $item) {
            $text = self::clean((string) data_get($item, 'question', ''), 200);
            if ($text === '' || in_array(mb_strtolower($text), $seen, true)) {
                continue;
            }
            $seen[] = mb_strtolower($text);
            $question = SiteQuestion::query()->create([
                'brand_id' => $brand->id, 'website_asset_id' => $siteId, 'target' => $target['key'], 'target_title' => mb_substr($target['title'], 0, 250),
                'target_url' => $target['url'], 'source' => SiteQuestion::SOURCE_DATA, 'question' => $text, 'status' => SiteQuestion::NEW, 'position' => ++$position,
            ]);
            $question->setRelation('brand', $brand)->setRelation('site', $site);
            if (! $this->ready($question, $text, (string) data_get($item, 'answer', ''), (string) data_get($item, 'link_url', ''), $articles)) {
                $question->forceFill(['status' => SiteQuestion::HIDDEN])->save();
            }
        }
    }

    /** The MoxDOP page record of a target (a post by its WordPress id, a term page by its address). */
    private function page(?DigitalAsset $site, string $target, string $url): ?Page
    {
        if ($site === null) {
            return null;
        }
        if (preg_match('/^post:(\d+)$/', $target, $m) === 1) {
            $page = Page::query()->where('website_asset_id', $site->id)->where('wp_post_id', (int) $m[1])->first();
            if ($page !== null) {
                return $page;
            }
        }

        return $url !== '' ? Page::query()->where('website_asset_id', $site->id)->where('url_hash', hash('sha256', $url))->first()
            ?? Page::query()->where('website_asset_id', $site->id)->where('url', $url)->first() : null;
    }

    /** @return list<int> clusters the page answers for the brand */
    private function clusters(Brand $brand, DigitalAsset $site, ?Page $page): array
    {
        if ($page === null) {
            return [];
        }

        return DB::table('brand_cluster_pages')->where('brand_id', $brand->id)->where('website_asset_id', $site->id)->where('page_id', $page->id)
            ->where(fn ($q) => $q->whereNull('excluded')->orWhere('excluded', false))->pluck('cluster_id')->map(fn ($id): int => (int) $id)->all();
    }

    /** @return list<string> the page's clusters' queries, most searched for the brand first */
    private function queries(Brand $brand, DigitalAsset $site, ?Page $page): array
    {
        $clusters = $this->clusters($brand, $site, $page);
        if ($clusters === []) {
            return [];
        }

        return DB::table('cluster_queries as cq')->join('queries as q', 'q.id', '=', 'cq.query_id')
            ->leftJoin('brand_queries as bq', fn ($j) => $j->on('bq.query_id', '=', 'q.id')->where('bq.brand_id', '=', $brand->id))
            ->whereIn('cq.cluster_id', $clusters)->where(fn ($q) => $q->whereNull('q.hidden')->orWhere('q.hidden', false))
            ->groupBy('q.id', 'q.text')->selectRaw('q.text, coalesce(sum(bq.impressions_28d), 0) as reach')
            ->orderByDesc('reach')->limit(40)->pluck('q.text')->map(fn ($t): string => (string) $t)->all();
    }

    /**
     * The site's topic-map questions for the page's clusters (with the article's address once it is live).
     *
     * @return list<array{question: string, type: string, url: string}>
     */
    private function topics(DigitalAsset $site, ?Page $page): array
    {
        $brand = $site->brand;
        $clusters = $brand !== null ? $this->clusters($brand, $site, $page) : [];
        $services = $page !== null ? ContentTopic::query()->where('website_asset_id', $site->id)->where('page_id', $page->id)->whereNotNull('service_id')->pluck('service_id')->all() : [];
        if ($clusters === [] && $services === []) {
            return [];
        }

        return ContentTopic::query()->with('page:id,url')->where('website_asset_id', $site->id)->where('type', '!=', 'pillar')
            ->where(fn ($q) => $q->whereIn('cluster_id', $clusters)->orWhereIn('service_id', $services))
            ->where('status', '!=', 'dropped')->orderBy('position')->limit(40)->get()
            ->map(fn (ContentTopic $t): array => ['question' => (string) ($t->question ?: $t->title), 'type' => (string) $t->type,
                'url' => $t->page !== null && $t->page_id !== $page?->id && in_array($t->status, ['live', 'sent'], true) ? (string) $t->page->url : ''])
            ->filter(fn (array $t): bool => $t['question'] !== '')->values()->all();
    }

    /** @return list<array{title: string, url: string}> the site's live articles an answer may point to */
    private function articles(DigitalAsset $site, ?Page $page): array
    {
        $topics = array_values(array_filter($this->topics($site, $page), fn (array $t): bool => $t['url'] !== ''));
        if ($topics !== []) {
            return array_map(fn (array $t): array => ['title' => $t['question'], 'url' => $t['url']], array_slice($topics, 0, 30));
        }

        return Page::query()->where('website_asset_id', $site->id)->where('category', 'blog')->where('is_indexable', true)
            ->when($page !== null, fn ($q) => $q->whereKeyNot($page->id))->orderByDesc('id')->limit(30)->get(['url', 'title', 'h1'])
            ->map(fn (Page $p): array => ['title' => (string) ($p->h1 ?: $p->title), 'url' => (string) $p->url])->values()->all();
    }

    /* ------------------------------------------------------------------ Onarım masası */

    /** Onayla: the answer is published and sent to the site. */
    public function approve(Suggestion $suggestion, User $user): void
    {
        $question = SiteQuestion::query()->find((int) data_get($suggestion->action, 'question_id'));
        if ($question === null || in_array($question->status, [SiteQuestion::SPAM, SiteQuestion::PRIVATE], true)) {
            throw ValidationException::withMessages(['qa' => 'Bu soru artık yok.']);
        }
        $action = (array) $suggestion->action;
        $question->forceFill(['question' => self::clean((string) ($action['question'] ?? $question->question), 200), 'answer' => self::clean((string) ($action['answer'] ?? $question->answer), 1200),
            'status' => SiteQuestion::PUBLISHED, 'published_at' => now()])->save();
        $suggestion->forceFill(['status' => Suggestion::APPLIED, 'resolved_by' => $user->id, 'resolved_at' => now(), 'applied_at' => now()])->save();
        SyncSiteShowcaseJob::dispatch((int) $question->website_asset_id);
    }

    /** Gerek yok on the desk: the question never shows. @param list<int> $suggestionIds */
    public static function dismissed(array $suggestionIds): void
    {
        SiteQuestion::query()->whereIn('suggestion_id', $suggestionIds)->where('status', SiteQuestion::READY)->update(['status' => SiteQuestion::HIDDEN]);
    }

    /** "Kaldır" in Marka ayarları: a published question leaves the site. */
    public function unpublish(int $brandId, int $questionId): void
    {
        $question = SiteQuestion::query()->where('brand_id', $brandId)->findOrFail($questionId);
        $question->forceFill(['status' => SiteQuestion::HIDDEN])->save();
        SyncSiteShowcaseJob::dispatch((int) $question->website_asset_id);
    }

    /** Nightly: questions whose answer could not be written are asked again (the AI route may be back). */
    public function retry(): int
    {
        $ids = SiteQuestion::query()->where('status', SiteQuestion::NEW)->where('source', SiteQuestion::SOURCE_SITE)
            ->where('created_at', '<', now()->subHour())->limit(200)->pluck('id');
        foreach ($ids as $id) {
            AnswerSiteQuestionJob::dispatch((int) $id);
        }

        return $ids->count();
    }
}
