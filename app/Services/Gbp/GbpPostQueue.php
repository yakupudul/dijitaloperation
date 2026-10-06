<?php

namespace App\Services\Gbp;

use App\Ai\Agents\GbpPostQueueAgent;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\GbpQueuedPost;
use App\Models\Page;
use App\Models\User;
use App\Services\Advisor\GoogleAds\GoogleAdsAdvisorInputCollector;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Compliance\SectorPackRegistry;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\SeoTasks\SeoText;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Otomatik İşletme Profili gönderileri (yakup, 2026-10-06, ADR-078): every location of an operational brand gets one
 * post a day, planned 30 days ahead from the pages of the brand's own site.
 *
 *  - Rules pick the slots: a page × an angle (a service page gives up to five: what it is, who it suits, the process,
 *    a question, aftercare; a blog post two). New pages first, then service pages and blog posts in turn, the least
 *    recently used first. A page waits 21 days between posts, a page × angle 180 days, and another location of the
 *    same brand does not use the same page × angle within 14 days. Too few pages = fewer posts, never repeats.
 *  - AI writes each slot from that page only; texts outside 200–1500 characters, with contact data, a blocking
 *    sector-compliance hit or too close to a recent post are dropped (the day stays empty).
 *  - The Admin approves the month in bulk (each row can be edited or skipped; a skipped day is planned again).
 *  - On its day (10:00 Istanbul + a few minutes per location) an approved post is checked again (page still on the
 *    site and indexable, brand operational, compliance, no manual post that day) and goes out through the ADR-073 write
 *    with the page's featured image and link. A draft whose day passed expires.
 */
final class GbpPostQueue
{
    public const int HORIZON_DAYS = 30;

    /** Planned days below this start a refill. */
    public const int REFILL_BELOW = 23;

    public const int BATCH = 10;

    public const int PAGE_GAP_DAYS = 21;

    public const int ANGLE_REUSE_DAYS = 180;

    public const int SIBLING_GAP_DAYS = 14;

    public const int PUBLISH_HOUR = 10;

    public const int TEXT_MIN = 200;

    public const int TEXT_MAX = 1500;

    public const float SIMILARITY_MAX = 0.5;

    /** @var array<string, array{label: string, hint: string}> */
    public const array ANGLES = [
        'tanim' => ['label' => 'Nedir', 'hint' => 'what the service is and what it is for, in plain words'],
        'kimler' => ['label' => 'Kimler için', 'hint' => 'who it suits and when people usually consider it, as the page says (no personal advice)'],
        'surec' => ['label' => 'Süreç', 'hint' => 'how the process goes, step by step, as the page describes it'],
        'soru' => ['label' => 'Soru-cevap', 'hint' => 'one question people often ask about it and the answer the page gives'],
        'bakim' => ['label' => 'Sonrası', 'hint' => 'what to expect or do afterwards, as the page describes it'],
        'bolge' => ['label' => 'Bölge', 'hint' => 'this service for people in the area, as the page describes it'],
        'ozet' => ['label' => 'Yazıdan', 'hint' => 'the key point of the article, inviting the reader to read it'],
        'ipucu' => ['label' => 'İpucu', 'hint' => 'one practical tip from the article'],
    ];

    private const array ANGLES_BY_CATEGORY = [
        'hizmet' => ['tanim', 'kimler', 'surec', 'soru', 'bakim'],
        'lokasyon' => ['bolge', 'tanim'],
        'blog' => ['ozet', 'ipucu'],
    ];

