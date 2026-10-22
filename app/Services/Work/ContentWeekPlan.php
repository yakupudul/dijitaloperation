<?php

namespace App\Services\Work;

use App\Jobs\Site\TopUpContentPoolJob;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Site\ContentMap;
use App\Services\Site\SiteSuggestions;
use App\Services\Site\SiteSuggestionTypes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Haftalık içerik planı (Aşama 8, 2026-10-22; yakup 2026-10-21: "haftalık içerik PLANI onaylanır, tümünü onayla,
 * istemediğini at"). Monday's planWeek no longer approves the cycle's ideas by itself: it proposes them (action.plan:
 * week, date for a new article) next to the week's updates and ★ page strengthening. The operator approves the whole
 * list or one row, or drops a row; a dropped row is replaced at once by the best waiting idea of the site (the pool tops
 * itself up after). An approved new article is written, checked and dated for its day; an update keeps its own approval
 * on the page.
 */
final class ContentWeekPlan
{
    public const string DROP_REASON = 'Plan: istenmedi';

    public const array KIND_LABELS = ['new' => 'Yeni yazı', 'strengthen' => 'Ana sayfayı güçlendir', 'update' => 'Güncelleme'];

    public function __construct(private readonly ContentBoard $board, private readonly SiteSuggestions $suggestions) {}

    /**
     * The week's proposals waiting for the operator, strengthening first, then new articles by date, then updates.
     *
     * @return Collection<int, array{id: int, brand_id: int, brand: string, logo: ?string, site_id: int, site: string, title: string, kind: string, kind_label: string, why: string, date: ?string}>
     */
    public function waiting(?int $brandId = null): Collection
    {
        $rows = self::proposals()->when($brandId !== null, fn ($q) => $q->where('brand_id', $brandId))->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return collect();
        }
        $brands = Brand::query()->whereIn('id', $rows->pluck('brand_id')->unique()->all())->get(['id', 'name', 'logo_url'])->keyBy('id');
        $sites = DigitalAsset::query()->whereIn('id', $rows->map(fn (Suggestion $s): int => (int) data_get($s->action, 'site_id'))->unique()->all())->get(['id', 'domain', 'name'])->keyBy('id');
        $order = ['strengthen' => 0, 'new' => 1, 'update' => 2];

