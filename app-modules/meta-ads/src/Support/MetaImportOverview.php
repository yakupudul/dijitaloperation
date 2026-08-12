<?php

namespace MoxDop\MetaAds\Support;

use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Support\Collection;
use MoxDop\MetaAds\Models\MetaAdsAccountImportState;
use MoxDop\MetaAds\Models\MetaAdsHistoryCoverage;

/**
 * Authoritative, operator-facing Meta history import overview for an Integration.
 *
 * The single source of truth for "how many Ad Accounts did we discover" (from the
 * available meta_ads CoreExternalResources — ONE number, never Run metadata) and the
 * per-account import progress (from meta_ads_account_import_states, falling back to
 * meta_ads_history_coverage while the state table is still empty). Never invents
 * accounts: rows exist only for actually discovered resources.
 */
final class MetaImportOverview
{
    /**
     * @return array{
     *     ad_accounts_found: int,
     *     businesses_found: int,
     *     accounts_total: int,
     *     accounts_ready: int,
     *     accounts_with_state: int,
     *     history_source: string,
     *     progress_percent: int,
     *     overall_status: string,
     *     accounts: list<array<string, mixed>>,
     * }
     */
    public static function forIntegration(CoreIntegration $integration): array
    {
        /** @var Collection<int, CoreExternalResource> $resources */
        $resources = CoreExternalResource::query()
            ->where('integration_id', $integration->id)
            ->where('provider', ProviderRegistry::META)
            ->where('resource_type', 'meta_ads')
            ->where('status', CoreExternalResource::STATUS_AVAILABLE)
            ->orderBy('display_name')
            ->get();

        $adAccountsFound = $resources->count();
        $businessesFound = $resources
            ->map(fn (CoreExternalResource $r): ?string => self::businessId($r))
            ->filter()
            ->unique()
            ->count();

        /** @var Collection<int, MetaAdsAccountImportState> $states */
        $states = MetaAdsAccountImportState::query()
            ->where('core_integration_id', $integration->id)
            ->get()
            ->keyBy('core_external_resource_id');

        $useStates = $states->isNotEmpty();

        /** @var Collection<string, MetaAdsHistoryCoverage> $coverage */
        $coverage = $useStates
            ? collect()
            : MetaAdsHistoryCoverage::query()
                ->whereIn('core_external_resource_id', $resources->pluck('id'))
                ->where('data_layer', MetaAdsHistoryCoverage::LAYER_DAILY_FACTS)
                ->get()
                ->keyBy('core_external_resource_id');

        $accounts = [];
        $ready = 0;
        $running = 0;
        $failed = 0;

        foreach ($resources as $resource) {
            /** @var MetaAdsAccountImportState|null $state */
            $state = $states->get($resource->id);

            if ($state !== null) {
                $view = self::fromState($resource, $state);
            } elseif ($coverage->has($resource->id)) {
                $view = self::fromCoverage($resource, $coverage->get($resource->id));
            } else {
                $view = self::notImported($resource);
            }

            if (in_array($view['status'], [MetaAdsAccountImportState::STATUS_READY], true)) {
                $ready++;
            }
            if ($view['is_running']) {
                $running++;
            }
            if ($view['status'] === MetaAdsAccountImportState::STATUS_FAILED) {
                $failed++;
            }

            $accounts[] = $view;
        }

        $total = $adAccountsFound;
        $progress = $total > 0 ? (int) round(($ready / $total) * 100) : 0;

        $overall = match (true) {
            $running > 0 => 'running',
            $total > 0 && $ready === $total => 'ready',
            $failed > 0 && $ready > 0 => 'partial',
            $failed > 0 => 'failed',
            $ready > 0 => 'partial',
            default => 'idle',
        };

        return [
            'ad_accounts_found' => $adAccountsFound,
            'businesses_found' => $businessesFound,
            'accounts_total' => $total,
            'accounts_ready' => $ready,
            'accounts_with_state' => $states->count(),
            'history_source' => $useStates ? 'import_states' : ($coverage->isNotEmpty() ? 'coverage' : 'none'),
            'progress_percent' => $progress,
            'overall_status' => $overall,
            'accounts' => $accounts,
        ];
    }

