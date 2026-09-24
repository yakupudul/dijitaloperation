<?php

namespace App\Services\ExternalWrites;

use App\Models\CoreConnection;
use App\Models\ExternalWriteAction;
use App\Models\SiteFixItem;
use App\Services\Integrations\WordPress\WordPressConnectorClient;
use RuntimeException;

/**
 * ADR-070: sends approved site fixes and content updates to the MoxDOP Connector (≥ 1.4.0) and undoes them. The plugin
 * keeps the previous value of every change; undo restores it only where nobody changed the value since.
 */
final class WordPressFixWriter
{
    public function __construct(
        private readonly WordPressConnectorClient $client,
        private readonly WordPressDraftWriter $drafts,
    ) {}

    /**
     * The change sent to WordPress for one item.
     *
     * @return array<string, mixed>
     */
    public static function change(SiteFixItem $item): array
    {
        $base = ['type' => $item->type, 'object_id' => (int) $item->object_id, 'reference' => 'site-fix-'.$item->id];

        return match ($item->type) {
            'redirect' => ['type' => 'redirect', 'from' => (string) $item->url, 'value' => (string) $item->value(), 'reference' => $base['reference']],
            'schema' => $base + ['value' => is_array($item->value()) ? json_encode($item->value(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $item->value()],
            'noindex' => $base + ['value' => (bool) $item->value()],
            'internal_link' => $base + ['value' => ['anchor' => (string) data_get($item->proposed, 'value.anchor'), 'url' => (string) data_get($item->proposed, 'value.url')]],
            default => $base + ['value' => (string) $item->value()],
        };
    }

    /** @return array<string, mixed> */
    public function apply(ExternalWriteAction $action): array
    {
        $connection = $this->connection((int) $action->digital_asset_id);

        return match ($action->action) {
            ExternalWriteAction::ACTION_CONTENT_DRAFT => $this->contentDraft($action, $connection),
            ExternalWriteAction::ACTION_CONTENT_APPLY => $this->contentApply($action, $connection),
            default => $this->fixes($action, $connection),
        };
    }

    /** @return array<string, mixed> */
    public function undo(ExternalWriteAction $action): array
    {
        $connection = $this->connection((int) $action->digital_asset_id);
        if ($action->action === ExternalWriteAction::ACTION_CONTENT_DRAFT) {
            $postId = (int) ($action->result['post_id'] ?? 0);
            if ($postId > 0) {
                $this->client->trashDraft($connection, $postId);
            }
            SiteFixItem::query()->whereKey($action->request_payload['item_id'] ?? 0)->update(['status' => 'open', 'updated_at' => now()]);

            return ['removed' => $postId > 0 ? 1 : 0];
        }
        $changeIds = $action->action === ExternalWriteAction::ACTION_CONTENT_APPLY
            ? array_filter([(string) ($action->result['change_id'] ?? '')])
            : array_values(array_filter(array_column((array) ($action->result['changes'] ?? []), 'change_id')));
        if ($changeIds === []) {
            return ['restored' => 0];
        }
        $results = (array) ($this->client->undoFixes($connection, $changeIds)['results'] ?? []);
        $restored = array_values(array_filter($results, fn (array $r): bool => (bool) ($r['ok'] ?? false)));
        $restoredIds = array_column($restored, 'change_id');
        SiteFixItem::query()->where('write_action_id', $action->id)->whereIn('change_id', $restoredIds)->update(['status' => 'undone', 'updated_at' => now()]);
        if ($action->action === ExternalWriteAction::ACTION_CONTENT_APPLY && $restored !== []) {
            SiteFixItem::query()->whereKey($action->request_payload['item_id'] ?? 0)->update(['status' => 'undone', 'updated_at' => now()]);
        }
        $conflicts = array_values(array_filter($results, fn (array $r): bool => ($r['error'] ?? null) === 'changed_since'));
        if ($restored === [] && $conflicts !== []) {
            throw new RuntimeException('Değerler MoxDOP’tan sonra sitede değiştirilmiş; üzerine yazılmadı ('.count($conflicts).' değişiklik).');
        }

        return ['restored' => count($restored), 'conflicts' => count($conflicts), 'results' => $results];
    }

    /** @return array<string, mixed> */
    private function fixes(ExternalWriteAction $action, CoreConnection $connection): array
    {
        $items = SiteFixItem::query()->whereIn('id', (array) ($action->request_payload['item_ids'] ?? []))->orderBy('id')->get();
        $changes = $items->map(fn (SiteFixItem $item): array => self::change($item))->values()->all();
        if ($changes === []) {
            throw new RuntimeException('Gönderilecek düzeltme yok.');
        }
        $results = array_values((array) ($this->client->applyFixes($connection, $changes)['results'] ?? []));
        $out = [];
        foreach ($items->values() as $i => $item) {
            $result = (array) ($results[$i] ?? ['ok' => false, 'error' => 'no result']);
            $ok = (bool) ($result['ok'] ?? false);
            $item->forceFill([
                'status' => $ok ? 'applied' : 'failed',
                'change_id' => $result['change_id'] ?? null,
                'error' => $ok ? null : mb_substr((string) ($result['error'] ?? 'bilinmeyen hata'), 0, 500),
                'current' => $ok && array_key_exists('before', $result) ? ['value' => $result['before']] + (array) $item->current : $item->current,
            ])->save();
            $out[] = ['item_id' => $item->id, 'ok' => $ok, 'change_id' => $result['change_id'] ?? null, 'error' => $result['error'] ?? null];
        }
        $okCount = count(array_filter($out, fn (array $r): bool => $r['ok']));

        return ['changes' => $out, 'applied' => $okCount, 'failed' => count($out) - $okCount, 'status' => $okCount === count($out) ? 'succeeded' : ($okCount > 0 ? 'partial' : 'failed')];
    }

    /** @return array<string, mixed> */
    private function contentDraft(ExternalWriteAction $action, CoreConnection $connection): array
    {
        $item = SiteFixItem::query()->findOrFail((int) $action->request_payload['item_id']);
        if ($item->type === 'new_page') {
            $data = $this->client->createDraft($connection, [
                'title' => (string) data_get($item->proposed, 'value.title'), 'content_html' => (string) data_get($item->proposed, 'value.html'),
                'post_type' => 'page', 'excerpt' => '', 'reference' => 'site-fix-'.$item->id,
            ]);
        } else {
            $data = $this->client->createContentDraft($connection, (int) $item->object_id, (string) data_get($item->proposed, 'value.title'),
                (string) data_get($item->proposed, 'value.html'), 'site-fix-'.$item->id);
        }
        if (! is_numeric($data['post_id'] ?? null)) {
            throw new RuntimeException('WordPress taslak kimliği dönmedi.');
        }
        $item->forceFill(['status' => 'drafted', 'error' => null, 'current' => array_merge((array) $item->current, ['draft_id' => (int) $data['post_id'], 'edit_url' => $data['edit_url'] ?? null, 'preview_url' => $data['preview_url'] ?? null])])->save();
        if ($item->type === 'new_page') {
            $item->forceFill(['status' => 'applied'])->save();
        }

        return ['post_id' => (int) $data['post_id'], 'edit_url' => (string) ($data['edit_url'] ?? ''), 'preview_url' => (string) ($data['preview_url'] ?? ''), 'status' => 'succeeded'];
    }

    /** @return array<string, mixed> */
    private function contentApply(ExternalWriteAction $action, CoreConnection $connection): array
    {
        $item = SiteFixItem::query()->findOrFail((int) $action->request_payload['item_id']);
        $draftId = (int) data_get($item->current, 'draft_id');
        if ($draftId < 1) {
            throw new RuntimeException('Önce taslak kopyayı WordPress’e gönder.');
        }
        $data = $this->client->applyContentDraft($connection, $draftId);
        $item->forceFill(['status' => 'applied', 'change_id' => $data['change_id'] ?? null, 'write_action_id' => $action->id, 'error' => null])->save();

        return ['change_id' => (string) ($data['change_id'] ?? ''), 'post_id' => (int) ($data['post_id'] ?? 0), 'url' => (string) ($data['url'] ?? ''), 'status' => 'succeeded'];
    }

    private function connection(int $siteId): CoreConnection
    {
        $connection = $this->drafts->connection($siteId);
        $version = (string) data_get($connection->config, 'plugin_version', '0.0.0');
        $minimum = (string) config('moxdop-wordpress.fixes_min_plugin_version', '1.4.0');
        if (version_compare($version, $minimum, '<')) {
            throw new RuntimeException('WordPress Connector eklentisi '.$version.'; site düzeltmeleri için en az '.$minimum.' gerekli. Eklentiyi güncelle.');
        }

        return $connection;
    }
}
