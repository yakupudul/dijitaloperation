<?php

namespace App\Services\Advisor;

use App\Enums\AdvisorItemStatus;
use App\Enums\SeoTaskStatus;
use App\Models\AdvisorItem;
use App\Models\AdvisorPlan;
use App\Models\DigitalAsset;
use App\Models\SeoTask;
use App\Services\Advisor\GoogleAds\GoogleAdsRowScope;
use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Services\SeoTasks\SeoPlanInputCollector;
use App\Services\SeoTasks\SeoText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Measures "Yapıldı" work after the configured window (default 28 days) and again at 56 days, against the
 * metric the item was produced from. Observed change only — never presented as proof of causation.
 *
 * Measured today: Google Ads negative keyword lists (spend on the listed terms before vs after) and SEO
 * tasks with a target page (Search Console clicks 28 days before vs after). Other items are recorded as
 * done without a metric so reports can still list them.
 */
final class AdvisorOutcomeMeasurer
{
    public function __construct(
        private readonly GoogleAdsSpecialistBindingResolver $adsBindings,
        private readonly SeoPlanInputCollector $seoInputs,
    ) {}

    /** @return array{advisor: int, seo: int} */
    public function measureDue(): array
    {
        $days = (int) config('moxdop-advisor.measure.after_days', 28);
        $lag = (int) config('moxdop-advisor.measure.gsc_lag_days', 3);
        $counts = ['advisor' => 0, 'seo' => 0];

        AdvisorItem::query()
            ->where('status', AdvisorItemStatus::Done->value)
            ->whereNull('measured_at')
            ->where('resolved_at', '<=', now()->subDays($days + 1))
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->each(function (AdvisorItem $item) use ($days, &$counts): void {
                $item->forceFill(['outcome' => $this->safely(fn (): array => $this->advisorOutcome($item, $days)), 'measured_at' => now()])->save();
                $counts['advisor']++;
            });

        SeoTask::query()
            ->where('status', SeoTaskStatus::Done->value)
            ->whereNull('measured_at')
            ->where('resolved_at', '<=', now()->subDays($days + $lag))
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->each(function (SeoTask $task) use ($days, &$counts): void {
                $task->forceFill(['outcome' => $this->safely(fn (): array => $this->seoOutcome($task, $days)), 'measured_at' => now()])->save();
                $counts['seo']++;
            });

        // Faz 7: a second look at 56 days for what was measured at 28 (stored under outcome.d56).
        $late = (int) config('moxdop-advisor.measure.second_after_days', 56);
        foreach ([[AdvisorItem::query(), AdvisorItemStatus::Done->value, 1, fn (AdvisorItem $i): array => $this->advisorOutcome($i, $late), 'advisor'],
            [SeoTask::query(), SeoTaskStatus::Done->value, $lag, fn (SeoTask $t): array => $this->seoOutcome($t, $late), 'seo']] as [$query, $done, $wait, $measure, $bucket]) {
            $query->where('status', $done)->whereNotNull('measured_at')->where('resolved_at', '<=', now()->subDays($late + $wait))
                ->orderBy('id')->limit(2000)->get()
                ->filter(fn ($row): bool => ($row->outcome['status'] ?? null) === 'measured' && ! isset($row->outcome['d56']))
                ->take(500)
                ->each(function ($row) use ($measure, $bucket, &$counts): void {
                    $row->forceFill(['outcome' => array_merge((array) $row->outcome, ['d56' => $this->safely(fn (): array => $measure($row))])])->save();
                    $counts[$bucket]++;
                });
        }

        return $counts;
    }

