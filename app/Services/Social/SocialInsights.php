<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use App\Models\SocialPostTarget;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Sosyal ölçüm: Instagram (büyüme yol haritası, yakup 2026-11-13), LinkedIn sayfası ve Threads (2026-11-18). Instagram ranks a post by watch time, likes per reach and
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

    /** Channels whose published posts are read: Instagram (Graph), the LinkedIn page and Threads (2026-11-18). */
    private const array PLATFORMS = [SocialAccount::INSTAGRAM, SocialAccount::LINKEDIN, SocialAccount::THREADS];

    public function __construct(private readonly SocialGraph $graph, private readonly LinkedInApi $linkedin, private readonly ThreadsApi $threads) {}

    /** @return array{read: int, failed: int} */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $stats = ['read' => 0, 'failed' => 0];
        SocialPostTarget::query()->with(['post', 'account'])
            ->where('status', SocialPostTarget::PUBLISHED)->whereNotNull('external_id')
            ->where('published_at', '>=', $now->subDays(self::DAYS))
            ->whereHas('account', fn ($q) => $q->whereIn('platform', self::PLATFORMS))
            ->orderBy('published_at')->limit(200)->get()
            ->filter(fn (SocialPostTarget $t): bool => $this->due($t, $now))
            ->each(function (SocialPostTarget $target) use (&$stats): void {
                try {
                    $values = match ($target->account?->platform) {
                        SocialAccount::LINKEDIN => $this->linkedinValues($target),
                        SocialAccount::THREADS => $this->threadsValues($target),
                        default => $this->instagramValues($target),
                    };
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

    /** @return array<string, int> */
    private function instagramValues(SocialPostTarget $target): array
    {
        $kind = match ($target->post?->kind) {
            'reels' => 'reels',
            'story' => 'story',
            default => 'feed',
        };
        $rows = (array) ($this->graph->get((string) $target->external_id.'/insights', (string) $target->account?->access_token, ['metric' => self::METRICS[$kind]])['data'] ?? []);
        $values = [];
        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            $value = $row['values'][0]['value'] ?? ($row['total_value']['value'] ?? null);
            if ($name !== '' && is_numeric($value)) {
                $values[$name] = (int) $value;
            }
        }

        return $values;
    }

    /**
     * The company page's post: likes and comments (social actions), and views, unique viewers, clicks and reshares
     * from the page's share statistics. The statistics need the page admin permission; without it the two counts stay.
     *
     * @return array<string, int>
     */
    private function linkedinValues(SocialPostTarget $target): array
    {
        $account = $target->account;
        $token = $this->linkedin->freshToken($account);
        $urn = (string) $target->external_id;
        $actions = $this->linkedin->get('/socialActions/'.rawurlencode($urn), $token);
        $values = [
            'likes' => (int) data_get($actions, 'likesSummary.totalLikes', 0),
            'comments' => (int) data_get($actions, 'commentsSummary.aggregatedTotalComments', 0),
        ];
        $org = (string) ($account->meta['org_urn'] ?? '');
        if ($org !== '') {
            try {
                $param = str_contains($urn, ':ugcPost:') ? 'ugcPosts' : 'shares';
                // Rest.li list syntax: the URNs inside List(...) are encoded, the parentheses are not.
                $stats = $this->linkedin->getRaw('/organizationalEntityShareStatistics?q=organizationalEntity&organizationalEntity='.rawurlencode($org).'&'.$param.'=List('.rawurlencode($urn).')', $token);
                $total = (array) data_get($stats, 'elements.0.totalShareStatistics', []);
                foreach (['impressionCount' => 'views', 'uniqueImpressionsCount' => 'reach', 'clickCount' => 'clicks', 'shareCount' => 'shares'] as $from => $to) {
                    if (is_numeric($total[$from] ?? null)) {
                        $values[$to] = (int) $total[$from];
                    }
                }
            } catch (SocialGraphException) {
            }
        }

        return $values;
    }

    /** Views, likes, replies, reposts and quotes of a Threads post; needs the threads_manage_insights permission. @return array<string, int> */
    private function threadsValues(SocialPostTarget $target): array
    {
        $token = $this->threads->freshToken($target->account);
        $rows = (array) ($this->threads->get('/v1.0/'.$target->external_id.'/insights', $token, ['metric' => 'views,likes,replies,reposts,quotes'])['data'] ?? []);
        $values = [];
        foreach ($rows as $row) {
            $value = $row['values'][0]['value'] ?? ($row['total_value']['value'] ?? null);
            if (is_numeric($value)) {
                $values[['replies' => 'comments', 'reposts' => 'shares'][(string) ($row['name'] ?? '')] ?? (string) ($row['name'] ?? '')] = (int) $value;
            }
        }

        return $values;
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
        foreach (['reach' => 'erişim', 'views' => 'izlenme', 'saved' => 'kaydetme', 'shares' => 'gönderme', 'likes' => 'beğeni', 'comments' => 'yorum', 'quotes' => 'alıntı', 'clicks' => 'tıklama'] as $key => $label) {
            if (isset($i[$key]) && is_int($i[$key])) {
                $parts[] = number_format($i[$key], 0, ',', '.').' '.$label;
            }
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }
}
