<?php

namespace App\Services\ExternalWrites;

use App\Enums\AdvisorItemStatus;
use App\Jobs\ExecuteExternalWriteJob;
use App\Models\AdvisorItem;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\SeoTask;
use App\Models\SiteFixItem;
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
    /** ADR-070 actions handled by WordPressFixWriter. */
    private const array FIX_ACTIONS = [ExternalWriteAction::ACTION_SITE_FIX, ExternalWriteAction::ACTION_CONTENT_DRAFT, ExternalWriteAction::ACTION_CONTENT_APPLY];

    public function __construct(
        private readonly GoogleAdsNegativeListWriter $negatives,
        private readonly WordPressDraftWriter $drafts,
        private readonly WordPressFixWriter $fixes,
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

    /**
     * ADR-070: Admin-approved batch of site fixes (SEO title / description, alt text, schema, redirect, noindex,
     * canonical, internal link). Each item must have a proposed value; all go to one site in one request.
     *
     * @param  list<int>  $itemIds
     */
    public function requestSiteFixes(User $user, DigitalAsset $site, array $itemIds): ExternalWriteAction
    {
        $this->guard($user, ExternalWriteAction::CHANNEL_WORDPRESS);
        $items = SiteFixItem::query()->where('digital_asset_id', $site->id)->whereIn('id', $itemIds)
            ->whereIn('status', ['open', 'failed', 'undone'])->whereNotIn('type', ['content_update', 'new_page'])->get();
        // An empty value is a real fix only where it means "remove" (canonical override, schema, noindex off).
        $items = $items->filter(fn (SiteFixItem $item): bool => is_array($item->proposed) && array_key_exists('value', $item->proposed)
            && (in_array($item->type, ['noindex', 'canonical', 'schema'], true) || ($item->value() !== null && $item->value() !== '')))->values();
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['write' => 'Önerilen değeri olan seçili düzeltme yok.']);
        }
        if ($items->count() > 100) {
            throw ValidationException::withMessages(['write' => 'Tek seferde en fazla 100 düzeltme gönderilebilir.']);
        }
        $this->fixConnection($site);
        $action = ExternalWriteAction::query()->create([
            'channel' => ExternalWriteAction::CHANNEL_WORDPRESS, 'action' => ExternalWriteAction::ACTION_SITE_FIX,
            'digital_asset_id' => $site->id, 'brand_id' => $site->brand_id, 'status' => 'queued',
            'request_payload' => ['item_ids' => $items->pluck('id')->all(), 'changes' => $items->map(fn (SiteFixItem $i): array => WordPressFixWriter::change($i))->all()],
            'requested_by' => $user->id,
        ]);
        SiteFixItem::query()->whereIn('id', $items->pluck('id'))->update(['status' => 'queued', 'write_action_id' => $action->id, 'error' => null, 'updated_at' => now()]);

        return $this->queue($action);
    }

    /** ADR-070: the AI-written new version of a page (or a new page) goes to WordPress as a draft. */
    public function requestContentDraft(User $user, SiteFixItem $item): ExternalWriteAction
    {
        $this->guard($user, ExternalWriteAction::CHANNEL_WORDPRESS);
        if (! in_array($item->type, ['content_update', 'new_page'], true) || blank(data_get($item->proposed, 'value.html')) || ! in_array($item->status, ['open', 'failed', 'undone'], true)) {
            throw ValidationException::withMessages(['write' => 'Bu öneride gönderilecek metin yok.']);
        }
        $this->fixConnection($item->digitalAsset);

        return $this->queue(ExternalWriteAction::query()->create([
            'channel' => ExternalWriteAction::CHANNEL_WORDPRESS, 'action' => ExternalWriteAction::ACTION_CONTENT_DRAFT,
            'digital_asset_id' => $item->digital_asset_id, 'brand_id' => $item->brand_id, 'status' => 'queued',
            'request_payload' => ['item_id' => $item->id, 'object_id' => $item->object_id, 'title' => data_get($item->proposed, 'value.title')],
            'requested_by' => $user->id,
        ]));
    }

    /** ADR-070: second approval, the draft copy (possibly edited in WordPress) replaces the live page. */
    public function requestContentApply(User $user, SiteFixItem $item): ExternalWriteAction
    {
        $this->guard($user, ExternalWriteAction::CHANNEL_WORDPRESS);
        if ($item->type !== 'content_update' || $item->status !== 'drafted' || (int) data_get($item->current, 'draft_id') < 1) {
            throw ValidationException::withMessages(['write' => 'Önce yeni sürümü WordPress’e taslak olarak gönder.']);
        }
        $this->fixConnection($item->digitalAsset);

        return $this->queue(ExternalWriteAction::query()->create([
            'channel' => ExternalWriteAction::CHANNEL_WORDPRESS, 'action' => ExternalWriteAction::ACTION_CONTENT_APPLY,
            'digital_asset_id' => $item->digital_asset_id, 'brand_id' => $item->brand_id, 'status' => 'queued',
            'request_payload' => ['item_id' => $item->id, 'draft_id' => (int) data_get($item->current, 'draft_id')],
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
                in_array($action->action, self::FIX_ACTIONS, true) => $this->fixes->apply($action),
                default => $this->drafts->apply($action),
            };
            $action->forceFill(['status' => $result['status'] ?? 'succeeded', 'result' => $result, 'finished_at' => now(), 'error' => null])->save();
            if ($action->advisor_item_id !== null && in_array($action->status, ['succeeded', 'partial'], true)) {
                AdvisorItem::query()->whereKey($action->advisor_item_id)->where('status', AdvisorItemStatus::Open->value)
                    ->update(['status' => AdvisorItemStatus::Done->value, 'resolved_at' => now(), 'resolved_by' => $action->requested_by, 'updated_at' => now()]);
            }
        } catch (Throwable $exception) {
            $action->forceFill(['status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($exception->getMessage(), 0, 500)])->save();
            SiteFixItem::query()->where('write_action_id', $action->id)->where('status', 'queued')
                ->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 500), 'updated_at' => now()]);
        }
    }

    public function executeUndo(ExternalWriteAction $action): void
    {
        try {
            $undo = match (true) {
                $action->channel === ExternalWriteAction::CHANNEL_GOOGLE_ADS => $this->negatives->undo($action),
                in_array($action->action, self::FIX_ACTIONS, true) => $this->fixes->undo($action),
                default => $this->drafts->undo($action),
            };
            $action->forceFill(['status' => 'undone', 'undone_at' => now(), 'result' => array_merge($action->result ?? [], ['undo' => $undo]), 'error' => null])->save();
        } catch (Throwable $exception) {
            $action->forceFill(['status' => 'undo_failed', 'error' => mb_substr($exception->getMessage(), 0, 500)])->save();
        }
    }

    private function fixConnection(?DigitalAsset $site): void
    {
        try {
            $connection = app(WordPressDraftWriter::class)->connection((int) $site?->id);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['write' => $exception->getMessage()]);
        }
        $version = (string) data_get($connection->config, 'plugin_version', '0.0.0');
        if (version_compare($version, (string) config('moxdop-wordpress.fixes_min_plugin_version', '1.4.0'), '<')) {
            throw ValidationException::withMessages(['write' => 'WordPress Connector '.$version.'; site düzeltmeleri için en az '.config('moxdop-wordpress.fixes_min_plugin_version', '1.4.0').' gerekli. Eklentiyi güncelle ve eklenti ayarlarında "SEO fixes" / "Content updates" seçeneğini aç.']);
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
