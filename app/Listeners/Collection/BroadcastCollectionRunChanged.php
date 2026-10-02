<?php

namespace App\Listeners\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Events\Collection\CollectionRunCancelled;
use App\Events\Collection\CollectionRunChanged;
use App\Events\Collection\CollectionRunCompleted;
use App\Events\Collection\CollectionRunStarted;
use App\Events\Collection\DatasetRunFailed;
use App\Events\Collection\DatasetRunProgressed;
use App\Models\Collection\CollectionRun;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;

final class BroadcastCollectionRunChanged
{
    public function handleStarted(CollectionRunStarted $event): void
    {
        $this->broadcast($event->collectionRun);
    }

    public function handleCompleted(CollectionRunCompleted $event): void
    {
        $this->broadcast($event->collectionRun);
        $this->notifyTerminal($event->collectionRun);
    }

    public function handleCancelled(CollectionRunCancelled $event): void
    {
        $this->broadcast($event->collectionRun);
        $this->notifyTerminal($event->collectionRun);
    }

    public function handleDatasetFailed(DatasetRunFailed $event): void
    {
        $run = $event->datasetRun->collectionRun;
        if ($run !== null) {
            $this->broadcastThrottled($run);
        }
    }

    public function handleDatasetProgressed(DatasetRunProgressed $event): void
    {
        $run = $event->datasetRun->collectionRun;
        if ($run !== null) {
            $this->broadcastThrottled($run, seconds: 3);
        }
    }

    private function broadcast(CollectionRun $run): void
    {
        event(CollectionRunChanged::fromRun($run));
    }

    private function broadcastThrottled(CollectionRun $run, int $seconds = 3): void
    {
        $key = 'collection-run-broadcast:'.$run->id;
        if (! Cache::add($key, 1, $seconds)) {
            return;
        }

        event(CollectionRunChanged::fromRun($run));
    }

    private function notifyTerminal(CollectionRun $run): void
    {
        if ($run->requested_by_user_id === null) {
            return;
        }

        $user = User::query()->find($run->requested_by_user_id);
        if ($user === null) {
            return;
        }

        $title = match ($run->status) {
            CollectionRunStatus::Completed => __('operator.collection.notifications.completed'),
            CollectionRunStatus::Partial => __('operator.collection.notifications.completed_with_issues'),
            CollectionRunStatus::Failed => __('operator.collection.notifications.failed'),
            CollectionRunStatus::Cancelled => __('operator.collection.notifications.cancelled'),
            default => __('operator.collection.notifications.updated'),
        };

        $body = __('operator.collection.notifications.body', [
            'datasets' => (int) $run->datasets_completed.'/'.(int) $run->datasets_total,
            'status' => $run->status->value,
        ]);

        $notification = Notification::make()
            ->title($title)
            ->body($body);

        if ($run->status === CollectionRunStatus::Failed) {
            $notification->danger();
        } elseif ($run->status === CollectionRunStatus::Partial) {
            $notification->warning();
        } else {
            $notification->success();
        }

        $notification->sendToDatabase($user);
    }
}
