<?php

namespace App\Services\Social;

use App\Models\SocialPost;
use Carbon\CarbonInterface;

/**
 * Saate bağlı gönderiler (yakup 2026-11-14: "akşam 8'de Günaydın postu paylaşmışsın"): a post whose title or caption
 * greets a part of the day ("günaydın", "iyi akşamlar", "iyi geceler") or a day ("hayırlı cumalar", "iyi hafta
 * sonları") may only go out in that window, Istanbul time. The line gives it the first free slot that fits and the
 * publisher never sends it outside the window, whatever time it was given by hand.
 * Only greetings count: a scene such as "Gece 02:07, dişi ağrıyan hasta" can go out at any hour.
 */
class SocialTimeOfDay
{
    /**
     * Greeting => [from, to] hours (to inclusive up to the minute) and allowed ISO weekdays (null = every day).
     *
     * @var array<string, array{0: string, 1: string, 2: list<int>|null}>
     */
    private const array GREETINGS = [
        'günaydın' => ['05:00', '11:59', null],
        'iyi sabahlar' => ['05:00', '11:59', null],
        'hayırlı sabahlar' => ['05:00', '11:59', null],
        'tünaydın' => ['11:30', '15:59', null],
        'iyi öğlenler' => ['11:30', '15:59', null],
        'iyi akşamlar' => ['17:00', '23:59', null],
        'hayırlı akşamlar' => ['17:00', '23:59', null],
        'iyi geceler' => ['21:00', '23:59', null],
        'hayırlı geceler' => ['21:00', '23:59', null],
        'hayırlı cumalar' => ['00:00', '23:59', [5]],
        'iyi hafta sonları' => ['00:00', '23:59', [6, 7]],
        'iyi haftasonları' => ['00:00', '23:59', [6, 7]],
        'iyi pazarlar' => ['00:00', '23:59', [7]],
    ];

    /** @return array{0: string, 1: string, 2: list<int>|null, greeting: string}|null the window the post's greeting asks for */
    public function window(SocialPost $post): ?array
    {
        $text = $this->lower(($post->title ?? '').' '.($post->caption ?? ''));
        foreach (self::GREETINGS as $greeting => $window) {
            if (preg_match('/(?<![\p{L}])'.preg_quote($greeting, '/').'(?![\p{L}])/u', $text) === 1) {
                return [...$window, 'greeting' => $greeting];
            }
        }

        return null;
    }

    /** True when the post may go out at this moment (no greeting = any time). */
    public function fits(SocialPost $post, CarbonInterface $at): bool
    {
        $window = $this->window($post);
        if ($window === null) {
            return true;
        }
        $local = $at->copy()->timezone(SocialPlanner::TIMEZONE);
        if ($window[2] !== null && ! in_array($local->isoWeekday(), $window[2], true)) {
            return false;
        }
        $hm = $local->format('H:i');

        return $hm >= $window[0] && $hm <= $window[1];
    }

    private function lower(string $text): string
    {
        return mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], $text), 'UTF-8');
    }
}
