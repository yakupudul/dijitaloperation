<?php

namespace App\Services\CommandCenter;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Komuta merkezi alert aging: a condition that has been open for days stops nagging.
 *
 * Each item's streak is kept in `inbox_item_states`: first_seen_at starts at the source's own first-seen time (alert
 * first detection, advisor / SEO item creation…) or at the first time the inbox saw it. The streak restarts when the
 * item's fingerprint (severity, title shape, money / clicks band) changes, when it disappeared and came back (a
 * complete evaluation marks absent keys gone; snoozed or resolved items count as absent) or when the source reports
 * a later first-seen time. An item open for `aging.days` or longer is "aged": it moves to "Uzun süredir devam eden",
 * leaves the top badge and the dashboard. Never-age topics and people's own work (tasks, invoices…) never age.
 */
final class InboxAging
{
    private const string TABLE = 'inbox_item_states';

    public function days(): int
    {
        return max(1, (int) config('moxdop-command-center.aging.days', 10));
    }

    /** @param  array<string, mixed>  $item  an item with its topic */
    public function canAge(array $item): bool
    {
        return ! in_array($item['source'], (array) config('moxdop-command-center.aging.exempt_sources', []), true)
            && ! TopicCatalog::matches((string) ($item['topic'] ?? $item['source']), (array) config('moxdop-command-center.aging.never_age', []));
    }

    /**
     * Adds `since` (start of the current streak) and `aged` to every item and records the streaks.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @param  bool  $complete  every producer answered, so a missing key really disappeared
     * @return Collection<int, array<string, mixed>>
     */
    public function track(Collection $items, bool $complete): Collection
    {
        $now = CarbonImmutable::now();
        $threshold = $now->subDays($this->days());
        $since = [];
        try {
            if (Schema::hasTable(self::TABLE)) {
                $since = $this->record($items, $complete, $now);
            }
        } catch (Throwable $error) {
            report($error);
        }

        return $items->map(function (array $item) use ($since, $threshold): array {
            $item['since'] = $since[$item['key']] ?? $this->sourceAge($item);
            $item['aged'] = $item['since'] !== null && $this->canAge($item) && $item['since']->lte($threshold);

            return $item;
        });
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public static function fingerprint(array $item): string
    {
        if (isset($item['fingerprint']) && is_string($item['fingerprint'])) {
            return $item['fingerprint'];
        }
        $band = static fn (float $value): int => $value > 0 ? (int) floor(log($value + 1, 2)) : 0;

        return md5(implode('|', [
            $item['severity'],
            // Counts in titles ("12 lead'in sonucu…") change daily; the problem does not.
            preg_replace('/\d+(?:[.,]\d+)*/u', '#', (string) $item['title']),
            $band((float) ($item['money'] ?? 0)),
            $band((float) ($item['clicks'] ?? 0)),
        ]));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<string, CarbonImmutable> item key => streak start
     */
    private function record(Collection $items, bool $complete, CarbonImmutable $now): array
    {
        $keys = $items->pluck('key')->unique()->values();
        $rows = collect();
        foreach ($keys->chunk(500) as $chunk) {
            $rows = $rows->merge(DB::table(self::TABLE)->whereIn('item_key', $chunk->all())->get()->keyBy('item_key'));
        }

        $since = [];
        $inserts = [];
        $touch = [];
        foreach ($items->unique('key') as $item) {
            $key = (string) $item['key'];
            $fingerprint = self::fingerprint($item);
            $sourceAge = $this->sourceAge($item);
            $row = $rows->get($key);
            if ($row === null) {
                $start = $sourceAge ?? $now;
                $inserts[] = ['item_key' => $key, 'first_seen_at' => $start, 'last_seen_at' => $now, 'fingerprint' => $fingerprint, 'gone_at' => null, 'created_at' => $now, 'updated_at' => $now];
                $since[$key] = $start;

                continue;
            }
            $start = CarbonImmutable::parse((string) $row->first_seen_at);
            $restart = match (true) {
                $row->gone_at !== null, $row->fingerprint !== $fingerprint => $now,
                $sourceAge !== null && $sourceAge->gt($start->addMinute()) => $sourceAge,
                default => null,
            };
            if ($restart !== null) {
                DB::table(self::TABLE)->where('item_key', $key)->update(['first_seen_at' => $restart, 'last_seen_at' => $now, 'fingerprint' => $fingerprint, 'gone_at' => null, 'updated_at' => $now]);
                $start = $restart;
            } elseif (CarbonImmutable::parse((string) $row->last_seen_at)->lt($now->subHour())) {
                $touch[] = $key;
            }
            $since[$key] = $start;
        }
        foreach (array_chunk($inserts, 200) as $chunk) {
            DB::table(self::TABLE)->insertOrIgnore($chunk);
        }
        foreach (array_chunk($touch, 500) as $chunk) {
            DB::table(self::TABLE)->whereIn('item_key', $chunk)->update(['last_seen_at' => $now, 'updated_at' => $now]);
        }

        if ($complete) {
            $present = array_flip($keys->all());
            $gone = DB::table(self::TABLE)->whereNull('gone_at')->pluck('item_key')->reject(fn ($key): bool => isset($present[(string) $key]))->values()->all();
            foreach (array_chunk($gone, 500) as $chunk) {
                DB::table(self::TABLE)->whereIn('item_key', $chunk)->update(['gone_at' => $now, 'updated_at' => $now]);
            }
            DB::table(self::TABLE)->where('gone_at', '<', $now->subDays(90))->delete();
        }

        return $since;
    }

    /** @param  array<string, mixed>  $item */
    private function sourceAge(array $item): ?CarbonImmutable
    {
        if (($item['age'] ?? null) === null || $item['age'] === '') {
            return null;
        }
        try {
            $age = CarbonImmutable::parse($item['age'] instanceof \DateTimeInterface ? $item['age']->format(DATE_ATOM) : (string) $item['age']);
        } catch (Throwable) {
            return null;
        }

        // A due date in the future (a follow-up planned for tonight) is not an open condition yet.
        return $age->isFuture() ? null : $age;
    }
}
