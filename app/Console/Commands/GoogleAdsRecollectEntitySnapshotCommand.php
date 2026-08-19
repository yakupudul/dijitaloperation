<?php

namespace App\Console\Commands;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\CollectionTriggerType;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DataPool\DatasetMaterialization;
use App\Models\DataPool\DatasetWriteBatch;
use App\Models\DigitalAsset;
use App\Services\Collection\CollectionPlanner;
use App\Services\Collection\Google\GoogleAdsKeywordGrainProof;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsRequestFamilyCatalog;
use App\Services\Collection\StartCollectionService;
use App\Services\Collection\Support\StartCollectionRequest;
use App\Services\Integrations\Google\GoogleCredentialBroker;
use App\Services\Integrations\Google\GoogleScopeRegistry;
use App\Support\Integrations\Google\GoogleAuthStatus;
use App\Support\Integrations\Google\GoogleResourceType;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Throwable;

#[Signature('moxdop:google-ads:recollect-entity-snapshot
    {--binding-id= : CoreAssetBinding id of the bound Google Ads customer (required; never auto-picked)}
    {--dry-run : Plan only; do not start a CollectionRun}
    {--wait : Poll until GADS_RF_ENTITY_SNAPSHOT is terminal}
    {--timeout=1800 : Seconds to wait when --wait is set}
    {--report-run-uuid= : Skip start; report an existing CollectionRun for this binding}
    {--json : Emit JSON}
    {--allow-non-staging : Allow APP_ENV other than staging (tests and explicit local)}')]
