<?php

namespace App\Services\Assistant;

use App\Models\AgencySetting;
use App\Support\SchemaState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bildirim düzeni (Günlük Düzen 3.1 and 3.5): the one door every phone notification passes. Three classes:
 * "hemen" rings now (someone wrote, money stops, a site is down, a backup or release failed, the operator's own alarm),
 * "özet" waits for the morning plan or the evening close, and what is neither urgent nor worth a summary stays in the
 * panel (the callers already keep those below the minimum severity). A day has an upper limit: above it a "hemen"
 * notification waits for the summary too. In izin modu ("bugün yokum") only money alarms ring.
 */
final class PushPolicy
{
    public const string SEND = 'send';

    public const string HOLD = 'hold';

    /** Ringing notifications a day; the ones above it wait for the summary. */
    public const int DAILY_LIMIT = 25;

    /** Money stops or a site is down: rings at any hour, also in izin modu. */
    private const array MONEY = ['uptime:down:', 'alert:'];

    /** Always through: the operator's own alarms and the two summaries themselves. */
    private const array OWN = ['reminder:', DayDigest::KEY];

    /** Never urgent: told in the next summary. */
    private const array SUMMARY = ['repair-desk-digest:', 'lead-form-morning:', 'inbox-night:', 'discovery-new:', 'discovery-lost:',
        'provider-watch:', 'gbp-review-approval:', 'uptime:up:', 'app-error:'];

    public function decide(string $key, string $severity): string
    {
        if (Str::startsWith($key, self::OWN)) {
            return self::SEND;
        }
        if (Str::startsWith($key, self::MONEY) && ($severity === 'critical' || Str::startsWith($key, 'uptime:down:'))) {
            return self::SEND;
        }
        if (self::away() || Str::startsWith($key, self::SUMMARY) || (Str::startsWith($key, 'auto-deploy:') && $severity === 'info')) {
            return self::HOLD;
        }
        // A quiet refresh of a notification already on the screen (low) is not a new ring.
        if ($severity !== 'low' && $this->rungToday() >= self::DAILY_LIMIT) {
            return self::HOLD;
        }

        return self::SEND;
    }

    /** Keeps a notification for the next summary. The same key is kept once. */
    public function hold(string $key, string $title, string $body, string $severity, ?string $url): void
    {
        if (DB::table('push_notifications')->where('dedupe_key', mb_substr($key, 0, 191))->where('status', 'held')->exists()) {
            return;
        }
        DB::table('push_notifications')->insert([
            'dedupe_key' => mb_substr($key, 0, 191), 'channel' => 'ozet', 'severity' => $severity, 'title' => mb_substr($title, 0, 255),
            'body' => mb_substr($body, 0, 2000), 'url' => $url !== null ? mb_substr($url, 0, 500) : null, 'status' => 'held', 'error' => null, 'created_at' => now(),
        ]);
    }

    /** Izin modu: on up to and including the stored day. */
    public static function away(): bool
    {
        if (! SchemaState::hasColumn('agency_settings', 'away_until')) {
            return false;
        }
        $until = AgencySetting::query()->value('away_until');

        return $until !== null && CarbonImmutable::parse((string) $until, 'Europe/Istanbul')->endOfDay()->gte(CarbonImmutable::now('Europe/Istanbul'));
    }

    public static function setAway(bool $on): void
    {
        AgencySetting::query()->firstOrCreate([])->forceFill(['away_until' => $on ? CarbonImmutable::now('Europe/Istanbul')->toDateString() : null])->save();
    }

    /** How many notifications rang on the phone today (the browser channel, quiet refreshes aside). */
    public function rungToday(): int
    {
        return DB::table('push_notifications')->where('channel', 'browser')->where('status', 'sent')->where('severity', '!=', 'low')
            ->where('created_at', '>=', CarbonImmutable::now('Europe/Istanbul')->startOfDay()->utc())->count();
    }
}
