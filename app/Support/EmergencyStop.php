<?php

namespace App\Support;

use App\Models\AgencySetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Acil durdurma (Bağlantı Düzeni 3.3): one switch that holds everything MoxDOP sends out by itself: approved writes to
 * sites and Business Profiles (ExecuteExternalWriteJob), social posts (SocialPublisher) and Claude's direct site edits
 * (MCP). Nothing is lost or failed while it is on: writes stay queued and go on from where they were once it is
 * switched off. Reading data is never stopped.
 */
final class EmergencyStop
{
    public const string REASON = 'Acil durdurma açık: dışarıya hiçbir şey gönderilmiyor. Paneldeki kırmızı şeritten "Devam ettir" ile açılır.';

    private const string CACHE = 'emergency-stop.since';

    public static function active(): bool
    {
        return self::since() !== null;
    }

    public static function since(): ?CarbonImmutable
    {
        try {
            $since = Cache::remember(self::CACHE, 30, fn (): string => (string) (AgencySetting::query()->value('writes_stopped_at') ?? ''));

            return $since !== '' ? CarbonImmutable::parse($since) : null;
        } catch (Throwable) {
            // Before the column exists (a deploy in progress) nothing is stopped.
            return null;
        }
    }

    public static function stop(User $user): void
    {
        self::set(['writes_stopped_at' => now(), 'writes_stopped_by' => $user->id]);
    }

    public static function resume(): void
    {
        self::set(['writes_stopped_at' => null, 'writes_stopped_by' => null]);
    }

    /** @param  array<string, mixed>  $values */
    private static function set(array $values): void
    {
        $settings = AgencySetting::query()->first() ?? new AgencySetting;
        $settings->forceFill($values)->save();
        Cache::forget(self::CACHE);
    }
}