    private const string CONTACT_PATTERN = '~https?://|www\.|[\w.+-]+@[\w-]+\.[\w.]+|\+?\d[\d\s().-]{8,}\d|#\w~iu';

    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly AiTaskQueue $tasks,
        private readonly ExternalWriteService $writes,
        private readonly GbpDailyWorkspace $daily,
    ) {}

    /** @return Builder<DigitalAsset> Business Profile locations of operational brands */
    public static function locations(): Builder
    {
        return DigitalAsset::query()->operational()->whereIn('digital_assets.type', ['google_business_profile', 'gbp'])->whereNotNull('digital_assets.brand_id');
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now('Europe/Istanbul')->startOfDay();
    }

    /** Local publish time of a location's post on a day (spread over the hour so 100 locations do not go at once). */
    public static function publishAt(int $assetId, string $day): CarbonImmutable
    {
        return CarbonImmutable::parse($day, 'Europe/Istanbul')->setTime(self::PUBLISH_HOUR, $assetId % 50);
    }

    /**
     * Days of the next 30 (from tomorrow) without a planned, approved or published post.
     *
     * @return list<string>
     */
    public function emptyDays(DigitalAsset $location): array
    {
        $first = self::today()->addDay();
        $taken = GbpQueuedPost::query()->where('digital_asset_id', $location->id)
            ->whereIn('status', [GbpQueuedPost::DRAFT, GbpQueuedPost::APPROVED, GbpQueuedPost::PUBLISHED])
            ->whereBetween('publish_on', [$first->toDateString(), $first->addDays(self::HORIZON_DAYS - 1)->toDateString()])
            ->pluck('publish_on')->map(fn ($d): string => substr((string) $d, 0, 10))->flip();
        $out = [];
        for ($i = 0; $i < self::HORIZON_DAYS; $i++) {
            $day = $first->addDays($i)->toDateString();
            if (! $taken->has($day)) {
                $out[] = $day;
            }
        }

        return $out;
    }

    /**
     * Plans the location's empty days. Null while a delegated call waits for Claude (the job runs again).
     *
     * @return array{status: string, added: int, empty: int}|null status: ready | full | no_content
     */
    public function fill(DigitalAsset $location, bool $force = false): ?array
    {
        $location->loadMissing('brand.customer');
        if ($location->brand === null || ! $location->brand->isOperational()) {
            throw new RuntimeException('Marka operasyonel değil; AI çalışmaz.');
        }
        $days = $this->emptyDays($location);
        if ($days === [] || (! $force && self::HORIZON_DAYS - count($days) >= self::REFILL_BELOW)) {
            return ['status' => 'full', 'added' => 0, 'empty' => count($days)];
        }
        $slots = $this->slots($location, $days);
        if ($slots === []) {
            return ['status' => 'no_content', 'added' => 0, 'empty' => count($days)];
        }
        $recent = $this->recentTexts($location);
        $context = [
            'business' => $location->brand->name, 'area' => $this->area($location),
            'offerings' => array_column(app(GbpAssistant::class)->offerings($location->brand), 'name'),
            'compliance' => app(SectorPackRegistry::class)->rulesForBrand($location->brand)->pluck('message')->unique()->values()->take(12)->all(),
            'recent_posts' => array_map(fn (string $t): string => mb_substr($t, 0, 300), array_slice($recent, 0, 12)),
        ];
        $answers = [];
        $waiting = false;
        foreach (array_chunk($slots, self::BATCH) as $batch) {
            [$raw, $versionId] = $this->ask($context + ['slots' => array_map(fn (array $s): array => [
                'slot' => $s['slot'], 'angle' => $s['angle'], 'angle_hint' => self::ANGLES[$s['angle']]['hint'],
                'page' => ['title' => (string) ($s['page']->title ?: $s['page']->h1), 'category' => $s['page']->category, 'url' => (string) $s['page']->url, 'text' => $s['page']->aiText(3500)],
            ], $batch)]);
            if ($raw === null) {
                $waiting = true;

                continue;
            }
            foreach ((array) ($raw['posts'] ?? []) as $row) {
                if (is_array($row) && isset($row['slot'])) {
                    $answers[(int) $row['slot']] ??= $row + ['prompt_version_id' => $versionId];
                }
            }
        }
        if ($waiting) {
            return null;
        }
        $added = 0;
        foreach ($slots as $slot) {
            $row = $answers[$slot['slot']] ?? null;
            $text = $row !== null ? $this->checked($location, (string) ($row['text'] ?? ''), $recent) : null;
            if ($text === null) {
                continue;
            }
            $recent[] = $text;
            GbpQueuedPost::query()->create([
                'digital_asset_id' => $location->id, 'brand_id' => $location->brand_id, 'page_id' => $slot['page']->id, 'angle' => $slot['angle'],
                'publish_on' => $slot['day'], 'summary' => $text, 'url' => (string) $slot['page']->url,
                'action_type' => in_array($row['action_type'] ?? null, ['LEARN_MORE', 'BOOK', 'CALL'], true) ? $row['action_type'] : 'LEARN_MORE',
                'image_url' => $this->featuredImage($slot['page']), 'status' => GbpQueuedPost::DRAFT, 'prompt_version_id' => $row['prompt_version_id'] ?? null,
            ]);
            $added++;
        }

        return ['status' => 'ready', 'added' => $added, 'empty' => count($days) - $added];
    }

    /**
     * The pages of the brands' websites that posts can be written from: indexable, ≥ 150 words, Turkish (no other
     * language field or path prefix), service / location / blog.
     *
     * @param  list<int>  $brandIds
     * @param  list<string>  $columns
     * @return Collection<int, Page>
     */
    public function usablePages(array $brandIds, array $columns = ['id', 'website_asset_id', 'path']): Collection
    {
        $sites = DigitalAsset::query()->whereIn('brand_id', $brandIds)->where('type', 'website')->pluck('id');

        return Page::query()->whereIn('website_asset_id', $sites)->where('is_indexable', true)->where('word_count', '>=', 150)
            ->where(fn ($q) => $q->whereNull('language')->orWhere('language', 'tr'))
            ->where(fn ($q) => $q->whereIn('category', array_keys(self::ANGLES_BY_CATEGORY))->orWhereNull('category'))
            ->orderBy('id')->get(array_values(array_unique([...$columns, 'website_asset_id', 'path'])))
            ->reject(fn (Page $p): bool => preg_match('#^/(?!tr/)[a-z]{2}(?:-[a-z]{2})?/#i', (string) $p->path) === 1)->values();
    }

    /**
     * Per brand: whether it has a website and how many pages posts can be written from (for the "why empty" note).
     *
     * @param  list<int>  $brandIds
     * @return array<int, array{sites: int, pages: int}>
     */
    public function sources(array $brandIds): array
    {
        $sites = DigitalAsset::query()->whereIn('brand_id', $brandIds)->where('type', 'website')->get(['id', 'brand_id']);
        $pages = $this->usablePages($brandIds)->countBy('website_asset_id');
        $out = [];
        foreach ($brandIds as $brandId) {
            $own = $sites->where('brand_id', $brandId)->pluck('id');
            $out[$brandId] = ['sites' => $own->count(), 'pages' => (int) $own->sum(fn ($id): int => (int) ($pages[$id] ?? 0))];
        }

        return $out;
    }

    /**
     * The slots for the empty days (see the class note), in day order.
     *
     * @param  list<string>  $days
     * @return list<array{slot: int, day: string, page: Page, angle: string}>
     */
    public function slots(DigitalAsset $location, array $days): array
    {
        if ($days === []) {
            return [];
        }
        $pages = $this->usablePages([(int) $location->brand_id], ['id', 'website_asset_id', 'url', 'path', 'title', 'h1', 'category', 'changed_at', 'created_at', 'wp_post_id']);
        if ($pages->isEmpty()) {
            return [];
        }
        $since = self::today()->subDays(self::ANGLE_REUSE_DAYS)->toDateString();
        $history = GbpQueuedPost::query()->where('digital_asset_id', $location->id)->where('status', '!=', GbpQueuedPost::FAILED)
            ->where('publish_on', '>=', $since)->get(['page_id', 'angle', 'publish_on']);
        $used = $history->map(fn (GbpQueuedPost $r): string => $r->page_id.':'.$r->angle)->flip();
        $lastUsed = $history->groupBy('page_id')->map(fn (Collection $rows): string => substr((string) $rows->max('publish_on'), 0, 10));
        $siblings = GbpQueuedPost::query()->where('brand_id', $location->brand_id)->where('digital_asset_id', '!=', $location->id)
            ->whereNotIn('status', [GbpQueuedPost::FAILED, GbpQueuedPost::SKIPPED, GbpQueuedPost::EXPIRED])
            ->where('publish_on', '>=', self::today()->subDays(self::SIBLING_GAP_DAYS)->toDateString())
            ->get(['page_id', 'angle'])->map(fn (GbpQueuedPost $r): string => $r->page_id.':'.$r->angle)->flip();
        $gapBefore = CarbonImmutable::parse($days[0])->subDays(self::PAGE_GAP_DAYS)->toDateString();
        $fresh = self::today()->subDays(30);
        $candidates = [];
        foreach ($pages as $page) {
            $category = $page->category ?? 'blog';
            $angles = array_values(array_filter(self::ANGLES_BY_CATEGORY[$category] ?? self::ANGLES_BY_CATEGORY['blog'],
                fn (string $a): bool => ! $used->has($page->id.':'.$a) && ! $siblings->has($page->id.':'.$a)));
            $last = $lastUsed->get($page->id);
            if ($angles === [] || ($last !== null && $last > $gapBefore)) {
                continue;
            }
            $changed = $page->changed_at ?? $page->created_at;
            $candidates[] = ['page' => $page, 'angle' => $angles[0], 'last' => $last ?? '', 'group' => $last === null && $changed !== null && $changed->greaterThan($fresh) ? 'new' : ($category === 'hizmet' ? 'service' : 'other')];
        }
        $byLast = fn (array $a, array $b): int => [$a['last'], $a['page']->id] <=> [$b['last'], $b['page']->id];
        $group = fn (string $name): array => collect($candidates)->where('group', $name)->sort($byLast)->values()->all();
        $new = collect($candidates)->where('group', 'new')->sortByDesc(fn (array $c): string => (string) ($c['page']->changed_at ?? $c['page']->created_at))->values()->all();
        [$service, $other] = [$group('service'), $group('other')];
        $ordered = $new;
        // Two service pages, then one blog / other page, in turn.
        while ($service !== [] || $other !== []) {
            for ($i = 0; $i < 2 && $service !== []; $i++) {
                $ordered[] = array_shift($service);
            }
            if ($other !== []) {
                $ordered[] = array_shift($other);
            }
        }
        $out = [];
        foreach (array_slice($ordered, 0, count($days)) as $index => $candidate) {
            $out[] = ['slot' => $index + 1, 'day' => $days[$index], 'page' => $candidate['page'], 'angle' => $candidate['angle']];
        }

        return $out;
    }

    /** @param  list<int>  $ids */
    public function approve(User $user, array $ids): int
    {
        $this->guard($user);

        return GbpQueuedPost::query()->whereIn('id', $ids)->where('status', GbpQueuedPost::DRAFT)
            ->update(['status' => GbpQueuedPost::APPROVED, 'approved_by' => $user->id, 'approved_at' => now(), 'updated_at' => now()]);
    }

    /** Approves every draft of the next 30 days (of one location or one brand when given). */
    public function approveAll(User $user, ?int $assetId = null, ?int $brandId = null): int
    {
        $this->guard($user);

        return GbpQueuedPost::query()->where('status', GbpQueuedPost::DRAFT)->where('publish_on', '>=', self::today()->toDateString())
            ->when($assetId !== null, fn ($q) => $q->where('digital_asset_id', $assetId))
            ->when($brandId !== null, fn ($q) => $q->where('brand_id', $brandId))
            ->update(['status' => GbpQueuedPost::APPROVED, 'approved_by' => $user->id, 'approved_at' => now(), 'updated_at' => now()]);
    }

    /** The operator's edit of a planned text (same checks as AI texts, except similarity). */
    public function edit(User $user, GbpQueuedPost $post, string $text): void
    {
        $this->guard($user);
        if (! in_array($post->status, [GbpQueuedPost::DRAFT, GbpQueuedPost::APPROVED], true)) {
            throw ValidationException::withMessages(['post' => 'Bu gönderi artık düzenlenemez.']);
        }
        $text = trim($text);
        if (mb_strlen($text) < 40 || mb_strlen($text) > self::TEXT_MAX) {
            throw ValidationException::withMessages(['post' => 'Metin 40–1500 karakter olmalı.']);
        }
        $blocking = GbpAssistant::blockingHits($post->digitalAsset?->brand, $text);
        if ($blocking !== []) {
            throw ValidationException::withMessages(['post' => 'Sektör uyum kuralına takılıyor: '.implode(', ', $blocking).'.']);
        }
        $post->forceFill(['summary' => $text])->save();
    }

    /** Skips a planned post; its day is planned again on the next refill. */
    public function skip(User $user, GbpQueuedPost $post): void
    {
        $this->guard($user);
        if (in_array($post->status, [GbpQueuedPost::DRAFT, GbpQueuedPost::APPROVED], true)) {
            $post->forceFill(['status' => GbpQueuedPost::SKIPPED, 'note' => 'Operatör atladı.'])->save();
        }
    }

    /** A post that could not be published goes again today (approved by the one who retries). */
    public function retry(User $user, GbpQueuedPost $post): void
    {
        $this->guard($user);
        if ($post->status !== GbpQueuedPost::FAILED && ! ($post->status === GbpQueuedPost::PUBLISHED && $post->writeAction?->status === 'failed')) {
            throw ValidationException::withMessages(['post' => 'Yalnız yayınlanamayan gönderi yeniden denenir.']);
        }
        $today = self::today()->toDateString();
        $post->forceFill(['status' => GbpQueuedPost::APPROVED, 'approved_by' => $user->id, 'approved_at' => now(), 'note' => null,
            'external_write_action_id' => null, 'publish_on' => max($today, substr((string) $post->publish_on, 0, 10))])->save();
    }

    /**
     * "Bugün paylaş": the location's post of today, or else the next planned one from the pool (moved to today), goes
     * out now, approved by the one who clicks. One post a day: refused when the location already posted today. The day
     * the post came from is empty again and is planned on the next refill.
     *
     * @return array{result: 'published'|'skipped'|'failed', post: GbpQueuedPost, moved_from: ?string}
     */
    public function publishToday(User $user, DigitalAsset $location, ?int $postId = null): array
    {
        $this->guard($user);
        $today = self::today()->toDateString();
        $posted = GbpQueuedPost::query()->where('digital_asset_id', $location->id)->where('publish_on', $today)->where('status', GbpQueuedPost::PUBLISHED)
            ->where(fn ($q) => $q->whereNull('external_write_action_id')->orWhereHas('writeAction', fn ($w) => $w->where('status', '!=', 'failed')))->exists();
        if ($posted || $this->manualPostToday((int) $location->id)) {
            throw ValidationException::withMessages(['post' => 'Bu işletmede bugün gönderi paylaşıldı; günde bir gönderi kuralı gereği yenisi yarın.']);
        }
        $post = $this->pool($location)->when($postId !== null, fn ($q) => $q->whereKey($postId))->first()
            ?? throw ValidationException::withMessages(['post' => 'Havuzda bu işletme için hazır gönderi yok; önce boş günleri doldurun.']);
        $from = substr((string) $post->publish_on, 0, 10);
        // Claimed once (a double click or the 10:00 run never sends it twice).
        $claimed = GbpQueuedPost::query()->whereKey($post->id)->whereIn('status', [GbpQueuedPost::DRAFT, GbpQueuedPost::APPROVED])
            ->update(['status' => GbpQueuedPost::PUBLISHED, 'publish_on' => $today, 'approved_by' => $user->id, 'approved_at' => now(), 'updated_at' => now()]);
        if ($claimed !== 1) {
            throw ValidationException::withMessages(['post' => 'Gönderi şu an başka bir işlemde; sayfayı yenileyin.']);
        }
        $post = $post->refresh()->load(['digitalAsset.brand.customer', 'page']);

        return ['result' => $this->publish($post), 'post' => $post->refresh(), 'moved_from' => $from !== $today ? $from : null];
    }

    /**
     * The location's planned posts from today on, in the order "Bugün paylaş" takes them.
     *
     * @return Builder<GbpQueuedPost>
     */
    public function pool(DigitalAsset $location): Builder
    {
        return GbpQueuedPost::query()->where('digital_asset_id', $location->id)->whereIn('status', [GbpQueuedPost::DRAFT, GbpQueuedPost::APPROVED])
            ->where('publish_on', '>=', self::today()->toDateString())->orderBy('publish_on')->orderBy('id');
    }

    /**
     * Publishes the approved posts whose time has come and expires the drafts whose day passed.
     *
     * @return array{published: int, skipped: int, failed: int, expired: int}
     */
    public function publishDue(): array
    {
        $now = CarbonImmutable::now('Europe/Istanbul');
        $today = $now->toDateString();
        $out = ['published' => 0, 'skipped' => 0, 'failed' => 0, 'expired' => 0];
        $out['expired'] = GbpQueuedPost::query()->where('status', GbpQueuedPost::DRAFT)->where('publish_on', '<', $today)
            ->update(['status' => GbpQueuedPost::EXPIRED, 'note' => 'Günü geldiğinde onaylanmamıştı.', 'updated_at' => now()]);
        $out['expired'] += GbpQueuedPost::query()->where('status', GbpQueuedPost::APPROVED)->where('publish_on', '<', $now->subDay()->toDateString())
            ->update(['status' => GbpQueuedPost::EXPIRED, 'note' => 'Günü geçti (yayın zamanında sistem çalışmıyordu).', 'updated_at' => now()]);
        GbpQueuedPost::query()->where('status', GbpQueuedPost::APPROVED)->where('publish_on', '<=', $today)->orderBy('publish_on')->orderBy('id')
            ->with(['digitalAsset.brand.customer', 'page'])->limit(500)->get()
            ->each(function (GbpQueuedPost $post) use ($now, &$out): void {
                if ($now->lessThan(self::publishAt((int) $post->digital_asset_id, substr((string) $post->publish_on, 0, 10)))) {
                    return;
                }
                // Claimed once: a second run never sends the same post again.
                if (GbpQueuedPost::query()->whereKey($post->id)->where('status', GbpQueuedPost::APPROVED)->update(['status' => GbpQueuedPost::PUBLISHED, 'updated_at' => now()]) !== 1) {
                    return;
                }
                $result = $this->publish($post);
                $out[$result]++;
            });

        return $out;
    }

    /** @return 'published'|'skipped'|'failed' */
    private function publish(GbpQueuedPost $post): string
    {
        $asset = $post->digitalAsset;
        $reason = match (true) {
            $asset === null || $asset->brand === null || ! $asset->brand->isOperational() => 'Marka operasyonel değil.',
            $post->page_id !== null && ($post->page === null || ! $post->page->is_indexable) => 'Sayfa sitede yok ya da dizine kapalı.',
            GbpAssistant::blockingHits($asset->brand, (string) $post->summary) !== [] => 'Metin sektör uyum kuralına takılıyor.',
            $this->manualPostToday((int) $post->digital_asset_id) => 'Bugün elle gönderi var.',
            default => null,
        };
        if ($reason !== null) {
            $post->forceFill(['status' => GbpQueuedPost::SKIPPED, 'note' => $reason])->save();

            return 'skipped';
        }
        $approver = User::query()->find($post->approved_by);
        try {
            if ($approver === null) {
                throw new RuntimeException('Onaylayan kullanıcı yok.');
            }
            $action = $this->writes->requestLocalPost($approver, $asset, ['summary' => (string) $post->summary, 'url' => $post->url,
                'action_type' => $post->action_type, 'image_url' => $post->image_url, 'queue_id' => (int) $post->id]);
            $post->forceFill(['external_write_action_id' => $action->id, 'note' => null])->save();

            return 'published';
        } catch (ValidationException $exception) {
            $message = (string) collect($exception->errors())->flatten()->first();
        } catch (HttpException|Throwable $exception) {
            $message = $exception->getMessage() !== '' ? $exception->getMessage() : 'Yazma izni yok.';
        }
        $post->forceFill(['status' => GbpQueuedPost::FAILED, 'note' => mb_substr($message, 0, 480)])->save();

        return 'failed';
    }

    /**
     * Location / brand overview for the desk: drafts, approved, planned days and a content warning.
     *
     * @return array{planned: int, drafts: int, approved: int, empty: int, low: bool}
     */
    public function summary(DigitalAsset $location): array
    {
        $rows = GbpQueuedPost::query()->where('digital_asset_id', $location->id)->where('publish_on', '>', self::today()->toDateString())
            ->whereIn('status', [GbpQueuedPost::DRAFT, GbpQueuedPost::APPROVED])->pluck('status');
        $empty = count($this->emptyDays($location));

        return ['planned' => $rows->count(), 'drafts' => $rows->filter(fn (string $s): bool => $s === GbpQueuedPost::DRAFT)->count(),
            'approved' => $rows->filter(fn (string $s): bool => $s === GbpQueuedPost::APPROVED)->count(), 'empty' => $empty, 'low' => $empty > self::HORIZON_DAYS - self::REFILL_BELOW];
    }

    /** Folded-word overlap (Jaccard) of two texts, 0–1. */
    public static function similarity(string $a, string $b): float
    {
        $words = fn (string $t): array => array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', SeoText::fold($t)) ?: [], fn (string $w): bool => mb_strlen($w) >= 4)));
        [$x, $y] = [$words($a), $words($b)];
        if ($x === [] || $y === []) {
            return 0.0;
        }

        return count(array_intersect($x, $y)) / count(array_unique([...$x, ...$y]));
    }

    /** @param  list<string>  $recent */
    private function checked(DigitalAsset $location, string $text, array $recent): ?string
    {
        $text = trim(preg_replace("/[ \t]+/u", ' ', strip_tags($text)) ?? '');
        if (mb_strlen($text) > self::TEXT_MAX) {
            $cut = mb_substr($text, 0, self::TEXT_MAX);
            $end = max((int) mb_strrpos($cut, '. '), (int) mb_strrpos($cut, ".\n"), (int) mb_strrpos($cut, '! '), (int) mb_strrpos($cut, '? '));
            $text = $end > self::TEXT_MAX / 2 ? mb_substr($cut, 0, $end + 1) : '';
        }
        if (mb_strlen($text) < self::TEXT_MIN || preg_match(self::CONTACT_PATTERN, $text) === 1 || GbpAssistant::blockingHits($location->brand, $text) !== []) {
            return null;
        }
        foreach ($recent as $other) {
            if (self::similarity($text, $other) > self::SIMILARITY_MAX) {
                return null;
            }
        }

        return $text;
    }

    /** @return list<string> texts of the brand's posts of the last 60 days and the planned ones, newest first */
    private function recentTexts(DigitalAsset $location): array
    {
        return GbpQueuedPost::query()->where('brand_id', $location->brand_id)->whereNotIn('status', [GbpQueuedPost::FAILED, GbpQueuedPost::SKIPPED])
            ->where('publish_on', '>=', self::today()->subDays(60)->toDateString())->orderByDesc('publish_on')->limit(60)->pluck('summary')
            ->map(fn ($t): string => (string) $t)->all();
    }

    private function manualPostToday(int $assetId): bool
    {
        return ExternalWriteAction::query()->where('digital_asset_id', $assetId)->where('action', ExternalWriteAction::ACTION_LOCAL_POST)
            ->whereIn('status', ['queued', 'running', 'succeeded', 'scheduled'])->whereNull('request_payload->queue_id')
            ->where('created_at', '>=', self::today()->utc())->exists();
    }

    /** "İlçe, İl" of the location's address (latest snapshot), or "". */
    private function area(DigitalAsset $location): string
    {
        $resource = $this->daily->resource($location);
        $row = $resource !== null ? DB::table('gbp_location_snapshots')->where('external_resource_id', $resource->id)->orderByDesc('captured_at')->orderByDesc('id')->first(['storefront_address']) : null;
        $address = $row !== null ? GoogleAdsAdvisorInputCollector::decode($row->storefront_address) : [];

        return implode(', ', array_filter([(string) ($address['sublocality'] ?? ''), (string) ($address['locality'] ?? ''), (string) ($address['administrativeArea'] ?? '')]));
    }

    /** The page's WordPress featured image (from the connector's snapshot), or null. */
    private function featuredImage(Page $page): ?string
    {
        if ($page->wp_post_id === null) {
            return null;
        }
        $mediaId = DB::table('website_cms_object_snapshot')->where('digital_asset_id', $page->website_asset_id)->where('object_id', (string) $page->wp_post_id)
            ->whereNotNull('featured_media_id')->orderByDesc('id')->value('featured_media_id');
        $url = $mediaId !== null && (string) $mediaId !== '0' ? DB::table('website_cms_object_snapshot')->where('digital_asset_id', $page->website_asset_id)
            ->where('object_id', (string) $mediaId)->where('object_type', 'attachment')->orderByDesc('id')->value('permalink') : null;

        return is_string($url) && preg_match('~^https://\S+\.(?:jpe?g|png)(?:\?\S*)?$~i', $url) === 1 ? $url : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?array<mixed>, 1: ?int}
     */
    private function ask(array $data): array
    {
        $agent = new GbpPostQueueAgent;
        $answer = $this->tasks->delegatedCall($agent, $data);
        if ($answer === 'queued') {
            return [null, null];
        }
        if ($answer === 'error') {
            throw new RuntimeException('Claude gönderileri yazamadı.');
        }
        if (is_array($answer)) {
            return [$answer, $agent->promptVersionId()];
        }
        $route = $this->routes->resolve(GbpPostQueueAgent::OPERATION);
        if ($route->isEmpty()) {
            throw new RuntimeException('Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu.');
        }
        $this->runtime->prepare(array_keys($route->providerModels));
        $response = (array) $agent->prompt("DATA_JSON\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            provider: $route->providerModels, timeout: 180)->toArray();

        return [$response, $agent->promptVersionId()];
    }

    private function guard(User $user): void
    {
        abort_unless(ExternalWriteService::allowed($user, ExternalWriteAction::CHANNEL_GBP), 403, 'Gönderileri yalnız Admin onaylar.');
    }
}
