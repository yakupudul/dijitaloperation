<?php

namespace App\Services\Insights\Definitions;

use App\Ai\Agents\Insights\CustomerBriefAgent;
use App\Ai\Agents\Insights\InsightAgent;
use App\Models\AdvisorItem;
use App\Models\AssetAlert;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\MonthlyReport;
use App\Services\Ai\Insights\BaseInsight;
use App\Services\Portfolio\CustomerCommercialSummary;
use App\Services\Portfolio\CustomerHealthScore;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** "Görüşme öncesi özet" for one customer. */
final class CustomerBriefInsight extends BaseInsight
{
    public function kind(): string
    {
        return 'customer.brief';
    }

    public function routeKey(): string
    {
        return AiRouteKeys::INSIGHT_CUSTOMER_BRIEF;
    }

    public function label(): string
    {
        return 'Görüşme öncesi özet';
    }

    public function tagStyles(): array
    {
        return [
            'issue' => ['Sorun', 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300'],
            'decision' => ['Karar', 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'],
            'good_news' => ['İyi haber', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'],
            'upsell' => ['Fırsat', 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300'],
        ];
    }

    public function subjectClass(): string
    {
        return Customer::class;
    }

    public function agent(): InsightAgent
    {
        return new CustomerBriefAgent;
    }

    public function freshDays(): int
    {
        return 7;
    }

    public function context(Model $subject): array
    {
        /** @var Customer $subject */
        $assetIds = DigitalAsset::query()->whereIn('brand_id', $subject->brands()->pluck('id'))->pluck('id');
        $report = MonthlyReport::query()->whereIn('brand_id', $subject->brands()->pluck('id'))->orderByDesc('month')->first();

        return [
            'customer' => $subject->name,
            'brands' => $subject->brands()->pluck('name')->all(),
            'health' => $this->attempt(fn () => app(CustomerHealthScore::class)->compute($subject)),
            'commercial' => $this->attempt(fn () => app(CustomerCommercialSummary::class)->for($subject)),
            'open_advisor_work' => AdvisorItem::query()->open()->whereIn('digital_asset_id', $assetIds)->orderByDesc('priority_score')->limit(10)
                ->get(['channel', 'title', 'severity', 'impact_label'])->map(fn (AdvisorItem $i): array => $i->only(['channel', 'title', 'severity', 'impact_label']))->all(),
            'open_alerts' => AssetAlert::query()->open()->whereIn('digital_asset_id', $assetIds)->limit(10)->get(['title', 'message'])
                ->map(fn (AssetAlert $a): array => ['title' => $a->title, 'message' => $a->message])->all(),
            'last_report' => $report === null ? null : ['month' => $report->month, 'commentary' => data_get($report->commentary, 'summary') ?? data_get($report->commentary, 'text')],
            'renewals_due' => Schema::hasTable('asset_renewals') ? DB::table('asset_renewals')->whereIn('brand_id', $subject->brands()->pluck('id'))
                ->whereNotNull('expires_on')->where('expires_on', '<=', now()->addDays(45)->toDateString())->limit(10)->get(['kind', 'label', 'expires_on'])->map(fn ($r): array => (array) $r)->all() : [],
        ];
    }

    public function meta(Model $subject): array
    {
        /** @var Customer $subject */
        return ['brand_id' => null, 'digital_asset_id' => null, 'title' => 'Müşteri özeti · '.$subject->name];
    }

    private function attempt(callable $read): mixed
    {
        try {
            return $read();
        } catch (Throwable) {
            return null;
        }
    }
}
