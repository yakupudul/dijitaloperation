<?php

namespace App\Services\Work;

use App\Models\Brand;
use App\Models\User;
use App\Models\WorkBatch;
use App\Support\SchemaState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Operatör süresi (Aşama 4, 2026-10-22; "1 kişi 100 markayı yönetsin" needs the minutes it takes): each minute an
 * operator opens a screen or clicks is one active minute, kept per brand when the screen is a brand's. Clicks are the
 * operator's own button presses (polls and lazy loads are not); the decision wait is how long the approved rows had
 * waited (median per approval, WorkBatches::openForDesk).
 */
final class OperatorTime
{
    private const string TZ = 'Europe/Istanbul';

    public static function record(User $user, ?int $brandId, bool $click): void
    {
        try {
            // No schema check on every click: a missing table fails inside its own savepoint and is reported.
            $now = now()->timezone(self::TZ);
            $column = $click ? 'clicks' : 'views';
            DB::transaction(fn () => DB::table('operator_activity')->upsert(
                [['user_id' => $user->id, 'day' => $now->toDateString(), 'minute' => $now->hour * 60 + $now->minute, 'brand_id' => max(0, (int) $brandId),
                    'views' => $click ? 0 : 1, 'clicks' => $click ? 1 : 0]],
                ['user_id', 'day', 'minute', 'brand_id'],
                [$column => DB::raw('operator_activity.'.$column.' + 1')],
            ));
        } catch (Throwable $error) {
            report($error);
        }
    }

    /**
     * The last 7 Istanbul days against the 7 before: active minutes, clicks, decision wait, and the brands that took
     * the most minutes.
     *
     * @return array{minutes: int, previous: int, clicks: int, views: int, wait_seconds: int|null, approvals: int, brands: list<array{id: int, name: string, minutes: int, clicks: int}>, per_brand: int|null}
     */
    public function week(?User $user = null): array
    {
        $empty = ['minutes' => 0, 'previous' => 0, 'clicks' => 0, 'views' => 0, 'wait_seconds' => null, 'approvals' => 0, 'brands' => [], 'per_brand' => null];
        if (! SchemaState::hasTable('operator_activity')) {
            return $empty;
        }
        $today = now()->timezone(self::TZ)->startOfDay();
        $from = $today->copy()->subDays(6)->toDateString();
        $before = $today->copy()->subDays(13)->toDateString();
        $scope = fn ($q) => $q->when($user !== null, fn ($w) => $w->where('user_id', $user->id));
        $rows = DB::table('operator_activity')->where('day', '>=', $from)->tap($scope);
        // A minute spent on two brands' screens is one minute of the week, but counts for both brands.
        $minutes = DB::query()->fromSub(DB::table('operator_activity')->where('day', '>=', $from)->tap($scope)->select('user_id', 'day', 'minute')->distinct(), 'm')->count();
        $previous = DB::query()->fromSub(DB::table('operator_activity')->where('day', '>=', $before)->where('day', '<', $from)->tap($scope)
            ->select('user_id', 'day', 'minute')->distinct(), 'm')->count();
        $totals = (clone $rows)->selectRaw('coalesce(sum(clicks), 0) as clicks, coalesce(sum(views), 0) as views')->first();
        $byBrand = (clone $rows)->where('brand_id', '>', 0)->selectRaw('brand_id, count(*) as minutes, sum(clicks) as clicks')
            ->groupBy('brand_id')->orderByDesc('minutes')->limit(8)->get();
        $names = Brand::query()->whereIn('id', $byBrand->pluck('brand_id'))->pluck('name', 'id');
        $brandCount = Brand::query()->operational()->count();
        $batches = SchemaState::hasTable('work_batches') ? WorkBatch::query()->where('kind', WorkBatch::KIND_DESK)
            ->where('created_at', '>=', Carbon::parse($from, self::TZ))->whereNotNull('decision_wait_seconds')
            ->when($user !== null, fn ($q) => $q->where('user_id', $user->id))->get(['total', 'decision_wait_seconds']) : collect();
        $weight = (int) $batches->sum('total');

        return [
            'minutes' => (int) $minutes,
            'previous' => (int) $previous,
            'clicks' => (int) ($totals->clicks ?? 0),
            'views' => (int) ($totals->views ?? 0),
            // Weighted by size: a thousand-row approval weighs more than a single row.
            'wait_seconds' => $weight > 0 ? (int) round($batches->sum(fn (WorkBatch $b): int => $b->total * (int) $b->decision_wait_seconds) / $weight) : null,
            'approvals' => $batches->count(),
            'brands' => $byBrand->map(fn ($r): array => ['id' => (int) $r->brand_id, 'name' => (string) ($names[$r->brand_id] ?? 'Marka'), 'minutes' => (int) $r->minutes, 'clicks' => (int) $r->clicks])->all(),
            'per_brand' => $brandCount > 0 ? (int) round($minutes / $brandCount) : null,
        ];
    }

    /** "3 sa 20 dk" / "45 dk". */
    public static function minutesLabel(int $minutes): string
    {
        return $minutes >= 60 ? intdiv($minutes, 60).' sa'.($minutes % 60 > 0 ? ' '.($minutes % 60).' dk' : '') : $minutes.' dk';
    }

    /** "2 gün" / "5 saat" / "12 dk". */
    public static function waitLabel(?int $seconds): string
    {
        return match (true) {
            $seconds === null => '—',
            $seconds >= 86400 => str_replace('.', ',', (string) round($seconds / 86400, 1)).' gün',
            $seconds >= 3600 => (int) round($seconds / 3600).' saat',
            default => max(1, (int) round($seconds / 60)).' dk',
        };
    }
}
