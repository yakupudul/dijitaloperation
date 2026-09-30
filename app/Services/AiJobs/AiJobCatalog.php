<?php

namespace App\Services\AiJobs;

use App\Contracts\Ai\TracksAiJob;
use App\Jobs\BuildBrandSetupProposalJob;
use App\Jobs\DraftReviewReplyJob;
use App\Jobs\ExtractBrandServicesJob;
use App\Jobs\Gbp\RunGbpAssistantJob;
use App\Jobs\GoogleAds\RunGoogleAdsAssistantJob;
use App\Jobs\Meta\RunMetaAssistantJob;
use App\Jobs\Prompts\RunPromptTrialJob;
use App\Jobs\Queries\AssignQueryServicesJob;
use App\Jobs\Queries\ClusterQueriesJob;
use App\Jobs\Queries\PlanQueriesJob;
use App\Jobs\Queries\PlanQueriesSectorJob;
use App\Jobs\Queries\ProposeQueryRulesJob;
use App\Jobs\RefreshBrandCandidatesJob;
use App\Jobs\RunChannelAnalystJob;
use App\Jobs\Site\AnalyzeCompetitorClusterJob;
use App\Jobs\Site\ProposeBacklinkSourcesJob;
use App\Jobs\Site\RefreshCompetitorsJob;
use App\Jobs\Site\RunSiteOperationJob;
use App\Jobs\WriteAiInsightJob;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\ServiceCategory;
use App\Services\Ai\AiUsageRecorder;
use App\Support\Ai\AiOperationLabels;
use Illuminate\Support\Str;
use Throwable;

/**
 * AI işleri: the queued jobs that do AI work. A listed job (or one implementing TracksAiJob) gets a row as soon as it is
 * queued ("Sırada") so it can be removed before it runs. The description (label, operation, subject, the page that uses
 * the result) is read from the job's public properties; it never runs the job's code.
 */
final class AiJobCatalog
{
    /** @var array<class-string, string> job class => label */
    private const array JOBS = [
        PlanQueriesJob::class => 'AI ile planla',
        PlanQueriesSectorJob::class => 'AI ile planla',
        AssignQueryServicesJob::class => 'AI ile hizmet öner',
        ClusterQueriesJob::class => 'AI ile kümele',
        ProposeQueryRulesJob::class => 'AI ile kural üret',
        ExtractBrandServicesJob::class => 'Hizmet keşfi',
        BuildBrandSetupProposalJob::class => 'Otomatik kur önerisi',
        RefreshBrandCandidatesJob::class => 'Marka adaylarını grupla',
        DraftReviewReplyJob::class => 'Yorum yanıt taslağı',
        WriteAiInsightJob::class => 'AI yorumu',
        RunChannelAnalystJob::class => 'Kanal analisti',
        RunGbpAssistantJob::class => 'İşletme Profili AI',
        RunGoogleAdsAssistantJob::class => 'Google Ads AI',
        RunMetaAssistantJob::class => 'Meta AI',
        RunPromptTrialJob::class => 'Prompt denemesi (Örnekte dene)',
        AnalyzeCompetitorClusterJob::class => 'Rakip analizi',
        ProposeBacklinkSourcesJob::class => 'AI ile kaynak öner',
        RefreshCompetitorsJob::class => 'Rakipleri güncelle',
        RunSiteOperationJob::class => 'Web sitesi AI işlemi',
    ];

    /** @var array<string, string> PlanQueriesJob step => operation */
    private const array PLAN_STEPS = [
        'sectors' => 'queries.plan_sectors',
        'services' => 'queries.plan_services',
        'filters' => 'queries.plan_filters',
    ];

    /** @var array<string, string> RunSiteOperationJob operation => AI operation */
    private const array SITE_OPERATIONS = [
        'categorize' => 'site.page_categories',
        'summaries' => 'site.page_summary',
        'standard' => 'site.standard_from_decision',
    ];

    public static function tracks(mixed $job): bool
    {
        if (! is_object($job)) {
            return false;
        }

        return $job instanceof TracksAiJob || isset(self::JOBS[$job::class]);
    }

    public static function tracksClass(?string $class): bool
    {
        return $class !== null && (isset(self::JOBS[$class]) || (class_exists($class) && is_subclass_of($class, TracksAiJob::class)));
    }

