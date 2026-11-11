<?php

namespace App\Services\Brand;

use App\Models\Brand;
use App\Models\Suggestion;
use App\Services\Operations\SettingChanges;
use App\Support\SchemaState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Marka başına "yeni düzen" (Marka Düzeni 2.5). A brand in the new order is worked from its findings (brand page:
 * Bulgular, Çöz, monthly session); the old order's rows stop reaching the operator for it: the nightly checks' rows,
 * the weekly article ideas, the "senin elinle" list. Nothing is deleted and nothing is switched off for good:
 *  - every open row of the brand is set aside (snoozed with a marker that keeps its old status);
 *  - a row a check opens later is set aside the moment it is saved (Suggestion::saving), and the nightly sweep
 *    (moxdop:ads:quiet-dormant, an existing job) catches the ones written by bulk updates;
 *  - switching the brand back puts every set-aside row back as it was.
 * Collection, queries and clusters keep running.
 */
final class NewOrder
{
    /** Marker in the suggestion's action: set aside by the new order, with the status it had. */
    public const string MARKER = 'new_order';

    private const string CACHE = 'brand.new-order.ids';

    /** Far enough that the row never "wakes up" by itself; switching off is what brings it back. */
    private const int HOLD_YEARS = 20;

    private const array HELD = [Suggestion::OPEN, Suggestion::RECHECK];

    /** @return list<int> brands in the new order */
    public static function brandIds(): array
    {
        try {
            return Cache::remember(self::CACHE, 300, fn (): array => SchemaState::hasColumn('brands', 'new_order_at')
                ? DB::table('brands')->whereNotNull('new_order_at')->pluck('id')->map(fn ($id): int => (int) $id)->all() : []);
        } catch (Throwable) {
            return [];
        }
    }

    public static function active(?int $brandId): bool
    {
        return $brandId !== null && in_array($brandId, self::brandIds(), true);
    }

    /** Suggestion::saving: a row that would land on the desk of a new-order brand is set aside instead. */
    public static function hold(Suggestion $suggestion): void
    {
        if (! in_array($suggestion->status, self::HELD, true) || ! self::active($suggestion->brand_id !== null ? (int) $suggestion->brand_id : null)) {
            return;
        }
        $action = (array) $suggestion->action;
        $action[self::MARKER] ??= ['since' => now()->toIso8601String(), 'status' => $suggestion->status];
        $suggestion->status = Suggestion::SNOOZED;
        $suggestion->snoozed_until = now()->addYears(self::HOLD_YEARS);
        $suggestion->action = $action;
    }

    /** @return int how many open rows were set aside */
    public function on(Brand $brand): int
    {
        if ($brand->new_order_at === null) {
            $brand->forceFill(['new_order_at' => now()])->save();
            Cache::forget(self::CACHE);
            SettingChanges::record('order:'.$brand->id, $brand->name.', yeni düzen', false, true);
        }

        return $this->sweep((int) $brand->id);
    }

    /** @return int how many rows came back */
    public function off(Brand $brand): int
    {
        if ($brand->new_order_at !== null) {
            $brand->forceFill(['new_order_at' => null])->save();
            Cache::forget(self::CACHE);
            SettingChanges::record('order:'.$brand->id, $brand->name.', yeni düzen', true, false);
        }
        $back = 0;
        Suggestion::query()->where('brand_id', $brand->id)->where('status', Suggestion::SNOOZED)->whereNotNull('action->'.self::MARKER)
            ->lazyById(200)->each(function (Suggestion $suggestion) use (&$back): void {
                $action = (array) $suggestion->action;
                $status = (string) data_get($action, self::MARKER.'.status', Suggestion::OPEN);
                unset($action[self::MARKER]);
                $suggestion->forceFill(['status' => in_array($status, self::HELD, true) ? $status : Suggestion::OPEN, 'snoozed_until' => null, 'action' => $action])->save();
                $back++;
            });

        return $back;
    }

    /** Sets aside the open rows of every new-order brand (or of one): the nightly catch-up for bulk-written rows. */
    public function sweep(?int $brandId = null): int
    {
        $ids = $brandId !== null ? [$brandId] : self::brandIds();
        if ($ids === []) {
            return 0;
        }
        $held = 0;
        Suggestion::query()->whereIn('brand_id', $ids)->whereIn('status', self::HELD)->lazyById(200)->each(function (Suggestion $suggestion) use (&$held): void {
            $suggestion->save(); // the saving hook sets it aside
            $held += $suggestion->status === Suggestion::SNOOZED ? 1 : 0;
        });

        return $held;
    }

    /** How many rows wait set aside for a brand. */
    public static function heldCount(int $brandId): int
    {
        return Suggestion::query()->where('brand_id', $brandId)->where('status', Suggestion::SNOOZED)->whereNotNull('action->'.self::MARKER)->count();
    }
}
