<?php

namespace App\Services\Assistant;

use App\Models\AgencySetting;
use App\Models\PushSubscription;
use App\Services\Push\WebPush;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Phone push to the owner's own channels: ntfy (topic URL, optional access token) and/or Telegram (bot token +
 * chat id), configured in Ayarlar › Bildirimler, and the browsers / phones that turned on notifications in Genel
 * işler (Web Push, `browser`). These are the agency's own notification channels, not writes to a client account.
 * The same dedupe key is sent at most once per window; every attempt is logged. The browser channel carries only
 * work alerts (yakup, 2026-10-03: "çok önemli bildirimler"): software / watchdog / backup alerts stay on ntfy and
 * Telegram.
 */
final class PushNotifier
{
    public const array SEVERITY_ORDER = ['info' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

    /** Dedupe key prefixes of system (not work) alerts: never sent to the browser channel. */
    public const array SYSTEM_PREFIXES = ['app-error:', 'ops-watchdog:', 'backup-failed:', 'discovery-new:'];

    /** @return list<string> configured channels */
    public function channels(): array
    {
        $settings = AgencySetting::query()->first();

        return array_values(array_filter([
            filled($settings?->push_ntfy_url) ? 'ntfy' : null,
            filled($settings?->push_telegram_bot_token) && filled($settings?->push_telegram_chat_id) ? 'telegram' : null,
            PushSubscription::query()->exists() ? 'browser' : null,
        ]));
    }

    /**
     * @return int channels that accepted the message (0 when not configured, below the minimum severity or a duplicate)
     */
    public function send(string $dedupeKey, string $title, string $body, string $severity = 'info', ?string $url = null, int $dedupeHours = 12, bool $force = false): int
    {
        $settings = AgencySetting::query()->first();
        $channels = $this->channels();
        if ($channels === []) {
            return 0;
        }
        $minimum = self::SEVERITY_ORDER[(string) ($settings?->push_min_severity ?: 'high')] ?? 3;
        if (! $force && (self::SEVERITY_ORDER[$severity] ?? 0) < $minimum) {
            return 0;
        }
        if (! $force && DB::table('push_notifications')->where('dedupe_key', $dedupeKey)->where('status', 'sent')
            ->where('created_at', '>=', now()->subHours($dedupeHours))->exists()) {
            return 0;
        }
        $sent = 0;
        foreach ($channels as $channel) {
            if ($channel === 'browser') {
                if (! Str::startsWith($dedupeKey, self::SYSTEM_PREFIXES) && ($force || (self::SEVERITY_ORDER[$severity] ?? 0) >= self::SEVERITY_ORDER['high'])) {
                    $sent += $this->browser($dedupeKey, $title, $body, $severity, $url);
                }

                continue;
            }
            $error = null;
            try {
                $response = $channel === 'ntfy' ? $this->ntfy($settings, $title, $body, $severity, $url) : $this->telegram($settings, $title, $body, $url);
                if (! $response->successful()) {
                    $error = 'HTTP '.$response->status();
                }
            } catch (Throwable $exception) {
                // Never keep the Telegram bot token (it is part of the URL) in the log.
                $error = mb_substr(str_replace((string) $settings->push_telegram_bot_token, '[REDACTED]', $exception->getMessage()), 0, 300);
            }
            DB::table('push_notifications')->insert([
                'dedupe_key' => mb_substr($dedupeKey, 0, 191), 'channel' => $channel, 'severity' => $severity,
                'title' => mb_substr($title, 0, 255), 'body' => mb_substr($body, 0, 2000),
                'status' => $error === null ? 'sent' : 'failed', 'error' => $error, 'created_at' => now(),
            ]);
            $sent += $error === null ? 1 : 0;
        }

        return $sent;
    }

    /**
     * Web Push to every subscribed browser / phone: the text is logged first (the service worker reads it from
     * /push/latest), then an empty push wakes each device.
     *
     * @return int 1 when at least one device accepted it
     */
    public function browser(string $dedupeKey, string $title, string $body, string $severity, ?string $url): int
    {
        $id = DB::table('push_notifications')->insertGetId([
            'dedupe_key' => mb_substr($dedupeKey, 0, 191), 'channel' => 'browser', 'severity' => $severity,
            'title' => mb_substr($title, 0, 255), 'body' => mb_substr($body, 0, 2000), 'url' => $url !== null ? mb_substr($url, 0, 500) : null,
            'status' => 'sending', 'error' => null, 'created_at' => now(),
        ]);
        $push = app(WebPush::class);
        $accepted = 0;
        $error = null;
        foreach (PushSubscription::query()->orderBy('id')->get() as $subscription) {
            try {
                $accepted += $push->send($subscription, $severity === 'critical' ? 'high' : 'normal') ? 1 : 0;
            } catch (Throwable $exception) {
                $error = mb_substr($exception->getMessage(), 0, 300);
            }
        }
        DB::table('push_notifications')->where('id', $id)->update(['status' => $accepted > 0 ? 'sent' : 'failed', 'error' => $accepted > 0 ? null : ($error ?? 'Hiçbir cihaz kabul etmedi.')]);

        return $accepted > 0 ? 1 : 0;
    }

    private function ntfy(AgencySetting $settings, string $title, string $body, string $severity, ?string $url): Response
    {
        $headers = [
            // Header values must be ASCII-safe; ntfy decodes RFC 2047 encoded words.
            'Title' => '=?UTF-8?B?'.base64_encode($title).'?=',
            'Priority' => (string) match ($severity) {
                'critical' => 5, 'high' => 4, 'medium' => 3, default => 2
            },
            'Tags' => $severity === 'critical' ? 'rotating_light' : ($severity === 'high' ? 'warning' : 'bell'),
        ];
        if ($url !== null) {
            $headers['Click'] = $url;
        }
        $request = Http::timeout(10)->withHeaders($headers);
        if (filled($settings->push_ntfy_token)) {
            $request = $request->withToken((string) $settings->push_ntfy_token);
        }

        return $request->withBody($body, 'text/plain; charset=utf-8')->post((string) $settings->push_ntfy_url);
    }

    private function telegram(AgencySetting $settings, string $title, string $body, ?string $url): Response
    {
        return Http::timeout(10)->asJson()->post('https://api.telegram.org/bot'.$settings->push_telegram_bot_token.'/sendMessage', [
            'chat_id' => $settings->push_telegram_chat_id,
            'text' => $title."\n".$body.($url !== null ? "\n".$url : ''),
            'disable_web_page_preview' => true,
        ]);
    }
}
