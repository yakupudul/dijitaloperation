<?php

namespace App\Services\Insights\Definitions;

use App\Ai\Agents\Insights\InsightAgent;
use App\Ai\Agents\Insights\TechnicalTasksAgent;
use App\Models\DigitalAsset;
use App\Services\Ai\Insights\BaseInsight;
use App\Services\IntelligenceProjection\Website\WebsiteTechnicalHealthReadService;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Database\Eloquent\Model;

/** "Geliştirici iş listesi" from the website's technical health observations. */
final class TechnicalTasksInsight extends BaseInsight
{
    public function kind(): string
    {
        return 'website.technical_tasks';
    }

    public function routeKey(): string
    {
        return AiRouteKeys::INSIGHT_TECHNICAL_TASKS;
    }

    public function label(): string
    {
        return 'Geliştirici için iş listesi çıkar';
    }

    public function subjectClass(): string
    {
        return DigitalAsset::class;
    }

    public function agent(): InsightAgent
    {
        return new TechnicalTasksAgent;
    }

    public function tokens(): array
    {
        return [6000, 1200];
    }

    public function freshDays(): int
    {
        return 14;
    }

    public function context(Model $subject): array
    {
        /** @var DigitalAsset $subject */
        $health = app(WebsiteTechnicalHealthReadService::class)->workspace($subject);
        if (! ($health['available'] ?? false)) {
            return ['error' => 'Bu site için henüz tarama verisi yok.'];
        }

        return [
            'site' => $subject->name,
            'brand' => $this->brandFacts($subject->brand),
            'summary' => $health['summary'] ?? null,
            'severity_counts' => $health['severity_counts'] ?? null,
            'observations' => array_slice((array) ($health['issue_groups'] ?? []), 0, 30),
            'infrastructure' => $health['infrastructure'] ?? null,
            'real_user_speed' => $health['field_vitals'] ?? null,
        ];
    }

    public function meta(Model $subject): array
    {
        /** @var DigitalAsset $subject */
        return ['brand_id' => $subject->brand_id, 'digital_asset_id' => $subject->id, 'title' => 'Teknik iş listesi · '.$subject->name];
    }
}
