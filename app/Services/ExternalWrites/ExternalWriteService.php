<?php

namespace App\Services\ExternalWrites;

use App\Enums\AdvisorItemStatus;
use App\Jobs\ExecuteExternalWriteJob;
use App\Models\AdvisorItem;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\SeoTask;
use App\Models\User;
use App\Services\Integrations\WordPress\WordPressManagementService;
use App\Support\Roles;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Entry point for the two ADR-064 writes. Checks: kill switch, Admin role, eligible source item. Every
 * request becomes an ExternalWriteAction row and runs on the queue; undo is the same path in reverse.
 */
final class ExternalWriteService
{
    public function __construct(
        private readonly GoogleAdsNegativeListWriter $negatives,
        private readonly WordPressDraftWriter $drafts,
    ) {}

    public static function allowed(?User $user, string $channel): bool
    {
        return (bool) config('moxdop-external-writes.enabled', true)
            && (bool) config('moxdop-external-writes.'.$channel.'.enabled', true)
            && $user !== null && $user->is_active && $user->hasRole(Roles::ADMIN);
    }

    /** Admin-approved negative list for a Google Ads "negative-keywords" advisor item. */
    public function requestNegativeList(User $user, AdvisorItem $item, string $lines): ExternalWriteAction
    {
        $this->guard($user, ExternalWriteAction::CHANNEL_GOOGLE_ADS);
        if ($item->channel !== 'google_ads' || $item->rule_id !== 'negative-keywords' || $item->status !== AdvisorItemStatus::Open) {
            throw ValidationException::withMessages(['write' => 'Bu öneri Google Ads\'e gönderilemez.']);
        }
        $parsed = GoogleAdsNegativeListWriter::parse($lines);
        if ($parsed['keywords'] === []) {
            throw ValidationException::withMessages(['write' => 'Gönderilecek geçerli terim yok.']);
        }
        if ($this->pending($item->id, null)) {
            throw ValidationException::withMessages(['write' => 'Bu öneri için bir gönderim zaten sürüyor.']);
        }

        return $this->queue(ExternalWriteAction::query()->create([
            'channel' => ExternalWriteAction::CHANNEL_GOOGLE_ADS,
            'action' => ExternalWriteAction::ACTION_NEGATIVE_LIST_ADD,
            'digital_asset_id' => $item->digital_asset_id,
            'brand_id' => $item->brand_id,
            'advisor_item_id' => $item->id,
            'status' => 'queued',
            'request_payload' => ['keywords' => $parsed['keywords'], 'rejected' => $parsed['rejected'], 'shared_set_name' => config('moxdop-external-writes.google_ads.shared_set_name')],
            'requested_by' => $user->id,
        ]));
    }

    /** Admin-approved WordPress draft for an SEO task with a content brief. */
    public function requestDraft(User $user, SeoTask $task): ExternalWriteAction
    {
        $this->guard($user, ExternalWriteAction::CHANNEL_WORDPRESS);
        try {
            $draft = WordPressDraftWriter::draftFromTask($task);
            $this->drafts->connection((int) $task->digital_asset_id);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['write' => $exception->getMessage()]);
        }
        if ($this->pending(null, $task->id)) {
            throw ValidationException::withMessages(['write' => 'Bu görev için bir gönderim zaten sürüyor.']);
        }

