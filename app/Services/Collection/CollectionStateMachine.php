<?php

namespace App\Services\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class CollectionStateMachine
{
    public function transition(Model $model, CollectionRunStatus $to): void
    {
        /** @var CollectionRunStatus $from */
        $from = $model->getAttribute('status');
        if (! $from instanceof CollectionRunStatus) {
            $from = CollectionRunStatus::from((string) $from);
        }

        if ($from === $to) {
            return;
        }

        if (! $from->canTransitionTo($to)) {
            throw new InvalidArgumentException("Invalid collection status transition {$from->value} → {$to->value}");
        }

        $model->forceFill([
            'status' => $to,
            'last_activity_at' => now(),
        ]);

        if ($to->isTerminal() && $model->getAttribute('finished_at') === null) {
            $model->forceFill(['finished_at' => now()]);
        }

        if ($to === CollectionRunStatus::Running && $model->getAttribute('started_at') === null) {
            $model->forceFill(['started_at' => now()]);
        }

        $model->save();
    }

    public function assertNotTerminal(CollectionDatasetRun|CollectionResourceRun|CollectionRun $model): void
    {
        /** @var CollectionRunStatus $status */
        $status = $model->status;
        if ($status->isTerminal()) {
            throw new InvalidArgumentException('Cannot mutate terminal collection state '.$status->value);
        }
    }
}
