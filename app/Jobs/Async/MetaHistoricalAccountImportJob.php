<?php

namespace App\Jobs\Async;

use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Run;
use App\Services\Async\AsyncOperationService;
use App\Support\Async\AsyncFailureClassifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use MoxDop\MetaAds\History\MetaHistoricalImportService;
use Throwable;

/**
 * Imports provider-available history for one Meta Ad Account into the historical store.
 * Updates the parent integration-scoped Run's progress metadata. Never binds a Brand.
 */
class MetaHistoricalAccountImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 2400;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 180];
    }

    /**
     * @param  array{from?: string, to?: string}  $window
     */
    public function __construct(
        public int $parentRunId,
        public int $externalResourceId,
        public array $window = [],
    ) {}

    public function handle(AsyncOperationService $async, MetaHistoricalImportService $import): void
    {
        $run = Run::query()->find($this->parentRunId);
        $resource = CoreExternalResource::query()->find($this->externalResourceId);
        if ($run === null || $resource === null) {
            return;
        }

        if (in_array($run->status, ['completed', 'partial', 'failed'], true)) {
            return;
        }

        $integration = CoreIntegration::query()->find($run->core_integration_id);
        if ($integration === null) {
            return;
        }

        $async->setPhase($run, 'importing_account', 'Importing '.$resource->display_name);
        $this->patch($run, [
            'current_account' => $resource->display_name,
            'current_resource_id' => $resource->id,
        ]);

        try {
            $options = array_filter([
                'from' => $this->window['from'] ?? null,
                'to' => $this->window['to'] ?? null,
                'on_progress' => function (array $progress) use ($async, $resource): void {
                    $phase = is_string($progress['current_phase'] ?? null) ? $progress['current_phase'] : 'importing';
                    $chunks = '';
                    if (isset($progress['chunks_done'], $progress['chunks_total'])) {
                        $chunks = ' · chunk '.(int) $progress['chunks_done'].'/'.(int) $progress['chunks_total'];
                    }
                    $async->setPhase(
                        Run::query()->findOrFail($this->parentRunId),
                        'importing_account',
                        'Importing '.$resource->display_name.' · '.str_replace('_', ' ', $phase).$chunks,
                    );
                },
            ], fn (mixed $v): bool => $v !== null && $v !== '');

            $result = $import->importAccountHistory($integration, $resource, $run->fresh() ?? $run, $options);

            $this->recordAccountResult($run->fresh() ?? $run, $resource, [
                'display_name' => $resource->display_name,
                'status' => $result['status'],
                'date_from' => $result['date_from'] ?? null,
                'date_to' => $result['date_to'] ?? null,
                'counts' => $result['counts'] ?? [],
                'errors' => array_slice($result['errors'] ?? [], 0, 20),
            ]);
        } catch (Throwable $exception) {
            $classified = AsyncFailureClassifier::classify($exception);
            $this->recordAccountResult($run->fresh() ?? $run, $resource, [
                'display_name' => $resource->display_name,
                'status' => 'failed',
                'errors' => [$classified['summary']],
            ]);

            if ($classified['category'] === AsyncFailureClassifier::AUTH
                || $classified['category'] === AsyncFailureClassifier::VALIDATION
                || $this->attempts() >= $this->tries) {
                return;
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function recordAccountResult(Run $run, CoreExternalResource $resource, array $result): void
    {
        $meta = $run->metadata ?? [];
        $accountResults = is_array($meta['account_results'] ?? null) ? $meta['account_results'] : [];
        $accountResults[(string) $resource->external_id] = $result;

        $done = collect($accountResults)->count();
        $total = (int) ($meta['accounts_total'] ?? $done);

        $run->update([
            'metadata' => array_merge($meta, [
                'account_results' => $accountResults,
                'accounts_done' => $done,
                'progress_units' => $done,
                'current_account' => $resource->display_name,
            ]),
        ]);

        if ($done >= $total && $total > 0) {
            app(MetaHistoricalImportFinalizer::class)->finalize($run->fresh() ?? $run);
        }
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private function patch(Run $run, array $patch): void
    {
        $run->update([
            'metadata' => array_merge($run->metadata ?? [], $patch),
        ]);
    }
}