        return $this->queue(ExternalWriteAction::query()->create([
            'channel' => ExternalWriteAction::CHANNEL_WORDPRESS,
            'action' => ExternalWriteAction::ACTION_DRAFT_CREATE,
            'digital_asset_id' => $task->digital_asset_id,
            'brand_id' => $task->brand_id,
            'seo_task_id' => $task->id,
            'status' => 'queued',
            'request_payload' => ['draft' => $draft],
            'requested_by' => $user->id,
        ]));
    }

    /**
     * ADR-068: Admin-approved install of one update WordPress offers (plugin file, theme stylesheet or core).
     * Not undoable from MoxDOP; the plugin must have updates switched on by the site admin.
     */
    public function requestUpdate(User $user, DigitalAsset $site, string $type, string $item, ?string $label = null): ExternalWriteAction
    {
        $this->guard($user, ExternalWriteAction::CHANNEL_WORDPRESS);
        if (! in_array($type, ['plugin', 'theme', 'core'], true) || ($type !== 'core' && (trim($item) === '' || strlen($item) > 200))) {
            throw ValidationException::withMessages(['write' => 'Geçersiz güncelleme.']);
        }
        try {
            app(WordPressManagementService::class)->connection((int) $site->id);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['write' => $exception->getMessage()]);
        }
        $busy = ExternalWriteAction::query()->where('digital_asset_id', $site->id)->where('action', ExternalWriteAction::ACTION_UPDATE_APPLY)
            ->whereIn('status', ['queued', 'running'])->exists();
        if ($busy) {
            throw ValidationException::withMessages(['write' => 'Bu sitede bir güncelleme zaten sürüyor; bitmesini bekle.']);
        }

        return $this->queue(ExternalWriteAction::query()->create([
            'channel' => ExternalWriteAction::CHANNEL_WORDPRESS,
            'action' => ExternalWriteAction::ACTION_UPDATE_APPLY,
            'digital_asset_id' => $site->id,
            'brand_id' => $site->brand_id,
            'status' => 'queued',
            'request_payload' => ['type' => $type, 'item' => $type === 'core' ? '' : $item, 'label' => $label],
            'requested_by' => $user->id,
        ]));
    }

    public function requestUndo(User $user, ExternalWriteAction $action): ExternalWriteAction
    {
        $this->guard($user, $action->channel);
        if (! $action->isUndoable()) {
            throw ValidationException::withMessages(['write' => 'Bu işlem geri alınamaz.']);
        }
        $action->forceFill(['status' => 'undoing', 'undone_by' => $user->id])->save();
        $this->dispatch($action, undo: true);

        return $action;
    }

    /** Executed by the job. */
    public function execute(ExternalWriteAction $action): void
    {
        $action->forceFill(['status' => 'running', 'started_at' => now()])->save();
        try {
            $result = match (true) {
                $action->channel === ExternalWriteAction::CHANNEL_GOOGLE_ADS => $this->negatives->apply($action),
                $action->action === ExternalWriteAction::ACTION_UPDATE_APPLY => app(WordPressManagementService::class)->apply($action),
                default => $this->drafts->apply($action),
            };
            $action->forceFill(['status' => $result['status'] ?? 'succeeded', 'result' => $result, 'finished_at' => now(), 'error' => null])->save();
            if ($action->advisor_item_id !== null && in_array($action->status, ['succeeded', 'partial'], true)) {
                AdvisorItem::query()->whereKey($action->advisor_item_id)->where('status', AdvisorItemStatus::Open->value)
                    ->update(['status' => AdvisorItemStatus::Done->value, 'resolved_at' => now(), 'resolved_by' => $action->requested_by, 'updated_at' => now()]);
            }
        } catch (Throwable $exception) {
            $action->forceFill(['status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($exception->getMessage(), 0, 500)])->save();
        }
    }

    public function executeUndo(ExternalWriteAction $action): void
    {
        try {
            $undo = $action->channel === ExternalWriteAction::CHANNEL_GOOGLE_ADS ? $this->negatives->undo($action) : $this->drafts->undo($action);
            $action->forceFill(['status' => 'undone', 'undone_at' => now(), 'result' => array_merge($action->result ?? [], ['undo' => $undo]), 'error' => null])->save();
        } catch (Throwable $exception) {
            $action->forceFill(['status' => 'undo_failed', 'error' => mb_substr($exception->getMessage(), 0, 500)])->save();
        }
    }

    private function guard(User $user, string $channel): void
    {
        if (! (bool) config('moxdop-external-writes.enabled', true) || ! (bool) config('moxdop-external-writes.'.$channel.'.enabled', true)) {
            throw ValidationException::withMessages(['write' => 'Harici yazma kapalı (EXTERNAL_WRITES_ENABLED).']);
        }
        if (! self::allowed($user, $channel)) {
            abort(403, 'Harici yazmayı yalnız Admin onaylayabilir.');
        }
    }

    private function pending(?int $itemId, ?int $taskId): bool
    {
        return ExternalWriteAction::query()
            ->when($itemId !== null, fn ($q) => $q->where('advisor_item_id', $itemId))
            ->when($taskId !== null, fn ($q) => $q->where('seo_task_id', $taskId))
            ->whereIn('status', ['queued', 'running', 'undoing'])
            ->exists();
    }

    private function queue(ExternalWriteAction $action): ExternalWriteAction
    {
        $this->dispatch($action, undo: false);

        return $action;
    }

    private function dispatch(ExternalWriteAction $action, bool $undo): void
    {
        dispatch(new ExecuteExternalWriteJob($action->id, $undo))
            ->onConnection((string) config('moxdop-external-writes.queue_connection', config('queue.default')))
            ->onQueue((string) config('moxdop-external-writes.queue', 'default'));
    }
}
