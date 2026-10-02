<?php

namespace App\Services\Collection;

use App\Models\Collection\CollectionRun;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class CollectionStatusQuery
{
    /**
     * @return array<string, mixed>
     */
    public function byUuid(string $uuid): array
    {
        $run = CollectionRun::query()
            ->with([
                'resourceRuns.datasetRuns.attempts',
                'datasetRuns.attempts',
            ])
            ->where('uuid', $uuid)
            ->first();

        if ($run === null) {
            throw (new ModelNotFoundException)->setModel(CollectionRun::class, [$uuid]);
        }

        return $this->serialize($run);
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(CollectionRun $run): array
    {
        return [
            'uuid' => $run->uuid,
            'id' => $run->id,
            'status' => $run->status->value,
            'trigger_type' => $run->trigger_type->value,
            'contract_registry_id' => $run->contract_registry_id,
            'contract_registry_version' => $run->contract_registry_version,
            'contract_registry_checksum' => $run->contract_registry_checksum,
            'started_at' => optional($run->started_at)?->toIso8601String(),
            'finished_at' => optional($run->finished_at)?->toIso8601String(),
            'last_activity_at' => optional($run->last_activity_at)?->toIso8601String(),
            'cancel_requested_at' => optional($run->cancel_requested_at)?->toIso8601String(),
            'progress' => [
                'resources_completed' => $run->resources_completed,
                'resources_total' => $run->resources_total,
                'datasets_completed' => $run->datasets_completed,
                'datasets_failed' => $run->datasets_failed,
                'datasets_total' => $run->datasets_total,
                // Honest aggregate — never weighted magic %.
            ],
            'resources' => $run->resourceRuns->map(fn ($r) => [
                'uuid' => $r->uuid,
                'provider_or_source' => $r->provider_or_source,
                'status' => $r->status->value,
                'datasets_completed' => $r->datasets_completed,
                'datasets_total' => $r->datasets_total,
                'datasets_failed' => $r->datasets_failed,
            ])->all(),
            'datasets' => $run->datasetRuns->map(function ($d) {
                return [
                    'uuid' => $d->uuid,
                    'provider_or_source' => $d->provider_or_source,
                    'dataset_contract_id' => $d->dataset_contract_id,
                    'request_family_id' => $d->request_family_id,
                    'requirement_level' => $d->requirement_level->value,
                    'contract_registry_version' => $d->contract_registry_version,
                    'status' => $d->status->value,
                    'attempt_count' => $d->attempt_count,
                    'progress_mode' => $d->progress_mode->value,
                    'percentage' => $d->percentage(),
                    'rows_received' => $d->rows_received,
                    'rows_written' => $d->rows_written,
                    'chunks_completed' => $d->chunks_completed,
                    'pages_completed' => $d->pages_completed,
                    'stage' => $d->stage,
                    'retry_at' => optional($d->retry_at)?->toIso8601String(),
                    'error_category' => $d->error_category?->value,
                    'error_code' => $d->error_code,
                    'error_message' => $d->error_message,
                    'attempts' => $d->attempts->map(fn ($a) => [
                        'attempt_number' => $a->attempt_number,
                        'status' => $a->status->value,
                        'error_category' => $a->error_category?->value,
                        'error_message' => $a->error_message,
                        'started_at' => optional($a->started_at)?->toIso8601String(),
                        'finished_at' => optional($a->finished_at)?->toIso8601String(),
                    ])->all(),
                ];
            })->all(),
        ];
    }
}
