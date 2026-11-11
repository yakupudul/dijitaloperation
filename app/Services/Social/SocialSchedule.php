<?php

namespace App\Services\Social;

use App\Models\AgencySetting;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Sosyal paylaşım düzeni (yakup 2026-11-12): which weekdays a post goes out and at what hour, one post a day. Approved
 * posts form a line; each takes the next free day of the plan in line order. The line's order is the order of their
 * times, so moving a post swaps two times and nothing else is stored. A post with a fixed date (`pinned`) is not in
 * the line and keeps its day for itself. Standard: every day at 12:30.
 */
class SocialSchedule
{
    public const string DEFAULT_TIME = '12:30';

    /** @return array<int, string> ISO weekday (1 = Pazartesi … 7 = Pazar) => "HH:MM", only the chosen days */
    public function days(): array
    {
        $stored = AgencySetting::query()->value('social_schedule');
        $stored = is_string($stored) ? json_decode($stored, true) : $stored;
        if (! is_array($stored) || ! is_array($stored['days'] ?? null)) {
            return array_fill(1, 7, self::DEFAULT_TIME);
        }
        $days = [];
        foreach ($stored['days'] as $day => $time) {
            if ((int) $day >= 1 && (int) $day <= 7 && is_string($time) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1) {
                $days[(int) $day] = $time;
            }
        }
        ksort($days);

        return $days;
    }

    /** @param  array<int, string>  $days  chosen weekdays with their hour; at least one */
    public function save(array $days): int
    {
        $clean = [];
        foreach ($days as $day => $time) {
            if ((int) $day >= 1 && (int) $day <= 7 && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $time) === 1) {
                $clean[(int) $day] = (string) $time;
            }
        }
        if ($clean === []) {
            throw new \InvalidArgumentException('En az bir gün seçin.');
        }
        ksort($clean);
        $setting = AgencySetting::query()->first() ?? new AgencySetting;
        $setting->forceFill(['social_schedule' => ['days' => $clean]])->save();

        return $this->reflow();
    }

    /** @return Collection<int, SocialPost> approved posts still waiting for their day, in line order */
    public function line(): Collection
    {
        return SocialPost::query()
            ->where('status', SocialPost::APPROVED)->where('pinned', false)->where('publish_at', '>', now())
            ->whereDoesntHave('targets', fn ($q) => $q->whereIn('status', [SocialPostTarget::PUBLISHED, SocialPostTarget::PROCESSING, SocialPostTarget::DONE]))
            ->orderBy('publish_at')->orderBy('id')->get();
    }

    /** A newly approved post goes to the end of the line. */
    public function enqueue(SocialPost $post): void
    {
        $last = $this->line()->reject(fn (SocialPost $p): bool => $p->is($post))->max('publish_at');
        $post->update(['publish_at' => Carbon::instance($last ?? now())->addYear()]);
        $this->reflow();
    }

    /** Öne al / sona al: the post changes place with its neighbour. */
    public function move(SocialPost $post, int $direction): void
    {
        $line = $this->line()->values();
        $at = $line->search(fn (SocialPost $p): bool => $p->is($post));
        $other = $at === false ? null : $line->get($at + ($direction < 0 ? -1 : 1));
        if ($other === null) {
            return;
        }
        [$a, $b] = [$post->publish_at, $other->publish_at];
        $line[$at]->update(['publish_at' => $b]);
        $other->update(['publish_at' => $a]);
    }

    /** Gives every post in the line its day again, in line order. @return int posts whose time changed */
    public function reflow(): int
    {
        $line = $this->line();
        $days = $this->days();
        if ($line->isEmpty() || $days === []) {
            return 0;
        }
        $tz = SocialPlanner::TIMEZONE;
        $taken = SocialPost::query()
            ->whereIn('status', [SocialPost::APPROVED, SocialPost::PARTIAL, SocialPost::PUBLISHED])
            ->where('publish_at', '>=', now()->subDay())
            ->whereNotIn('id', $line->modelKeys())
            ->pluck('publish_at')->map(fn ($at): string => Carbon::parse($at)->timezone($tz)->toDateString())->flip();
        $changed = 0;
        $day = now($tz)->startOfDay();
        foreach ($line as $post) {
            for ($guard = 0; $guard < 800; $guard++) {
                $time = $days[$day->isoWeekday()] ?? null;
                $slot = $time !== null ? $day->copy()->setTimeFromTimeString($time) : null;
                $free = $slot !== null && $slot->greaterThan(now()->addMinutes(5)) && ! isset($taken[$day->toDateString()]);
                $day = $day->copy()->addDay();
                if ($free) {
                    break;
                }
            }
            if (! ($free ?? false)) {
                break;
            }
            if (! $post->publish_at->equalTo($slot)) {
                $post->update(['publish_at' => $slot->copy()->utc()]);
                $changed++;
            }
        }

        return $changed;
    }
}
