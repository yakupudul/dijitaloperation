<?php

namespace App\Services\Social;

use App\Models\AgencySetting;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Sosyal paylaşım düzeni (yakup 2026-11-12): which weekdays a post goes out and at what hour, one post a day. Approved
 * posts form a line; each takes the next free day of the plan in line order. The line's order is the order of their
 * times, so moving a post swaps two times and nothing else is stored. A post with a fixed date (`pinned`) is not in
 * the line and keeps its day for itself. Standard: every day at 12:30.
 * Stories have their own line (yakup 2026-11-12: "günde 2 story, her gün 1 post"): every day at the story hours,
 * standard 09:00 and 18:00, so a story never takes a post's day.
 * Videos (Reels) get their own line once video hours are set (yakup 2026-11-13: "her gün bunlar paylaşılsın"): every
 * day at those hours, beside the day's post. With no video hour a video waits in the post line as before.
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

    /** @return list<string> the hours a video goes out, every day; empty = videos share the post line */
    public function reelTimes(): array
    {
        $stored = AgencySetting::query()->value('social_schedule');
        $stored = is_string($stored) ? json_decode($stored, true) : $stored;

        return is_array($stored) && is_array($stored['reels'] ?? null) ? $this->cleanTimes($stored['reels']) : [];
    }

    /**
     * Deneme Reels'i (yakup 2026-11-13): with two video hours, the last video of the day goes out as an Instagram trial
     * reel. Instagram shows it to people who do not follow the account first and moves it to the profile by itself when
     * it does well. On by default; the Plan tab switches it off.
     */
    public function trialReels(): bool
    {
        $stored = AgencySetting::query()->value('social_schedule');
        $stored = is_string($stored) ? json_decode($stored, true) : $stored;

        return ! is_array($stored) || ($stored['trial'] ?? true) !== false;
    }

    /** True for the video waiting at the last of at least two video hours while trial reels are on. */
    public function isTrial(SocialPost $post): bool
    {
        if ($post->kind !== 'reels' || $post->publish_at === null || ! $this->trialReels()) {
            return false;
        }
        $times = $this->reelTimes();

        return count($times) >= 2 && Carbon::parse($post->publish_at)->timezone(SocialPlanner::TIMEZONE)->format('H:i') === end($times);
    }

    /** Which line a post waits in: story, reels (only when video hours are set) or post. */
    public function lane(SocialPost $post): string
    {
        return match (true) {
            $post->kind === 'story' => 'story',
            $post->kind === 'reels' && $this->reelTimes() !== [] => 'reels',
            default => 'post',
        };
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
     * @param  array<array-key, mixed>|null  $reelTimes  video hours of every day; null keeps the stored ones
     * @param  bool|null  $trial  last video of the day as a trial reel; null keeps the stored choice
     */
    public function save(array $days, ?array $storyTimes = null, ?array $reelTimes = null, ?bool $trial = null): int
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
        $reels = $reelTimes === null ? $this->reelTimes() : $this->cleanTimes($reelTimes);
        $setting->forceFill(['social_schedule' => ['days' => $clean, 'stories' => $stories, 'reels' => $reels, 'trial' => $trial ?? $this->trialReels()]])->save();

        return $this->reflow();
    }

    /**
     * @param  bool|string  $lane  false / 'post', true / 'story', 'reels'
     * @return Collection<int, SocialPost> approved posts of one line still waiting for their day, in line order
     */
    public function line(bool|string $lane = false): Collection
    {
        return $this->ofLane(SocialPost::query(), $this->laneName($lane))
            ->where('status', SocialPost::APPROVED)->where('pinned', false)->where('publish_at', '>', now())
            ->whereDoesntHave('targets', fn ($q) => $q->whereIn('status', [SocialPostTarget::PUBLISHED, SocialPostTarget::PROCESSING, SocialPostTarget::DONE]))
            ->orderBy('publish_at')->orderBy('id')->get();
    }

    private function laneName(bool|string $lane): string
    {
        return $lane === true ? 'story' : ($lane === false ? 'post' : $lane);
    }

    /**
     * @param  Builder<SocialPost>  $query
     * @return Builder<SocialPost>
     */
    private function ofLane($query, string $lane)
    {
        return match (true) {
            $lane === 'story' => $query->where('kind', 'story'),
            $lane === 'reels' => $query->where('kind', 'reels'),
            $this->reelTimes() !== [] => $query->whereNotIn('kind', ['story', 'reels']),
            default => $query->where('kind', '!=', 'story'),
        };
    }

    /** A newly approved post goes to the end of the line. */
    public function enqueue(SocialPost $post): void
    {
        $last = $this->line($this->lane($post))->reject(fn (SocialPost $p): bool => $p->is($post))->max('publish_at');
        $post->update(['publish_at' => Carbon::instance($last ?? now())->addYear()]);
        $this->reflow();
    }

    /** Öne al / sona al: the post changes place with its neighbour. */
    public function move(SocialPost $post, int $direction): void
    {
        $line = $this->line($this->lane($post))->values();
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
        $posts = $days === [] ? 0 : $this->place($this->line(), fn (Carbon $day): array => array_filter([$days[$day->isoWeekday()] ?? null]), 'post');
        $reels = $this->reelTimes();
        if ($reels !== []) {
            $posts += $this->place($this->line('reels'), fn (Carbon $day): array => $reels, 'reels');
        }

        return $posts + $this->place($this->line(true), fn (Carbon $day): array => $this->storyTimes(), 'story');
    }

    /**
     * @param  Collection<int, SocialPost>  $line
     * @param  \Closure(Carbon): array<array-key, string>  $hours  the hours of one day
     */
    private function place(Collection $line, \Closure $hours, string $lane): int
    {
        if ($line->isEmpty()) {
            return 0;
        }
        $tz = SocialPlanner::TIMEZONE;
        $stories = $lane !== 'post';
        // A post day is taken by any other post of that day; a story or video hour only by one of its kind at that very hour.
        $taken = $this->ofLane(SocialPost::query(), $lane)
            ->whereIn('status', [SocialPost::APPROVED, SocialPost::PARTIAL, SocialPost::PUBLISHED])
            // The blog's own posts go out at their fixed hour beside the day's post; they do not take the day.
            ->where(fn ($q) => $q->whereNull('created_by')->orWhere('created_by', '!=', 'blog'))
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
