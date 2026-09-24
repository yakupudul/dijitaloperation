<?php

namespace App\Services\Insights\Definitions;

use App\Ai\Agents\Insights\AdvisorExplainAgent;
use App\Ai\Agents\Insights\InsightAgent;
use App\Models\AdvisorItem;
use App\Services\Ai\Insights\BaseInsight;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Database\Eloquent\Model;

/** "Neden ve ne yapmalı?" for one Danışman finding. */
final class AdvisorExplainInsight extends BaseInsight
{
    public function kind(): string
    {
        return 'advisor.explain';
    }

    public function routeKey(): string
    {
        return AiRouteKeys::INSIGHT_ADVISOR_EXPLAIN;
    }

    public function label(): string
    {
        return 'Neden önemli, ne yapmalı?';
    }

    public function tagStyles(): array
    {
        return [
            'now' => ['Bugün', 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300'],
            'next' => ['Bu hafta', 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'],
            'check' => ['Sonra kontrol', 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300'],
            'risk' => ['Dikkat', 'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300'],
        ];
    }

    public function subjectClass(): string
    {
        return AdvisorItem::class;
    }

    public function agent(): InsightAgent
    {
        return new AdvisorExplainAgent;
    }

    public function tokens(): array
    {
        return [2500, 700];
    }

    public function context(Model $subject): array
    {
        /** @var AdvisorItem $subject */
        return [
            'channel' => $subject->channel,
            'rule' => $subject->rule_id,
            'category' => $subject->category?->value ?? (string) $subject->getRawOriginal('category'),
            'severity' => $subject->severity,
            'title' => $subject->title,
            'reason' => $subject->reason,
            'impact' => $subject->impact_label ?? $subject->money($subject->impact_amount !== null ? (float) $subject->impact_amount : null),
            'evidence' => $subject->evidence,
            'checklist' => $subject->checklist,
            'copy_text' => $subject->copy_text !== null ? mb_substr((string) $subject->copy_text, 0, 1500) : null,
            'brand' => $this->brandFacts($subject->brand),
        ];
    }

    public function meta(Model $subject): array
    {
        /** @var AdvisorItem $subject */
        return ['brand_id' => $subject->brand_id, 'digital_asset_id' => $subject->digital_asset_id, 'title' => 'Danışman açıklaması · '.mb_substr((string) $subject->title, 0, 120)];
    }
}
