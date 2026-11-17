<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\SocialThread;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What each tab of the Sosyal screen reads (redesign, yakup 2026-11-17). Every view is bounded: one day, one week, one
 * month of counts, 25 rows of a list, 24 tiles of a grid. A tab reads only its own data, so the page stays the same
 * size however many posts there are.
 */
final class SocialScreen
{
    public const int PER_PAGE = 25;

    public const int GRID_STEP = 24;

    /** Days of ready content under which a channel's stock bar turns red. */
    public const int STOCK_LOW = 14;

    public const array CHANNELS = [
        SocialAccount::INSTAGRAM => 'Instagram', SocialAccount::FACEBOOK => 'Facebook',
        SocialAccount::LINKEDIN => 'LinkedIn', SocialAccount::THREADS => 'Threads',
    ];

    public const array STATUS_FILTERS = [
        '' => 'Tümü', 'onay' => 'Onay bekleyen', 'sirada' => 'Sırada', 'yayinda' => 'Yayında', 'sorunlu' => 'Sorunlu', 'atlanan' => 'Atlanan',
    ];

    public const array KIND_FILTERS = ['' => 'Her tür', 'image' => 'Gönderi', 'carousel' => 'Kaydırmalı', 'reels' => 'Video', 'story' => 'Hikâye', 'text' => 'Metin'];

    private const array STATUS_MAP = [
        'onay' => [SocialPost::DRAFT],
        'sirada' => [SocialPost::APPROVED],
        'yayinda' => [SocialPost::PUBLISHED],
        'sorunlu' => [SocialPost::FAILED, SocialPost::PARTIAL, SocialPost::EXPIRED],
        'atlanan' => [SocialPost::SKIPPED],
    ];

    private string $tz = SocialPlanner::TIMEZONE;

    /**
     * The channel dots under the title: on = publishes by itself, manual = connected but waits for a hand, off = not connected.
     *
     * @param  Collection<int, SocialAccount>  $accounts
     * @return list<array{key: string, label: string, state: string}>
     */
    public function channels(Collection $accounts): array
    {
        $out = [];
        foreach (self::CHANNELS as $key => $label) {
            $account = $accounts->where('platform', $key)->where('enabled', true)->first();
            $out[] = ['key' => $key, 'label' => $label, 'state' => $account === null ? 'off' : ($account->isManual() ? 'manual' : 'on')];
        }
        $out[] = ['key' => 'youtube', 'label' => 'YouTube', 'state' => 'off'];

        return $out;
    }

    /**
     * "Sizi bekleyen": only what needs the operator, most urgent first.
     *
     * @param  Collection<int, SocialAccount>  $accounts
     * @return list<array{key: string, title: string, text: string, tab: string, params: array<string, string|int>, cta: string}>
     */
    public function waiting(Collection $accounts): array
    {
        $out = [];
        $failed = $this->posts()->whereIn('status', [SocialPost::FAILED, SocialPost::PARTIAL])->count();
        if ($failed > 0) {
            $out[] = ['key' => 'failed', 'title' => $failed.' gönderi yayınlanamadı', 'text' => 'Nedeni gönderinin içinde yazıyor; düzeltip yeniden deneyin.', 'tab' => 'gonderiler', 'params' => ['status' => 'sorunlu'], 'cta' => 'Aç'];
        }
        $drafts = $this->posts()->where('status', SocialPost::DRAFT)->orderBy('publish_at')->limit(2)->get();
        $draftCount = $this->posts()->where('status', SocialPost::DRAFT)->count();
        if ($draftCount === 1) {
            $post = $drafts->first();
            $out[] = ['key' => 'draft', 'title' => 'Bir gönderi onayınızı bekliyor', 'text' => '“'.($post->title ?: mb_strimwidth((string) $post->caption, 0, 70, '…')).'”, '.$post->publish_at->timezone($this->tz)->translatedFormat('j F H:i'), 'tab' => 'gonderiler', 'params' => ['status' => 'onay', 'open' => $post->id], 'cta' => 'Aç'];
        } elseif ($draftCount > 1) {
            $out[] = ['key' => 'draft', 'title' => $draftCount.' gönderi onayınızı bekliyor', 'text' => 'İlki '.$drafts->first()->publish_at->timezone($this->tz)->translatedFormat('j F H:i').' için hazırlandı.', 'tab' => 'gonderiler', 'params' => ['status' => 'onay'], 'cta' => 'Aç'];
        }
        $talks = SocialThread::query()->onEnabledAccount()->where('status', SocialThread::OPEN)->where('last_direction', 'in')->count();
        if ($talks > 0) {
            $out[] = ['key' => 'talks', 'title' => $talks.' konuşma cevap bekliyor', 'text' => 'Yorumlar ve mesajlar; hazır cevabı okuyup gönderin.', 'tab' => 'konusmalar', 'params' => [], 'cta' => 'Aç'];
        }
        foreach ($accounts->where('enabled', true) as $account) {
            $ends = $account->token_expires_at;
            if ($account->isManual() && $account->platform !== SocialAccount::LINKEDIN) {
                $out[] = ['key' => 'account-'.$account->id, 'title' => $account->platformLabel().' bağlantısı kopmuş', 'text' => 'Yeniden bağlanana kadar bu kanala gönderi çıkmaz.', 'tab' => 'ayarlar', 'params' => ['section' => 'hesaplar'], 'cta' => 'Bağla'];
            } elseif ($ends !== null && ! $account->refresh_token && $account->platform === SocialAccount::LINKEDIN && $ends->isBefore(now()->addDays(10)) && ! $account->isManual()) {
                $out[] = ['key' => 'account-'.$account->id, 'title' => 'LinkedIn bağlantısı '.$ends->timezone($this->tz)->format('d.m.Y').' tarihinde bitiyor', 'text' => 'Bitmeden yeniden bağlayın; yoksa gönderiler elle paylaşılmayı bekler.', 'tab' => 'ayarlar', 'params' => ['section' => 'hesaplar'], 'cta' => 'Bağla'];
            } elseif (! empty(data_get($account->meta, 'inbox_errors'))) {
                $out[] = ['key' => 'account-'.$account->id, 'title' => $account->platformLabel().' mesajları okunamıyor', 'text' => mb_strimwidth(implode(' · ', (array) data_get($account->meta, 'inbox_errors')), 0, 110, '…'), 'tab' => 'ayarlar', 'params' => ['section' => 'hesaplar'], 'cta' => 'Aç'];
            }
        }

        return $out;
    }

