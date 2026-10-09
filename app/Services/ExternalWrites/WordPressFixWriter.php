<?php

namespace App\Services\ExternalWrites;

use App\Models\CoreConnection;
use App\Models\ExternalWriteAction;
use App\Services\Integrations\WordPress\WordPressConnectorClient;
use RuntimeException;

/**
 * ADR-070: sends approved site fixes and content updates to the MoxDOP Connector (≥ 1.4.0) and undoes them. The plugin
 * keeps the previous value of every change; undo restores it only where nobody changed the value since.
 *
 * v2: the writer is payload-driven. The caller (a later phase's suggestion flow) puts the change list, the content
 * draft or the draft id into `request_payload`; results live on the ExternalWriteAction row only.
 */
final class WordPressFixWriter
{
    public function __construct(
        private readonly WordPressConnectorClient $client,
        private readonly WordPressDraftWriter $drafts,
    ) {}

    /**
     * One change for the connector's /fixes endpoint.
     *
     * @param  array{type: string, object_id?: int, reference: string, value?: mixed, from?: string}  $change
     * @return array<string, mixed>
     */
    public static function change(array $change): array
    {
        $type = (string) ($change['type'] ?? '');
        $base = ['type' => $type, 'object_id' => (int) ($change['object_id'] ?? 0), 'reference' => (string) ($change['reference'] ?? '')];
        $value = $change['value'] ?? null;

        return match ($type) {
            'redirect' => ['type' => 'redirect', 'from' => (string) ($change['from'] ?? ''), 'value' => (string) $value, 'reference' => $base['reference']],
            // 1.9.0: the redirect goes into the site's SEO plugin and the redirected post becomes a draft (object_id, else found by path).
            'merge_redirect' => ['type' => 'merge_redirect', 'object_id' => $base['object_id'], 'from' => (string) ($change['from'] ?? ''), 'value' => (string) $value, 'reference' => $base['reference']],
            'schema' => $base + ['value' => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $value],
            'noindex' => $base + ['value' => (bool) $value],
            'internal_link' => $base + ['value' => ['anchor' => (string) data_get($value, 'anchor'), 'url' => (string) data_get($value, 'url')]],
            default => $base + ['value' => (string) $value],
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
        $conflicts = array_values(array_filter($results, fn (array $r): bool => ($r['error'] ?? null) === 'changed_since'));
        if ($restored === [] && $conflicts !== []) {
            throw new RuntimeException('Değerler MoxDOP’tan sonra sitede değiştirilmiş; üzerine yazılmadı ('.count($conflicts).' değişiklik).');
        }

        return ['restored' => count($restored), 'conflicts' => count($conflicts), 'results' => $results];
    }

    /** @return array<string, mixed> */
    private function fixes(ExternalWriteAction $action, CoreConnection $connection): array
    {
        $changes = array_values(array_map(fn (array $change): array => self::change($change), (array) ($action->request_payload['changes'] ?? [])));
        if ($changes === []) {
            throw new RuntimeException('Gönderilecek düzeltme yok.');
        }
        $results = array_values((array) ($this->client->applyFixes($connection, $changes)['results'] ?? []));
        $out = [];
        foreach ($changes as $i => $change) {
            $result = (array) ($results[$i] ?? ['ok' => false, 'error' => 'no result']);
            $ok = (bool) ($result['ok'] ?? false);
            $out[] = ['reference' => $change['reference'], 'ok' => $ok, 'change_id' => $result['change_id'] ?? null, 'before' => $result['before'] ?? null,
                'error' => $ok ? null : mb_substr(trim(is_scalar($result['error'] ?? null) ? (string) $result['error'] : '') ?: 'Site bu değişikliği yapmadı ve sebebini bildirmedi ('.$change['type'].').', 0, 500)]
                + (isset($result['provider']) ? ['provider' => (string) $result['provider']] : []);
        }
        $okCount = count(array_filter($out, fn (array $r): bool => $r['ok']));

        return ['changes' => $out, 'applied' => $okCount, 'failed' => count($out) - $okCount, 'status' => $okCount === count($out) ? 'succeeded' : ($okCount > 0 ? 'partial' : 'failed')];
    }

    /** @return array<string, mixed> */
    private function contentDraft(ExternalWriteAction $action, CoreConnection $connection): array
    {
        $payload = (array) $action->request_payload;
        if (is_array($payload['article'] ?? null)) {
            // ADR-076: a new page with slug and SEO fields — older plugins read only title, content and reference.
            $data = $this->client->createDraft($connection, WordPressDraftWriter::payload(ArticleDraft::fromArray($payload['article'])));
        } else {
            $data = $this->client->createContentDraft($connection, (int) ($payload['object_id'] ?? 0), (string) ($payload['title'] ?? ''),
                (string) ($payload['html'] ?? ''), (string) ($payload['reference'] ?? ''));
        }
        if (! is_numeric($data['post_id'] ?? null)) {
            throw new RuntimeException('WordPress taslak kimliği dönmedi.');
        }

        return ['post_id' => (int) $data['post_id'], 'edit_url' => (string) ($data['edit_url'] ?? ''), 'preview_url' => (string) ($data['preview_url'] ?? ''), 'status' => 'succeeded'];
    }

    /** @return array<string, mixed> */
    private function contentApply(ExternalWriteAction $action, CoreConnection $connection): array
    {
        $draftId = (int) ($action->request_payload['draft_id'] ?? 0);
        if ($draftId < 1) {
            throw new RuntimeException('Önce taslak kopyayı WordPress’e gönder.');
        }
        $data = $this->client->applyContentDraft($connection, $draftId);

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
