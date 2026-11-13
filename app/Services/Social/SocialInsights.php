<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use App\Models\SocialPostTarget;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Instagram ölçümü (büyüme yol haritası, yakup 2026-11-13). Instagram ranks a post by watch time, likes per reach and
 * sends per reach (Mosseri), so every published Instagram post keeps its reach, saves, sends (shares), likes, comments
 * and views. Read every 3 hours in the first two days, daily up to 30 days, then the numbers stay as they are.
 */
final class SocialInsights
{
    public const int DAYS = 30;

    /** Metrics the Graph API gives per media type (v22+; "impressions" and "plays" are gone). */
    private const array METRICS = [
        'feed' => 'reach,saved,shares,likes,comments,total_interactions,views',
        'reels' => 'reach,saved,shares,likes,comments,total_interactions,views,ig_reels_avg_watch_time',
        'story' => 'reach,replies,shares,views,navigation',
    ];

    public function __construct(private readonly SocialGraph $graph) {}

    /** @return array{read: int, failed: int} */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $stats = ['read' => 0, 'failed' => 0];
        SocialPostTarget::query()->with(['post', 'account'])
            ->where('status', SocialPostTarget::PUBLISHED)->whereNotNull('external_id')
            ->where('published_at', '>=', $now->subDays(self::DAYS))
            ->whereHas('account', fn ($q) => $q->where('platform', SocialAccount::INSTAGRAM))
            ->orderBy('published_at')->limit(200)->get()
            ->filter(fn (SocialPostTarget $t): bool => $this->due($t, $now))
            ->each(function (SocialPostTarget $target) use (&$stats): void {
                $kind = match ($target->post?->kind) {
                    'reels' => 'reels',
                    'story' => 'story',
                    default => 'feed',
                };
                try {
                    $rows = (array) ($this->graph->get((string) $target->external_id.'/insights', (string) $target->account?->access_token, ['metric' => self::METRICS[$kind]])['data'] ?? []);
                    $values = [];
                    foreach ($rows as $row) {
                        $name = (string) ($row['name'] ?? '');
                        $value = $row['values'][0]['value'] ?? ($row['total_value']['value'] ?? null);
                        if ($name !== '' && is_numeric($value)) {
                            $values[$name] = (int) $value;
                        }
                    }
                    $target->update(['insights' => $values, 'insights_at' => now()]);
                    $stats['read']++;
                } catch (Throwable $e) {
                    // A story is gone after 24 hours and a deleted post has no insights: keep what was read, try later.
                    $target->update(['insights' => ((array) $target->insights) + ['error' => mb_substr($e->getMessage(), 0, 200)], 'insights_at' => now()]);
                    $stats['failed']++;
                }
            });

        return $stats;
    }

    private function due(SocialPostTarget $target, CarbonImmutable $now): bool
    {
        if ($target->insights_at === null) {
            return true;
        }
        $age = $target->published_at?->diffInHours($now) ?? 0;

        return $target->insights_at->diffInMinutes($now) >= ($age < 48 ? 180 : 1440);
    }

    /** "1.240 erişim · 31 kaydetme · 12 gönderme" for the card; null before the first reading. */
    public static function line(SocialPostTarget $target): ?string
    {
        $i = (array) $target->insights;
        $parts = [];
        foreach (['reach' => 'erişim', 'views' => 'izlenme', 'saved' => 'kaydetme', 'shares' => 'gönderme', 'likes' => 'beğeni', 'comments' => 'yorum'] as $key => $label) {
            if (isset($i[$key]) && is_int($i[$key])) {
                $parts[] = number_format($i[$key], 0, ',', '.').' '.$label;
            }
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }
}
