<?php

namespace App\Services\Sales;

use App\Models\SalesLead;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Satış Düzeni 4.4, olaylı takip: once a week the home pages of the silent prospects (ısıtma havuzu) are opened, a
 * limited number per week, the longest unseen first. A prospect comes back to Bugün only when something happened:
 * the site stopped opening, its certificate broke, or the site was renewed. The reason stands in the next step and
 * on the card, so a re-warm message always has a cause. One plain page request per prospect, no AI, no paid API, and
 * no new scheduled job: the round rides on the ten-minute sales sweep and runs once per week.
 */
final class WarmPoolWatch
{
    /** Prospects looked at per week (cost stays at this many page requests). */
    public const int WEEKLY = 25;

    /** @return array{looked: int, events: int} */
    public function sweep(): array
    {
        $now = CarbonImmutable::now('Europe/Istanbul');
        if ($now->isWeekend() || $now->hour < 9 || ! Cache::add('sales.warm-watch.'.$now->format('o-W'), 1, now()->addDays(8))) {
            return ['looked' => 0, 'events' => 0];
        }
        $leads = SalesLead::query()->open()->whereNotNull('website_host')
            ->where(fn ($q) => $q->where('last_incoming_at', '<', now()->subDays(FollowUpSequence::COLD_AFTER_DAYS))
                ->orWhere(fn ($n) => $n->whereNull('last_incoming_at')->where('last_contact_at', '<', now()->subDays(FollowUpSequence::COLD_AFTER_DAYS))))
            ->orderByRaw('site_watched_at is null desc')->orderBy('site_watched_at')->orderBy('id')->limit(self::WEEKLY)->get();
        $events = 0;
        foreach ($leads as $lead) {
            try {
                $events += $this->look($lead) ? 1 : 0;
            } catch (Throwable $error) {
                report($error);
            }
        }

        return ['looked' => $leads->count(), 'events' => $events];
    }

    /** Looks once; true when something happened since the last look. */
    public function look(SalesLead $lead): bool
    {
        $before = is_array($lead->site_watch) ? $lead->site_watch : null;
        $now = $this->state((string) $lead->website_host);
        $lead->forceFill(['site_watch' => $now, 'site_watched_at' => now()])->save();
        $reason = self::reason($before, $now);
        if ($reason === null) {
            return false;
        }
        $week = CarbonImmutable::now('Europe/Istanbul')->format('o-W');
        if (app(SalesLeads::class)->event($lead, 'system', 'Yazmak için iyi an: '.$reason, meta: ['watch' => $now['state']], fingerprint: 'watch:'.$now['state'].':'.$week) === null) {
            return false;
        }
        $lead->forceFill(['next_step' => mb_substr('Yaz: '.$reason, 0, 190), 'next_step_at' => FollowUpSequence::weekdayMorning(CarbonImmutable::now('Europe/Istanbul'))])->save();

        return true;
    }

    /**
     * What changed, in the words of the next step; null when nothing worth writing about happened.
     *
     * @param  array{state: string, title: ?string}|null  $before
     * @param  array{state: string, title: ?string}  $now
     */
    public static function reason(?array $before, array $now): ?string
    {
        $was = $before['state'] ?? null;

        return match (true) {
            $now['state'] === 'down' && $was !== 'down' => 'sitesi açılmıyor.',
            $now['state'] === 'insecure' && $was !== 'insecure' => 'sitesinin güvenlik sertifikası çalışmıyor, ziyaretçi uyarı görüyor.',
            $now['state'] === 'up' && $was === 'down' => 'sitesi yeniden açıldı.',
            $now['state'] === 'up' && $was === 'up' && filled($before['title'] ?? null) && filled($now['title']) && $before['title'] !== $now['title'] => 'sitesini yeniledi, ana sayfa başlığı değişti.',
            default => null,
        };
    }

    /** @return array{state: string, title: ?string} */
    private function state(string $host): array
    {
        $secure = $this->get('https://'.$host);
        if ($secure !== null) {
            return $secure;
        }
        // Not reachable over https: a broken certificate when plain http still answers, down otherwise (asked twice).
        $plain = $this->get('http://'.$host, false);
        if ($plain !== null && $plain['state'] === 'up') {
            return ['state' => 'insecure', 'title' => $plain['title']];
        }

        return $this->get('https://'.$host) ?? ['state' => 'down', 'title' => null];
    }

    /** @return array{state: string, title: ?string}|null null when the address could not be reached at all */
    private function get(string $url, bool $verify = true): ?array
    {
        try {
            $response = Http::withUserAgent('Mozilla/5.0 (compatible; site-check)')->connectTimeout(6)->timeout(10)->withOptions(['verify' => $verify])->get($url);
        } catch (Throwable) {
            return null;
        }
        if ($response->serverError() || in_array($response->status(), [404, 410], true)) {
            return ['state' => 'down', 'title' => null];
        }
        // 403 and the like: a firewall answered, the site itself is up and unreadable from here.
        preg_match('~<title[^>]*>(.*?)</title>~is', mb_substr((string) $response->body(), 0, 20000), $m);
        $title = isset($m[1]) ? mb_substr(trim(html_entity_decode(preg_replace('/\s+/', ' ', $m[1]) ?? '')), 0, 160) : null;

        return ['state' => 'up', 'title' => $response->successful() && $title !== '' ? $title : null];
    }
}