#[Description('Start a Manual force-refresh GADS_RF_ENTITY_SNAPSHOT recollection for one bound Google Ads customer through the collection engine. Never prints secrets.')]
class GoogleAdsRecollectEntitySnapshotCommand extends Command
{
    public function handle(
        StartCollectionService $starter,
        CollectionPlanner $planner,
        GoogleAdsKeywordGrainProof $grainProof,
        GoogleCredentialBroker $adsReadiness,
    ): int {
        try {
            $this->assertEnvironmentAllowed();
            $bindingId = $this->requiredBindingId();
            $binding = $this->loadEligibleBinding($bindingId);
            $readiness = $this->assertGoogleAdsReadiness($binding, $adsReadiness);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $asset = $binding->digitalAsset;
        $reportUuid = trim((string) $this->option('report-run-uuid'));
        $payload = [
            'command' => 'moxdop:google-ads:recollect-entity-snapshot',
            'deployed_sha' => $this->deployedSha(),
            'app_env' => (string) app()->environment(),
            'family_id' => GoogleAdsRequestFamilyCatalog::FAMILY_ENTITY_SNAPSHOT,
            'binding_id' => $binding->id,
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $binding->external_resource_id,
            'resource_external_id_hash' => GoogleAdsKeywordGrainProof::hashIdentifier((string) $binding->externalResource?->external_id),
            'google_auth_status' => $readiness['auth_status'],
            'developer_token_configured' => $readiness['developer_token_configured'],
            'oauth_scope_ready' => $readiness['oauth_scope_ready'],
            'trigger_type' => CollectionTriggerType::Manual->value,
            'force_refresh' => true,
        ];

        if ($this->option('dry-run')) {
            $plan = $planner->plan($this->startRequest($asset, $binding));
            $payload['dry_run'] = true;
            $payload['planned_request_family_ids'] = array_values(array_unique(array_column($plan['datasets'], 'request_family_id')));
            $payload['planned_dataset_count'] = count($plan['datasets']);
            $payload['message'] = 'Plan only. No CollectionRun started.';
            $this->emit($payload);

            return self::SUCCESS;
        }

        if ($reportUuid !== '') {
            $run = CollectionRun::query()->where('uuid', $reportUuid)->first();
            if ($run === null) {
                $this->error('CollectionRun uuid not found.');

                return self::FAILURE;
            }
            if (! $run->resourceRuns()->where('core_asset_binding_id', $binding->id)->exists()) {
                $this->error('CollectionRun does not include this Google Ads binding.');

                return self::FAILURE;
            }
            $payload = array_merge($payload, $this->evidenceForRun($run, $binding, $grainProof, includeBefore: false));
            $this->emit($payload);

            return $this->exitFromEvidence($payload);
        }

        $payload['grain_before'] = $grainProof->prove((int) $asset->id);
        $run = $starter->start($this->startRequest($asset, $binding));
        $payload['collection_run_id'] = $run->id;
        $payload['collection_run_uuid'] = $run->uuid;
        $payload['collection_run_status'] = $run->status->value;

        if ($this->option('wait')) {
            $timeout = max(1, (int) $this->option('timeout'));
            $dataset = $this->waitForEntitySnapshot($run, $timeout);
            if ($dataset === null) {
                $payload['wait_timed_out'] = true;
                $payload['message'] = 'Timed out waiting for GADS_RF_ENTITY_SNAPSHOT. Horizon collection worker must consume ExecuteDatasetRunJob. Re-run with --report-run-uuid after it finishes.';
                $payload = array_merge($payload, $this->evidenceForRun($run->fresh() ?? $run, $binding, $grainProof, includeBefore: false));
                $this->emit($payload);

                return self::FAILURE;
            }
        }

        $payload = array_merge($payload, $this->evidenceForRun($run->fresh() ?? $run, $binding, $grainProof, includeBefore: false));
        if (! $this->option('wait')) {
            $payload['message'] = 'CollectionRun started. Horizon must process queue=collection. Re-run with --wait, or later --report-run-uuid='.$run->uuid.' to print grain proof. Run the same command a second time after completion to prove idempotent upsert.';
        }

        $this->emit($payload);

        return $this->option('wait') ? $this->exitFromEvidence($payload) : self::SUCCESS;
    }

    private function assertEnvironmentAllowed(): void
    {
        if (app()->environment('production')) {
            throw new InvalidArgumentException(
                'Refusing to run on production. This acceptance recollection is for the staging host that already holds the bound Google Ads resource.',
            );
        }

        if (app()->environment(['staging', 'testing'])) {
            return;
        }

        if ($this->option('allow-non-staging')) {
            return;
        }

        throw new InvalidArgumentException(
            'This command must run on the staging application host (APP_ENV=staging) that already has the bound Google Ads OAuth token. Cursor Cloud SQLite is not that host. Pass --allow-non-staging only for explicit local/tests.',
        );
    }

    private function requiredBindingId(): int
    {
        $raw = $this->option('binding-id');
        if (! is_string($raw) && ! is_int($raw)) {
            throw new InvalidArgumentException('--binding-id is required. Never auto-picks the first Google Ads account.');
        }

        $value = trim((string) $raw);
        if ($value === '' || ! ctype_digit($value)) {
            throw new InvalidArgumentException('--binding-id is required. Never auto-picks the first Google Ads account.');
        }

        return (int) $value;
    }

    private function loadEligibleBinding(int $bindingId): CoreAssetBinding
    {
        $binding = CoreAssetBinding::query()
            ->with(['digitalAsset.brand', 'externalResource.integration'])
            ->find($bindingId);

        if (! $binding instanceof CoreAssetBinding) {
            throw new InvalidArgumentException('CoreAssetBinding '.$bindingId.' was not found.');
        }

        if ($binding->status !== CoreAssetBinding::STATUS_ACTIVE) {
            throw new InvalidArgumentException('CoreAssetBinding '.$bindingId.' is not active.');
        }

        if ($binding->capability !== GoogleScopeRegistry::CAPABILITY_GOOGLE_ADS) {
            throw new InvalidArgumentException('CoreAssetBinding '.$bindingId.' is not capability google_ads.');
        }

        $asset = $binding->digitalAsset;
        $resource = $binding->externalResource;
        if (! $asset instanceof DigitalAsset || ! $resource instanceof CoreExternalResource) {
            throw new InvalidArgumentException('Google Ads binding is missing DigitalAsset or ExternalResource.');
        }

        if ($resource->resource_type !== GoogleResourceType::GOOGLE_ADS_CUSTOMER) {
            throw new InvalidArgumentException('ExternalResource is not a Google Ads customer.');
        }

        if ($resource->status !== CoreExternalResource::STATUS_AVAILABLE) {
            throw new InvalidArgumentException('Google Ads ExternalResource is not available.');
        }

        return $binding;
    }

    /**
     * @return array{auth_status: string, developer_token_configured: bool, oauth_scope_ready: bool}
     */
    private function assertGoogleAdsReadiness(CoreAssetBinding $binding, GoogleCredentialBroker $adsReadiness): array
    {
        $integration = $binding->externalResource?->integration;
        if (! $integration instanceof CoreIntegration || ! $integration->isActive()) {
            throw new InvalidArgumentException('Google Integration is missing or not active.');
        }

        $auth = GoogleAuthStatus::for($integration);
        if ($auth !== GoogleAuthStatus::CONNECTED) {
            throw new InvalidArgumentException('Google Integration authorization is not usable ('.$auth.'). Reconnect via OAuth; do not paste tokens.');
        }

        $ads = $adsReadiness->adsApplicationReadiness($integration);
        if (! $ads['configured']) {
            throw new InvalidArgumentException('Google OAuth application credentials are not configured.');
        }
        if (! $ads['developer_token_configured']) {
            throw new InvalidArgumentException('Google Ads developer token is not configured.');
        }
        if (! $ads['oauth_scope_ready']) {
            throw new InvalidArgumentException('Google Ads OAuth scope is not granted on this integration.');
        }

        return [
            'auth_status' => $auth,
            'developer_token_configured' => true,
            'oauth_scope_ready' => true,
        ];
    }

    private function startRequest(DigitalAsset $asset, CoreAssetBinding $binding): StartCollectionRequest
    {
        return new StartCollectionRequest(
            digitalAsset: $asset,
            triggerType: CollectionTriggerType::Manual,
            bindingIds: [$binding->id],
            requestFamilyIds: [GoogleAdsRequestFamilyCatalog::FAMILY_ENTITY_SNAPSHOT],
            providerSources: ['GOOGLE_ADS'],
            forceRefresh: true,
            context: [
                'collection_intent' => 'google_ads_entity_snapshot_recollection',
                'collection_intent_label' => 'Google Ads entity-snapshot recollection',
                'acceptance_target' => 'google_ads_keyword_snapshot',
                'allow_multi_asset_bindings' => false,
            ],
        );
    }

    private function waitForEntitySnapshot(CollectionRun $run, int $timeoutSeconds): ?CollectionDatasetRun
    {
        $deadline = time() + $timeoutSeconds;
        while (time() <= $deadline) {
            $dataset = $this->entitySnapshotDataset($run->fresh(['datasetRuns']) ?? $run);
            if ($dataset instanceof CollectionDatasetRun && $dataset->status->isTerminal()) {
                return $dataset;
            }
            sleep(1);
        }

        return null;
    }

    private function entitySnapshotDataset(CollectionRun $run): ?CollectionDatasetRun
    {
        $run->loadMissing('datasetRuns');

        return $run->datasetRuns->first(
            fn (CollectionDatasetRun $dataset): bool => $dataset->request_family_id === GoogleAdsRequestFamilyCatalog::FAMILY_ENTITY_SNAPSHOT,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function evidenceForRun(
        CollectionRun $run,
        CoreAssetBinding $binding,
        GoogleAdsKeywordGrainProof $grainProof,
        bool $includeBefore,
    ): array {
        $dataset = $this->entitySnapshotDataset($run);
        $dataset?->loadMissing('resourceRun');
        $resource = $dataset?->resourceRun;
        $keywordBatches = [];
        if ($dataset !== null) {
            $keywordBatches = DatasetWriteBatch::query()
                ->where('dataset_run_id', $dataset->id)
                ->where('dataset_id', GoogleAdsKeywordGrainProof::TABLE)
                ->orderBy('id')
                ->get()
                ->map(fn (DatasetWriteBatch $batch): array => [
                    'id' => $batch->id,
                    'batch_key' => $batch->batch_key,
                    'status' => $batch->status?->value,
                    'rows_received' => $batch->rows_received,
                    'rows_inserted' => $batch->rows_inserted,
                    'rows_updated' => $batch->rows_updated,
                    'rows_unchanged' => $batch->rows_unchanged,
                    'committed_at' => $batch->committed_at?->toIso8601String(),
                ])
                ->all();
        }

        $materialization = DatasetMaterialization::query()
            ->where('dataset_id', GoogleAdsKeywordGrainProof::TABLE)
            ->where('digital_asset_id', $binding->digital_asset_id)
            ->where('external_resource_id', $binding->external_resource_id)
            ->first();

        $evidence = [
            'collection_run_id' => $run->id,
            'collection_run_uuid' => $run->uuid,
            'collection_run_status' => $run->status->value,
            'resource_run_id' => $resource?->id,
            'resource_run_status' => $resource?->status?->value,
            'dataset_run_id' => $dataset?->id,
            'dataset_run_uuid' => $dataset?->uuid,
            'dataset_run_status' => $dataset?->status?->value,
            'attempt_count' => $dataset?->attempt_count,
            'rows_received' => $dataset?->rows_received,
            'rows_written' => $dataset?->rows_written,
            'error_code' => $dataset?->error_code,
            'write_batches_keyword_snapshot' => $keywordBatches,
            'materialization' => $materialization === null ? null : [
                'id' => $materialization->id,
                'status' => $materialization->status?->value,
                'row_count_approx' => $materialization->row_count_approx,
                'last_successful_dataset_run_id' => $materialization->last_successful_dataset_run_id,
                'last_collected_at' => $materialization->last_collected_at?->toIso8601String(),
            ],
            'grain_after' => $grainProof->prove(
                (int) $binding->digital_asset_id,
                $dataset?->id,
            ),
        ];

        if ($includeBefore) {
            $evidence['grain_before'] = $grainProof->prove((int) $binding->digital_asset_id);
        }

        return $evidence;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function exitFromEvidence(array $payload): int
    {
        $status = $payload['dataset_run_status'] ?? null;
        if ($status === CollectionRunStatus::Completed->value) {
            return self::SUCCESS;
        }

        return self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->info('Google Ads entity-snapshot recollection');
        $this->line('SHA: '.($payload['deployed_sha'] ?? 'unknown'));
        $this->line('APP_ENV: '.($payload['app_env'] ?? ''));
        $this->line('Binding: '.($payload['binding_id'] ?? ''));
        $this->line('Digital asset: '.($payload['digital_asset_id'] ?? ''));
        $this->line('Family: '.($payload['family_id'] ?? ''));
        if (isset($payload['dry_run'])) {
            $this->line($payload['message'] ?? 'dry-run');
            $this->line('Planned families: '.implode(', ', $payload['planned_request_family_ids'] ?? []));

            return;
        }
        if (isset($payload['collection_run_uuid'])) {
            $this->line('CollectionRun '.$payload['collection_run_id'].' '.$payload['collection_run_uuid'].' status='.$payload['collection_run_status']);
        }
        if (isset($payload['dataset_run_id'])) {
            $this->line(sprintf(
                'DatasetRun %s %s family=%s status=%s attempts=%s received=%s written=%s',
                $payload['dataset_run_id'] ?? 'n/a',
                $payload['dataset_run_uuid'] ?? '',
                GoogleAdsRequestFamilyCatalog::FAMILY_ENTITY_SNAPSHOT,
                $payload['dataset_run_status'] ?? 'n/a',
                $payload['attempt_count'] ?? 'n/a',
                $payload['rows_received'] ?? 'n/a',
                $payload['rows_written'] ?? 'n/a',
            ));
        }
        $grain = $payload['grain_after'] ?? $payload['grain_before'] ?? null;
        if (is_array($grain)) {
            $this->line(sprintf(
                'Keyword grain COUNT(*)=%s DISTINCT(customer|ad_group|criterion)=%s non_null_ad_group=%s missing_ad_group=%s repeated_criterion_ids=%s schema_ok=%s',
                $grain['row_count'] ?? 'n/a',
                $grain['distinct_composite_count'] ?? 'n/a',
                $grain['non_null_ad_group_id_count'] ?? 'n/a',
                $grain['rows_missing_ad_group_id'] ?? 'n/a',
                $grain['criterion_ids_in_multiple_ad_groups'] ?? 'n/a',
                ! empty($grain['grain_matches_current_schema']) ? 'yes' : 'no',
            ));
            foreach ($grain['notes'] ?? [] as $note) {
                $this->line('- '.$note);
            }
        }
        if (isset($payload['message']) && is_string($payload['message'])) {
            $this->line($payload['message']);
        }
    }

    private function deployedSha(): string
    {
        $fromEnv = trim((string) env('MOXDOP_DEPLOYED_SHA', ''));
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        try {
            $result = Process::path(base_path())->run(['git', 'rev-parse', 'HEAD']);
            $sha = trim($result->output());
            if ($result->successful() && $sha !== '') {
                return $sha;
            }
        } catch (Throwable) {
            return 'unknown';
        }

        return 'unknown';
    }
}