    private static function businessId(CoreExternalResource $resource): ?string
    {
        $meta = is_array($resource->metadata) ? $resource->metadata : [];
        $businessId = $meta['business_id'] ?? $resource->parent_external_id;

        return filled($businessId) ? (string) $businessId : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function fromState(CoreExternalResource $resource, MetaAdsAccountImportState $state): array
    {
        return [
            'resource_id' => $resource->id,
            'external_id' => $resource->external_id,
            'label' => $resource->metaAdAccountOptionLabel(),
            'account_name' => $resource->display_name ?: $resource->external_id,
            'business_name' => self::businessName($resource),
            'status' => $state->status,
            'phase_label' => $state->phase_label ?? self::defaultPhaseLabel($state->status),
            'coverage_from' => $state->earliest_date?->toDateString(),
            'coverage_to' => $state->latest_date?->toDateString(),
            'campaigns_done' => $state->campaigns_done,
            'campaigns_total' => $state->campaigns_total,
            'adsets_done' => $state->adsets_done,
            'adsets_total' => $state->adsets_total,
            'ads_done' => $state->ads_done,
            'ads_total' => $state->ads_total,
            'chunks_done' => $state->chunks_done,
            'chunks_total' => $state->chunks_total,
            'daily_facts_count' => $state->daily_facts_count,
            'last_error_category' => $state->last_error_category,
            'last_error_summary' => $state->last_error_summary,
            'last_successful_at' => $state->last_successful_at?->diffForHumans(),
            'is_running' => $state->isRunning(),
            'progress_text' => self::progressText($state),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function fromCoverage(CoreExternalResource $resource, MetaAdsHistoryCoverage $coverage): array
    {
        $status = match ($coverage->status) {
            MetaAdsHistoryCoverage::STATUS_COMPLETE => MetaAdsAccountImportState::STATUS_READY,
            MetaAdsHistoryCoverage::STATUS_IMPORTING => MetaAdsAccountImportState::STATUS_DOWNLOADING,
            MetaAdsHistoryCoverage::STATUS_PARTIAL => MetaAdsAccountImportState::STATUS_PARTIAL,
            default => MetaAdsAccountImportState::STATUS_QUEUED,
        };

        return array_merge(self::notImported($resource), [
            'status' => $status,
            'phase_label' => self::defaultPhaseLabel($status),
            'coverage_from' => $coverage->start_date?->toDateString(),
            'coverage_to' => $coverage->end_date?->toDateString(),
            'is_running' => $coverage->status === MetaAdsHistoryCoverage::STATUS_IMPORTING,
            'progress_text' => ucfirst(str_replace('_', ' ', $status)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function notImported(CoreExternalResource $resource): array
    {
        return [
            'resource_id' => $resource->id,
            'external_id' => $resource->external_id,
            'label' => $resource->metaAdAccountOptionLabel(),
            'account_name' => $resource->display_name ?: $resource->external_id,
            'business_name' => self::businessName($resource),
            'status' => 'not_imported',
            'phase_label' => 'Not imported',
            'coverage_from' => null,
            'coverage_to' => null,
            'campaigns_done' => null,
            'campaigns_total' => null,
            'adsets_done' => null,
            'adsets_total' => null,
            'ads_done' => null,
            'ads_total' => null,
            'chunks_done' => null,
            'chunks_total' => null,
            'daily_facts_count' => 0,
            'last_error_category' => null,
            'last_error_summary' => null,
            'last_successful_at' => null,
            'is_running' => false,
            'progress_text' => 'Not imported',
        ];
    }

    private static function businessName(CoreExternalResource $resource): ?string
    {
        $meta = is_array($resource->metadata) ? $resource->metadata : [];

        return filled($meta['business_name'] ?? null) ? (string) $meta['business_name'] : null;
    }

    private static function defaultPhaseLabel(string $status): string
    {
        return match ($status) {
            MetaAdsAccountImportState::STATUS_READY => 'Ready',
            MetaAdsAccountImportState::STATUS_PARTIAL => 'Partial — some gaps remain',
            MetaAdsAccountImportState::STATUS_FAILED => 'Failed',
            MetaAdsAccountImportState::STATUS_NEEDS_ATTENTION => 'Needs attention',
            MetaAdsAccountImportState::STATUS_QUEUED => 'Queued',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    private static function progressText(MetaAdsAccountImportState $state): string
    {
        if ($state->status === MetaAdsAccountImportState::STATUS_READY) {
            return $state->daily_facts_count > 0
                ? number_format($state->daily_facts_count).' daily rows'
                : 'Ready';
        }

        if ($state->chunks_total !== null && $state->chunks_total > 0) {
            return 'Chunks '.(int) $state->chunks_done.' / '.(int) $state->chunks_total;
        }

        return self::defaultPhaseLabel($state->status);
    }
}