        return $rows->filter(fn (Suggestion $s): bool => $brands->has($s->brand_id) && $sites->has((int) data_get($s->action, 'site_id')))
            ->map(function (Suggestion $s) use ($brands, $sites): array {
                $kind = self::kind($s);
                $site = $sites[(int) data_get($s->action, 'site_id')];

                return [
                    'id' => (int) $s->id, 'brand_id' => (int) $s->brand_id, 'brand' => (string) $brands[$s->brand_id]->name, 'logo' => $brands[$s->brand_id]->logo_url ?: null,
                    'site_id' => (int) $site->id, 'site' => (string) ($site->domain ?: $site->name), 'title' => (string) $s->title, 'kind' => $kind,
                    'kind_label' => self::KIND_LABELS[$kind], 'why' => self::why($s), 'date' => data_get($s->action, 'plan.date'),
                ];
            })
            ->sortBy(fn (array $r): string => $order[$r['kind']].($r['date'] ?? '9999').str_pad((string) $r['id'], 10, '0', STR_PAD_LEFT))->values();
    }

    /** @return array<int, int> brand id => proposals waiting */
    public function countsByBrand(): array
    {
        return $this->waiting()->countBy('brand_id')->all();
    }

    /**
     * "Tümünü onayla" (a brand's, or every brand's) or one row: each proposal is approved and written; a new article
     * keeps its plan day (today or the next free Mon / Wed / Fri of the week when the day has passed).
     *
     * @param  list<int>|null  $ids  null: every waiting proposal (of the brand)
     */
    public function approve(?array $ids, User $user, ?int $brandId = null): string
    {
        $rows = self::proposals()->when($ids !== null, fn ($q) => $q->whereIn('id', $ids ?: [0]))
            ->when($brandId !== null, fn ($q) => $q->where('brand_id', $brandId))->orderBy('id')->get();
        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['plan' => 'Onay bekleyen plan satırı yok.']);
        }
        $today = ContentAutopilot::today();
        $taken = [];
        foreach ($rows as $suggestion) {
            $site = (int) data_get($suggestion->action, 'site_id');
            $taken[$site] ??= $this->takenDates($site);
            $this->board->write((int) $suggestion->id, $user);
            $fresh = $suggestion->fresh();
            $action = (array) $fresh->action;
            $action['plan'] = array_merge((array) ($action['plan'] ?? []), ['approved_at' => now()->toIso8601String(), 'approved_by' => (int) $user->id]);
            if ((string) data_get($action, 'kind', 'new') !== 'update') {
                $date = $this->dateFor((string) data_get($action, 'plan.date', ''), (string) data_get($action, 'plan.week', $today->toDateString()), $today, $taken[$site]);
                $taken[$site][] = $date;
                $action['autopilot'] = array_merge((array) ($action['autopilot'] ?? []), ['date' => $date, 'planned_at' => now()->toIso8601String(), 'week' => (string) data_get($action, 'plan.week')]);
            }
            $fresh->forceFill(['action' => $action])->save();
        }

        return $rows->count().' plan satırı onaylandı; yazılıp kontrol edilince gönderime hazır olur.';
    }

    /** "At": the row leaves the plan and the best waiting idea of the site takes its place (same day, same kind). */
    public function drop(int $id, User $user): string
    {
        $suggestion = self::proposals()->whereKey($id)->first();
        if ($suggestion === null) {
            throw ValidationException::withMessages(['plan' => 'Bu satır artık planda değil.']);
        }
        $plan = (array) data_get($suggestion->action, 'plan', []);
        $kind = self::kind($suggestion) === 'update' ? 'update' : 'new';
        $this->suggestions->dismiss($suggestion, $user, self::DROP_REASON);
        $site = DigitalAsset::query()->find((int) data_get($suggestion->action, 'site_id'));
        $next = $site !== null ? $this->replacement($site, $kind) : null;
        if ($site !== null) {
            TopUpContentPoolJob::dispatch((int) $site->id)->delay(now()->addMinute());
        }
        if ($next === null) {
            return 'Plandan çıkarıldı. Havuzda yerine konacak fikir yok; yenisi üretiliyor.';
        }
        $next->forceFill(['action' => array_merge((array) $next->action, ['plan' => array_filter(['week' => $plan['week'] ?? null, 'date' => $kind === 'new' ? ($plan['date'] ?? null) : null,
            'proposed_at' => now()->toIso8601String(), 'replaces' => $id], fn (mixed $v): bool => $v !== null)])])->save();

        return 'Plandan çıkarıldı; yerine «'.$next->title.'» geldi.';
    }

    /**
     * Last 90 days' sent articles, one line: "Son 90 günde gönderilen 12 yazı: 28 günde 140 tık, 90 günde 380 tık."
     *
     * @return array{articles: int, clicks28: int, clicks90: int, sentence: string}|null
     */
    public function results(?int $brandId = null): ?array
    {
        $siteIds = $brandId === null ? null : DigitalAsset::query()->where('brand_id', $brandId)->where('type', 'website')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $live = array_values(array_filter(app(ContentCoverage::class)->articleResults($siteIds), fn (array $r): bool => $r['live']));
        if ($live === []) {
            return null;
        }
        $c28 = array_sum(array_column($live, 'clicks'));
        $c90 = array_sum(array_column($live, 'clicks90'));
        $n = fn (int $v): string => number_format($v, 0, ',', '.');

        return ['articles' => count($live), 'clicks28' => $c28, 'clicks90' => $c90,
            'sentence' => 'Yayındaki '.count($live).' yazı: son 30 günde '.$n($c28).' tık, son 90 günde '.$n($c90).' tık. Az getiren yazı türü sonraki planlarda geri çekilir.'];
    }

    /** Waiting proposals (still open, marked by planWeek or a replacement). */
    public static function proposals()
    {
        return Suggestion::query()->where('channel', 'search')->where('action_type', SiteSuggestionTypes::CONTENT)
            ->where('status', Suggestion::OPEN)->whereNotNull('action->plan->week')->whereNull('action->plan->approved_at');
    }

    /** strengthen (a thin ★ / service page), update, or new. */
    public static function kind(Suggestion $suggestion): string
    {
        if ((string) data_get($suggestion->action, 'kind', 'new') !== 'update') {
            return 'new';
        }
        $evidence = implode(' ', array_map(fn (mixed $e): string => (string) data_get($e, 'value', ''), (array) $suggestion->evidence));

        return str_contains($evidence, 'güçlendirilecek') ? 'strengthen' : 'update';
    }

    /** One line why: the idea's own reason, else its first evidence. */
    public static function why(Suggestion $suggestion): string
    {
        $reason = trim((string) $suggestion->reason);
        if ($reason !== '') {
            return mb_substr($reason, 0, 160);
        }
        foreach ((array) $suggestion->evidence as $evidence) {
            $value = trim((string) data_get($evidence, 'value', ''));
            if ($value !== '' && $value !== 'veri yok') {
                return mb_substr($value, 0, 160);
            }
        }

        return 'Sitenin konu haritasında boş kalan yer.';
    }

    /** The best waiting idea of the site of the same kind, not in the plan and not answered by a page. */
    private function replacement(DigitalAsset $site, string $kind): ?Suggestion
    {
        $map = app(ContentMap::class);

        return Suggestion::query()->where('channel', 'search')->where('action_type', SiteSuggestionTypes::CONTENT)->where('status', Suggestion::OPEN)
            ->where('brand_id', $site->brand_id)->where('action->site_id', (int) $site->id)->whereNull('action->plan')
            ->orderBy('priority')->orderBy('id')->limit(50)->get()
            ->first(fn (Suggestion $s): bool => ((string) data_get($s->action, 'kind', 'new') === 'update') === ($kind === 'update')
                && ! is_array(data_get($s->action, 'article')) && ! isset(((array) $s->action)['article_blocked']) && $map->resolve($s) === null);
    }

    /** @return list<string> plan dates the site's articles already hold this week and later */
    private function takenDates(int $siteId): array
    {
        $site = DigitalAsset::query()->find($siteId);

        return $site === null ? [] : ContentAutopilot::planned($site)->map(fn (Suggestion $s): string => (string) data_get($s->action, 'autopilot.date'))
            ->filter(fn (string $d): bool => $d >= ContentAutopilot::today()->toDateString())->values()->all();
    }

    /** @param  list<string>  $taken */
    private function dateFor(string $planned, string $week, CarbonImmutable $today, array $taken): string
    {
        if ($planned !== '' && $planned >= $today->toDateString() && ! in_array($planned, $taken, true)) {
            return $planned;
        }
        $monday = CarbonImmutable::parse($week, 'Europe/Istanbul')->startOfWeek(CarbonImmutable::MONDAY);
        foreach ([$monday, $monday->addWeek()] as $start) {
            foreach (ContentAutopilot::WEEK_DAYS as $offset) {
                $day = $start->addDays($offset)->toDateString();
                if ($day >= $today->toDateString() && ! in_array($day, $taken, true)) {
                    return $day;
                }
            }
        }

        return $today->toDateString();
    }
}