    /**
     * One day's posts in time order, whatever their state.
     *
     * @return Collection<int, SocialPost>
     */
    public function day(Carbon $day, string $channel = ''): Collection
    {
        $start = $day->copy()->timezone($this->tz)->startOfDay();

        return $this->onChannel($this->posts(), $channel)->where('status', '!=', SocialPost::SKIPPED)
            ->whereBetween('publish_at', [$start->copy()->utc(), $start->copy()->endOfDay()->utc()])
            ->with(['targets.account'])->orderBy('publish_at')->orderBy('id')->get();
    }

    /**
     * "Hazır içerik kaç gün yeter": for every line the last day that still has a ready post.
     *
     * @return list<array{label: string, days: int, count: int, low: bool}>
     */
    public function stock(): array
    {
        $ready = fn (): Builder => $this->posts()->whereIn('status', [SocialPost::DRAFT, SocialPost::APPROVED])->where('publish_at', '>', now());
        $lines = [
            'Instagram video' => $this->onChannel($ready(), SocialAccount::INSTAGRAM)->where('kind', 'reels'),
            'Instagram hikâye' => $this->onChannel($ready(), SocialAccount::INSTAGRAM)->where('kind', 'story'),
            'Instagram gönderi' => $this->onChannel($ready(), SocialAccount::INSTAGRAM)->whereIn('kind', ['image', 'carousel']),
            'LinkedIn' => $this->onChannel($ready(), SocialAccount::LINKEDIN),
            'Threads' => $this->onChannel($ready(), SocialAccount::THREADS),
        ];
        $today = now($this->tz)->startOfDay();
        $out = [];
        foreach ($lines as $label => $query) {
            $last = (clone $query)->max('publish_at');
            $days = $last === null ? 0 : (int) max(0, $today->diffInDays(Carbon::parse($last)->timezone($this->tz)->startOfDay()));
            $out[] = ['label' => $label, 'days' => $days, 'count' => (clone $query)->count(), 'low' => $days < self::STOCK_LOW];
        }

        return $out;
    }

    /**
     * The week's posts by day (Y-m-d => posts).
     *
     * @return array<string, Collection<int, SocialPost>>
     */
    public function week(Carbon $start, string $channel = '', bool $stories = true): array
    {
        $posts = $this->onChannel($this->posts(), $channel)->where('status', '!=', SocialPost::SKIPPED)
            ->when(! $stories, fn (Builder $q) => $q->where('kind', '!=', 'story'))
            ->whereBetween('publish_at', [$start->copy()->utc(), $start->copy()->addDays(6)->endOfDay()->utc()])
            ->with(['targets.account'])->orderBy('publish_at')->orderBy('id')->get()
            ->groupBy(fn (SocialPost $p): string => $p->publish_at->timezone($this->tz)->format('Y-m-d'));
        $out = [];
        foreach (range(0, 6) as $i) {
            $key = $start->copy()->addDays($i)->format('Y-m-d');
            $out[$key] = $posts->get($key, collect());
        }

        return $out;
    }

