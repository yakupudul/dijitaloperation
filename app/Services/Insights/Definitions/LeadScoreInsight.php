<?php

namespace App\Services\Insights\Definitions;

use App\Ai\Agents\Insights\InsightAgent;
use App\Ai\Agents\Insights\LeadScoreAgent;
use App\Models\AgencyLead;
use App\Services\Ai\Insights\BaseInsight;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Database\Eloquent\Model;

/** "Talebi puanla" for one agency lead. Contact details (name, phone, e-mail) are not sent to the AI. */
final class LeadScoreInsight extends BaseInsight
{
    public function kind(): string
    {
        return 'sales.lead_score';
    }

    public function routeKey(): string
    {
        return AiRouteKeys::INSIGHT_LEAD_SCORE;
    }

    public function label(): string
    {
        return 'Talebi puanla, ilk mesajı hazırla';
    }

    public function tagStyles(): array
    {
        return [
            'hot' => ['Sıcak', 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300'],
            'warm' => ['Ilık', 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'],
            'cold' => ['Soğuk', 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300'],
            'ask' => ['Sor', 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300'],
            'reply' => ['İlk mesaj', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'],
        ];
    }

    public function subjectClass(): string
    {
        return AgencyLead::class;
    }

    public function agent(): InsightAgent
    {
        return new LeadScoreAgent;
    }

    public function tokens(): array
    {
        return [1500, 600];
    }

    public function freshDays(): int
    {
        return 90;
    }

    public function context(Model $subject): array
    {
        /** @var AgencyLead $subject */
        return [
            'company' => $subject->company,
            'message' => mb_substr((string) $subject->message, 0, 3000),
            'source' => $subject->source,
            'campaign' => $subject->utm['utm_campaign'] ?? null,
            'page' => $subject->page_url,
            'received_at' => $subject->received_at?->toDateString(),
            'agency_services' => ['Google Ads', 'Meta reklamları', 'SEO', 'Google İşletme Profili', 'web sitesi', 'aylık raporlama'],
        ];
    }

    public function meta(Model $subject): array
    {
        /** @var AgencyLead $subject */
        return ['brand_id' => null, 'digital_asset_id' => null, 'title' => 'Lead puanı · '.($subject->company ?: '#'.$subject->id)];
    }
}