    /**
     * @return array{label: string, operation: ?string, subject: ?string, context: array<string, mixed>, link: ?string, user_id: ?int}
     */
    public static function describe(object $job): array
    {
        $operation = self::operation($job);
        $base = self::JOBS[$job::class] ?? class_basename($job);
        $label = match (true) {
            $job instanceof TracksAiJob => $job->aiLabel(),
            $job instanceof PlanQueriesJob, $job instanceof PlanQueriesSectorJob => $base.' · '.(PlanQueriesJob::LABELS[$job->step] ?? $job->step),
            $operation !== null && ! isset(self::JOBS[$job::class]) => AiOperationLabels::for($operation),
            $operation !== null && in_array($job::class, [RunSiteOperationJob::class, RunGbpAssistantJob::class, RunGoogleAdsAssistantJob::class, RunMetaAssistantJob::class], true) => AiOperationLabels::for($operation),
            default => $base,
        };
        $context = [];
        $subject = [];
        try {
            foreach (['brandId' => 'brand_id'] as $property => $key) {
                if (isset($job->{$property}) && is_int($job->{$property})) {
                    $context[$key] = $job->{$property};
                    $subject[] = Brand::query()->whereKey($job->{$property})->value('name');
                }
            }
            foreach (['assetId', 'siteId', 'websiteAssetId'] as $property) {
                if (isset($job->{$property}) && is_int($job->{$property})) {
                    $asset = DigitalAsset::query()->with('brand:id,name')->find($job->{$property}, ['id', 'name', 'type', 'brand_id']);
                    $context['asset_id'] = $job->{$property};
                    if ($asset !== null) {
                        $context['asset_type'] = (string) $asset->type;
                        $subject[] = $asset->brand?->name;
                        $subject[] = $asset->name;
                        if ($asset->brand_id !== null) {
                            $context['brand_id'] = (int) $asset->brand_id;
                        }
                    }
                }
            }
            $sectorIds = array_values(array_filter([
                ...(isset($job->sectorIds) && is_array($job->sectorIds) ? $job->sectorIds : []),
                ...(isset($job->sectorId) && is_int($job->sectorId) ? [$job->sectorId] : []),
            ], 'is_numeric'));
            if ($sectorIds !== []) {
                $context['sector_ids'] = array_map('intval', $sectorIds);
                $names = ServiceCategory::query()->whereIn('id', $sectorIds)->orderBy('name')->pluck('name')->all();
                $subject[] = count($names) > 3 ? implode(', ', array_slice($names, 0, 3)).' +'.(count($names) - 3) : implode(', ', $names);
            }
            if ($job instanceof ProposeQueryRulesJob) {
                $subject[] = count($job->queryIds).' sorgu';
            }
        } catch (Throwable) {
            // Description only.
        }
        $subjectText = implode(' · ', array_values(array_filter(array_map(fn ($part): string => trim((string) $part), $subject))));
        $user = $job->userId ?? ($job->actorId ?? null);

        return [
            'label' => Str::limit($label, 185),
            'operation' => $operation,
            'subject' => $subjectText !== '' ? Str::limit($subjectText, 190) : null,
            'context' => $context,
            'link' => self::link($job, $context),
            'user_id' => is_int($user) ? $user : null,
        ];
    }

    private static function operation(object $job): ?string
    {
        return match (true) {
            $job instanceof TracksAiJob => $job->aiOperation(),
            $job instanceof PlanQueriesJob, $job instanceof PlanQueriesSectorJob => self::PLAN_STEPS[$job->step] ?? null,
            $job instanceof AssignQueryServicesJob => 'queries.assign_services',
            $job instanceof ClusterQueriesJob => 'queries.cluster',
            $job instanceof ProposeQueryRulesJob => 'queries.filter_rules',
            $job instanceof ExtractBrandServicesJob => 'brand.services',
            $job instanceof RefreshBrandCandidatesJob => 'brand.candidates',
            $job instanceof DraftReviewReplyJob => 'gbp.review_reply',
            $job instanceof RunPromptTrialJob => AiUsageRecorder::TRIAL_ROUTE,
            $job instanceof AnalyzeCompetitorClusterJob => 'competitors.analyze',
            $job instanceof ProposeBacklinkSourcesJob => 'backlinks.sources',
            $job instanceof RefreshCompetitorsJob => 'competitors.classify',
            $job instanceof RunSiteOperationJob => self::SITE_OPERATIONS[$job->operation] ?? 'site.'.$job->operation,
            $job instanceof RunGbpAssistantJob => 'gbp.'.$job->operation,
            $job instanceof RunGoogleAdsAssistantJob => 'google_ads.'.$job->operation,
            $job instanceof RunMetaAssistantJob => 'meta.'.$job->operation,
            default => null,
        };
    }

    /**
     * The operator page that shows (or uses) the job's result, when known.
     *
     * @param  array<string, mixed>  $context
     */
    private static function link(object $job, array $context): ?string
    {
        try {
            return match (true) {
                $job instanceof TracksAiJob => $job->aiResultUrl(),
                $job instanceof PlanQueriesJob, $job instanceof PlanQueriesSectorJob => route('operator.library.queries.plan', [], false),
                $job instanceof AssignQueryServicesJob => route('operator.library.queries', ['service' => '__none'], false),
                $job instanceof ClusterQueriesJob, $job instanceof ProposeQueryRulesJob => route('operator.library.queries', [], false),
                $job instanceof RunPromptTrialJob => route('operator.settings.ai-operations', ['islem' => $job->operation], false),
                $job instanceof RefreshBrandCandidatesJob => route('operator.integrations.discovered', [], false),
                $job instanceof RunSiteOperationJob, $job instanceof RefreshCompetitorsJob => route('operator.website', ['assetId' => $job->siteId], false),
                $job instanceof RunGbpAssistantJob => route('operator.gbp', ['assetId' => $job->assetId], false),
                $job instanceof RunGoogleAdsAssistantJob => route('operator.google-ads.overview', ['assetId' => $job->assetId], false),
                $job instanceof RunMetaAssistantJob => route('operator.meta.overview', ['assetId' => $job->assetId], false),
                isset($context['brand_id']) => route('operator.brand', ['brand' => $context['brand_id']], false),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }
}