    /**
     * The month as counts per day; no post is loaded.
     *
     * @return array<string, array{post: int, video: int, story: int, draft: int, failed: int}>
     */
    public function month(Carbon $month, string $channel = ''): array
    {
        $start = $month->copy()->startOfMonth()->startOfWeek();
        $end = $month->copy()->endOfMonth()->endOfWeek();
        $out = [];
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $out[$day->format('Y-m-d')] = ['post' => 0, 'video' => 0, 'story' => 0, 'draft' => 0, 'failed' => 0];
        }
        $rows = $this->onChannel($this->posts(), $channel)->where('status', '!=', SocialPost::SKIPPED)
            ->whereBetween('publish_at', [$start->copy()->utc(), $end->copy()->utc()])->get(['id', 'kind', 'status', 'publish_at']);
        foreach ($rows as $row) {
            $key = $row->publish_at->timezone($this->tz)->format('Y-m-d');
            if (! isset($out[$key])) {
                continue;
            }
            $out[$key][$row->kind === 'reels' ? 'video' : ($row->kind === 'story' ? 'story' : 'post')]++;
            if ($row->status === SocialPost::DRAFT) {
                $out[$key]['draft']++;
            }
            if (in_array($row->status, [SocialPost::FAILED, SocialPost::PARTIAL, SocialPost::EXPIRED], true)) {
                $out[$key]['failed']++;
            }
        }