    /** @return array<string, mixed> */
    public function advisorOutcome(AdvisorItem $item, int $days): array
    {
        if ($item->channel !== AdvisorPlan::CHANNEL_GOOGLE_ADS || $item->rule_id !== 'negative-keywords') {
            return ['status' => 'not_measured'];
        }
        $terms = array_values(array_filter(array_map(static fn ($row): string => mb_strtolower(trim((string) ($row['term'] ?? ''))), (array) ($item->evidence['terms'] ?? []))));
        $binding = $this->adsBindings->resolve((string) $item->digital_asset_id);
        if ($terms === [] || ! $binding->isReal()) {
            return ['status' => 'not_measured'];
        }
        $scope = new GoogleAdsRowScope((int) $item->digital_asset_id, (int) $binding->externalResourceId, (string) $binding->customerId);
        $resolved = CarbonImmutable::parse($item->resolved_at);
        $sum = function (CarbonImmutable $from, CarbonImmutable $to) use ($scope, $terms): float {
            $total = 0.0;
            foreach ($scope->daily('google_ads_search_term_daily', $from->toDateString(), $to->toDateString())->get(['search_term', 'cost_amount']) as $row) {
                if (in_array(mb_strtolower(trim((string) $row->search_term)), $terms, true)) {
                    $total += (float) $row->cost_amount;
                }
            }

            return round($total, 2);
        };
        $before = $sum($resolved->subDays($days), $resolved->subDay());
        $after = $sum($resolved->addDay(), $resolved->addDays($days));

        return [
            'status' => 'measured',
            'metric' => 'Listedeki terimlere harcama',
            'unit' => 'money',
            'currency' => $item->currency,
            'before' => $before,
            'after' => $after,
            'change_pct' => $before > 0 ? (int) round(($after / $before - 1) * 100) : null,
            'days' => $days,
            'good_direction' => 'down',
        ];
    }

    /** @return array<string, mixed> */
    public function seoOutcome(SeoTask $task, int $days): array
    {
        if (blank($task->target_url) || $task->resolved_at === null) {
            return ['status' => 'not_measured'];
        }
        $site = DigitalAsset::query()->find($task->digital_asset_id);
        if ($site === null) {
            return ['status' => 'not_measured'];
        }
        $key = SeoText::urlKey((string) $task->target_url);
        $resolved = CarbonImmutable::parse($task->resolved_at);
        $sum = function (CarbonImmutable $from, CarbonImmutable $to) use ($site, $key): array {
            $query = $this->seoInputs->scopeGsc(DB::table('gsc_page_daily'), $site, 'gsc_page_daily')
                ->whereBetween('reporting_date', [$from->toDateString(), $to->toDateString()]);
            $clicks = 0;
            $impressions = 0;
            $rows = 0;
            foreach ($query->get(['page', 'clicks', 'impressions']) as $row) {
                if (SeoText::urlKey((string) $row->page) === $key) {
                    $clicks += (int) $row->clicks;
                    $impressions += (int) $row->impressions;
                    $rows++;
                }
            }

            return ['clicks' => $clicks, 'impressions' => $impressions, 'rows' => $rows];
        };
        $before = $sum($resolved->subDays($days), $resolved->subDay());
        $after = $sum($resolved->addDay(), $resolved->addDays($days));
        if ($before['rows'] === 0 && $after['rows'] === 0) {
            return ['status' => 'no_data', 'metric' => 'Sayfanın Search Console tıklaması'];
        }

        return [
            'status' => 'measured',
            'metric' => 'Sayfanın Search Console tıklaması',
            'unit' => 'count',
            'before' => $before['clicks'],
            'after' => $after['clicks'],
            'impressions_before' => $before['impressions'],
            'impressions_after' => $after['impressions'],
            'change_pct' => $before['clicks'] > 0 ? (int) round(($after['clicks'] / $before['clicks'] - 1) * 100) : null,
            'days' => $days,
            'good_direction' => 'up',
            'url' => $task->target_url,
        ];
    }

    /** @return array<string, mixed> */
    private function safely(callable $measure): array
    {
        try {
            return $measure();
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => mb_substr($exception->getMessage(), 0, 200)];
        }
    }
}
