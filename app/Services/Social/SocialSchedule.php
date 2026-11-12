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
 * Stories have their own line (yakup 2026-11-12: "günde 2 story, her gün 1 post"): every day at the story hours,
 * standard 09:00 and 18:00, so a story never takes a post's day.
 */
class SocialSchedule
{
    public const string DEFAULT_TIME = '12:30';

    /** @var list<string> */
    public const array DEFAULT_STORY_TIMES = ['09:00', '18:00'];

    private const string TIME = '/^([01]\d|2[0-3]):[0-5]\d$/';

    /** @return list<string> the hours a story goes out, every day, earliest first */
    public function storyTimes(): array
    {
        $stored = AgencySetting::query()->value('social_schedule');
        $stored = is_string($stored) ? json_decode($stored, true) : $stored;
        if (! is_array($stored) || ! is_array($stored['stories'] ?? null)) {
            return self::DEFAULT_STORY_TIMES;
        }

        return $this->cleanTimes($stored['stories']);
    }

    /**
     * @param  array<array-key, mixed>  $times
     * @return list<string>
     */
    private function cleanTimes(array $times): array
    {
        $clean = array_values(array_unique(array_filter($times, fn ($t): bool => is_string($t) && preg_match(self::TIME, $t) === 1)));
        sort($clean);

        return $clean;
    }

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
            if ((int) $day >= 1 && (int) $day <= 7 && is_string($time) && preg_match(self::TIME, $time) === 1) {
                $days[(int) $day] = $time;
            }
        }
        ksort($days);

        return $days;
    }

    /**
     * @param  array<int, string>  $days  chosen weekdays with their hour; at least one
     * @param  array<array-key, mixed>|null  $storyTimes  story hours of every day; null keeps the stored ones
     */
    public function save(array $days, ?array $storyTimes = null): int
    {
        $clean = [];
        foreach ($days as $day => $time) {
            if ((int) $day >= 1 && (int) $day <= 7 && preg_match(self::TIME, (string) $time) === 1) {
                $clean[(int) $day] = (string) $time;
            }
        }
        if ($clean === []) {
            throw new \InvalidArgumentException('En az bir gün seçin.');
        }
        ksort($clean);
        $setting = AgencySetting::query()->first() ?? new AgencySetting;
        $stories = $storyTimes === null ? $this->storyTimes() : $this->cleanTimes($storyTimes);
        $setting->forceFill(['social_schedule' => ['days' => $clean, 'stories' => $stories]])->save();

        return $this->reflow();
    }

    /** @return Collection<int, SocialPost> approved posts (or, with $stories, stories) still waiting for their day, in line order */
    public function line(bool $stories = false): Collection
    {
        return SocialPost::query()
            ->where('kind', $stories ? '=' : '!=', 'story')
            ->where('status', SocialPost::APPROVED)->where('pinned', false)->where('publish_at', '>', now())
            ->whereDoesntHave('targets', fn ($q) => $q->whereIn('status', [SocialPostTarget::PUBLISHED, SocialPostTarget::PROCESSING, SocialPostTarget::DONE]))
            ->orderBy('publish_at')->orderBy('id')->get();
    }

    /** A newly approved post goes to the end of the line. */
    public function enqueue(SocialPost $post): void
    {
        $last = $this->line($post->kind === 'story')->reject(fn (SocialPost $p): bool => $p->is($post))->max('publish_at');
        $post->update(['publish_at' => Carbon::instance($last ?? now())->addYear()]);
        $this->reflow();
    }

    /** Öne al / sona al: the post changes place with its neighbour. */
    public function move(SocialPost $post, int $direction): void
    {
        $line = $this->line($post->kind === 'story')->values();
        $at = $line->search(fn (SocialPost $p): bool => $p->is($post));
        $other = $at === false ? null : $line->get($at + ($direction < 0 ? -1 : 1));
        if ($other === null) {
            return;
        }
        [$a, $b] = [$post->publish_at, $other->publish_at];
        $line[$at]->update(['publish_at' => $b]);
        $other->update(['publish_at' => $a]);
    }

    /** Gives every post and every story in its line its day again, in line order. @return int posts whose time changed */
    public function reflow(): int
    {
        $days = $this->days();
        $posts = $days === [] ? 0 : $this->place($this->line(), fn (Carbon $day): array => array_filter([$days[$day->isoWeekday()] ?? null]), false);

        return $posts + $this->place($this->line(true), fn (Carbon $day): array => $this->storyTimes(), true);
    }

    /**
     * @param  Collection<int, SocialPost>  $line
     * @param  \Closure(Carbon): array<array-key, string>  $hours  the hours of one day
     */
    private function place(Collection $line, \Closure $hours, bool $stories): int
    {
        if ($line->isEmpty()) {
            return 0;
        }
        $tz = SocialPlanner::TIMEZONE;
        // A post day is taken by any other post of that day; a story hour only by a story at that very hour.
        $taken = SocialPost::query()
            ->where('kind', $stories ? '=' : '!=', 'story')
            ->whereIn('status', [SocialPost::APPROVED, SocialPost::PARTIAL, SocialPost::PUBLISHED])
            ->where('publish_at', '>=', now()->subDay())
            ->whereNotIn('id', $line->modelKeys())
            ->pluck('publish_at')->map(fn ($at): string => Carbon::parse($at)->timezone($tz)->format($stories ? 'Y-m-d H:i' : 'Y-m-d'))->flip();
        $slots = (function () use ($hours, $taken, $tz, $stories) {
            $day = now($tz)->startOfDay();
            for ($guard = 0; $guard < 800; $guard++, $day = $day->copy()->addDay()) {
                foreach ($hours($day) as $time) {
                    $slot = $day->copy()->setTimeFromTimeString($time);
                    if ($slot->greaterThan(now()->addMinutes(5)) && ! isset($taken[$slot->format($stories ? 'Y-m-d H:i' : 'Y-m-d')])) {
                        yield $slot;
                    }
                }
            }
        })();
        $changed = 0;
        foreach ($line as $post) {
            if (! $slots->valid()) {
                break;
            }
            $slot = $slots->current();
            $slots->next();
            if (! $post->publish_at->equalTo($slot)) {
                $post->update(['publish_at' => $slot->copy()->utc()]);
                $changed++;
            }
        }

        return $changed;
    }
}