        return $out;
    }

    /**
     * One page of the list.
     *
     * @return array{rows: Collection<int, SocialPost>, total: int, page: int, pages: int, counts: array<string, int>}
     */
    public function page(string $status, string $kind, string $channel, string $search, int $page): array
    {
        $base = fn (): Builder => $this->onChannel($this->posts(), $channel)
            ->when(isset(self::KIND_FILTERS[$kind]) && $kind !== '', fn (Builder $q) => $q->where('kind', $kind))
            ->when(trim($search) !== '', function (Builder $q) use ($search): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower(trim($search))).'%';
                $q->where(fn (Builder $w) => $w->whereRaw('lower(title) like ?', [$like])->orWhereRaw('lower(caption) like ?', [$like]));
            });
        $counts = [];
        foreach ((clone $base())->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status') as $state => $n) {
            foreach (self::STATUS_MAP as $key => $states) {
                if (in_array($state, $states, true)) {
                    $counts[$key] = ($counts[$key] ?? 0) + (int) $n;
                }
            }
            $counts[''] = ($counts[''] ?? 0) + (int) $n;
        }
        $query = $base()->when(isset(self::STATUS_MAP[$status]), fn (Builder $q) => $q->whereIn('status', self::STATUS_MAP[$status]));
        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);
        // What is still to come reads forward in time; everything else newest first.
        $forward = in_array($status, ['onay', 'sirada'], true);
        $rows = $query->with(['targets.account'])->orderBy('publish_at', $forward ? 'asc' : 'desc')->orderBy('id', $forward ? 'asc' : 'desc')
            ->forPage($page, self::PER_PAGE)->get();

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'counts' => $counts];
    }

    /**
     * The Instagram profile as a grid: ready posts first, then what is live (shared from here or from the phone).
     *
     * @param  list<array<string, mixed>>  $feed
     * @return array{ready: Collection<int, SocialPost>, readyTotal: int, grid: Collection<int, array{post: ?SocialPost, ig: ?array<string, mixed>, at: string}>, gridTotal: int, waiting: int}
     */
    public function profile(array $feed, int $readyCount, int $gridCount): array
    {
        $readyQuery = fn (): Builder => $this->posts()->where('kind', '!=', 'text')
            ->whereIn('status', [SocialPost::DRAFT, SocialPost::APPROVED, SocialPost::FAILED, SocialPost::PARTIAL])
            ->whereDoesntHave('targets', fn ($q) => $q->where('status', SocialPostTarget::PUBLISHED));
        $ready = $readyQuery()->with(['targets.account'])->orderBy('publish_at')->limit($readyCount)->get();
        $published = $this->posts()->where('kind', '!=', 'text')
            ->whereHas('targets', fn ($q) => $q->whereIn('status', [SocialPostTarget::PUBLISHED, SocialPostTarget::DONE]))
            ->with(['targets.account'])->orderByDesc('publish_at')->limit(max(90, $gridCount))->get();
        $instagram = fn (SocialPost $p): ?SocialPostTarget => $p->targets->first(fn ($t) => $t->account?->platform === SocialAccount::INSTAGRAM && $t->external_id);
        $byExternal = $published->filter(fn (SocialPost $p): bool => $instagram($p) !== null)->keyBy(fn (SocialPost $p): string => (string) $instagram($p)->external_id);
        $grid = collect($feed)->map(fn (array $m): array => ['post' => $byExternal->get($m['id']), 'ig' => $m, 'at' => (string) $m['at']]);
        $seen = $grid->pluck('ig.id')->flip();
        foreach ($published as $post) {
            $ext = (string) ($instagram($post)?->external_id ?? '');
            if ($ext === '' || ! isset($seen[$ext])) {
                $grid->push(['post' => $post, 'ig' => null, 'at' => $post->publish_at->toIso8601String()]);
            }
        }

        return [
            'ready' => $ready, 'readyTotal' => $readyQuery()->count(),
            'grid' => $grid->sortByDesc('at')->values()->take($gridCount), 'gridTotal' => $grid->count(),
            'waiting' => $readyQuery()->where('status', SocialPost::DRAFT)->count(),
        ];
    }

    /**
     * Konuşmalar: public comments that wait for an answer (25 at most) and how many direct messages wait in the Gelen kutusu.
     *
     * @return array{comments: Collection<int, SocialThread>, commentTotal: int, dms: int}
     */
    public function talks(string $channel = ''): array
    {
        $open = fn (): Builder => SocialThread::query()->onEnabledAccount()->where('status', SocialThread::OPEN)->where('last_direction', 'in')
            ->when($channel !== '', fn (Builder $q) => $q->whereHas('account', fn ($a) => $a->where('platform', $channel)));

        return [
            'comments' => $open()->where('kind', SocialThread::COMMENT)->with('account')->orderByDesc('last_at')->limit(self::PER_PAGE)->get(),
            'commentTotal' => $open()->where('kind', SocialThread::COMMENT)->count(),
            'dms' => $open()->where('kind', SocialThread::DM)->count(),
        ];
    }

    /**
     * Sonuçlar: what went out in the last N days per channel, the Instagram numbers that were read, and the best posts.
     *
     * @return array{days: int, channels: list<array{key: string, label: string, published: int, measured: bool}>, instagram: array{reach: int, views: int, likes: int, comments: int, saved: int, shares: int, watch: ?float, measured: int}, top: Collection<int, SocialPostTarget>}
     */
    public function results(int $days): array
    {
        $since = now()->subDays($days);
        $targets = SocialPostTarget::query()->whereIn('status', [SocialPostTarget::PUBLISHED, SocialPostTarget::DONE])
            ->where('published_at', '>=', $since)->with(['account', 'post'])->get();
        $channels = [];
        foreach (self::CHANNELS as $key => $label) {
            $channels[] = ['key' => $key, 'label' => $label, 'published' => $targets->filter(fn (SocialPostTarget $t): bool => $t->account?->platform === $key)->count(), 'measured' => $key === SocialAccount::INSTAGRAM];
        }
        $ig = $targets->filter(fn (SocialPostTarget $t): bool => $t->account?->platform === SocialAccount::INSTAGRAM && is_array($t->insights));
        $sum = fn (string $key): int => (int) $ig->sum(fn (SocialPostTarget $t): int => (int) ($t->insights[$key] ?? 0));
        $watch = $ig->map(fn (SocialPostTarget $t) => $t->insights['ig_reels_avg_watch_time'] ?? null)->filter(fn ($v): bool => is_int($v) && $v > 0);

        return [
            'days' => $days,
            'channels' => $channels,
            'instagram' => [
                'reach' => $sum('reach'), 'views' => $sum('views'), 'likes' => $sum('likes'), 'comments' => $sum('comments'), 'saved' => $sum('saved'), 'shares' => $sum('shares'),
                'watch' => $watch->isEmpty() ? null : round($watch->avg() / 1000, 1), 'measured' => $ig->count(),
            ],
            'top' => $ig->filter(fn (SocialPostTarget $t): bool => $t->post !== null && isset($t->insights['reach']))
                ->sortByDesc(fn (SocialPostTarget $t): int => (int) $t->insights['reach'])->take(5)->values(),
        ];
    }

    /** @return Builder<SocialPost> */
    private function posts(): Builder
    {
        return SocialPost::query()->whereNull('removed_at');
    }

    /**
     * @param  Builder<SocialPost>  $query
     * @return Builder<SocialPost>
     */
    private function onChannel(Builder $query, string $channel): Builder
    {
        return isset(self::CHANNELS[$channel])
            ? $query->whereHas('targets', fn ($t) => $t->whereHas('account', fn ($a) => $a->where('platform', $channel)))
            : $query;
    }
}
