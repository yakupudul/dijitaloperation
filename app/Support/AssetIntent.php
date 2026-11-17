<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Bilinen niyet (arayüz kararı 2026-11-17): what the operator knows about an asset and no data can tell, stored on the
 * asset (digital_assets.intent: paused = stopped on purpose, retired = an old account nobody uses). An asset with an
 * intent is information, never an alarm: no "veri gelmiyor", no "erişim kesik", no open work, and it does not turn its
 * brand red. Nothing is disconnected or deleted; clearing the note makes the asset count again the same minute.
 */
final class AssetIntent
{
    private const string CACHE_KEY = 'assets.intent-ids';

    /** @return array<int, string> asset id => intent */
    public static function map(): array
    {
        return once(fn (): array => app()->runningUnitTests() ? self::read() : Cache::remember(self::CACHE_KEY, 600, fn (): array => self::read()));
    }

    public static function quiet(int $assetId): bool
    {
        return isset(self::map()[$assetId]);
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<int, string> */
    private static function read(): array
    {
        try {
            if (! SchemaState::hasColumn('digital_assets', 'intent')) {
                return [];
            }

            return DB::table('digital_assets')->whereNotNull('intent')->whereNull('deleted_at')->pluck('intent', 'id')
                ->mapWithKeys(fn ($intent, $id): array => [(int) $id => (string) $intent])->all();
        } catch (Throwable $error) {
            report($error);

            return [];
        }
    }
}
