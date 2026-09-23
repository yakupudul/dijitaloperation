<?php

namespace App\Services\ExternalWrites;

use App\Models\CoreConnection;
use App\Models\ExternalWriteAction;
use App\Models\SeoTask;
use App\Services\Integrations\WordPress\WordPressConnectorClient;
use RuntimeException;

/**
 * ADR-064 (2): turns an SEO Görevleri content brief into a WordPress draft skeleton (title, H2 outline,
 * queries to cover, internal links) via the MoxDOP Connector. The writer fills in the text; nothing is
 * published. Undo trashes the draft only while it is still a MoxDOP draft.
 */
final class WordPressDraftWriter
{
    public function __construct(private readonly WordPressConnectorClient $client) {}

    /** @return array{title: string, content_html: string, post_type: string, excerpt: string, reference: string} */
    public static function draftFromTask(SeoTask $task): array
    {
        $brief = is_array($task->content_brief) ? $task->content_brief : [];
        if ($brief === [] || blank($brief['page_title'] ?? null)) {
            throw new RuntimeException('Bu görevde içerik briefi yok.');
        }
        $e = static fn (mixed $text): string => htmlspecialchars((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = '<!-- MoxDOP taslağı: SEO görevi #'.$task->id.'. Yayınlamadan önce metni yaz ve kontrol et. -->'."\n";
        $html .= '<p><em>Bu taslak MoxDOP tarafından içerik briefinden oluşturuldu. Aşağıdaki başlıkların altını doldur.</em></p>'."\n";
        foreach ((array) ($brief['h2_outline'] ?? []) as $heading) {
            $html .= '<h2>'.$e($heading).'</h2>'."\n".'<p></p>'."\n";
        }
        $queries = array_values(array_filter((array) ($brief['queries'] ?? [])));
        if ($queries !== []) {
            $html .= '<!-- Kapsanacak aramalar: '.$e(implode(', ', $queries)).' -->'."\n";
        }
        $links = array_values(array_filter((array) ($brief['internal_links'] ?? [])));
        if ($links !== []) {
            $html .= '<!-- İç link verilecek sayfalar: '.$e(implode(', ', $links)).' -->'."\n";
        }
        if (! empty($brief['target_words'])) {
            $html .= '<!-- Hedef uzunluk: ~'.(int) $brief['target_words'].' kelime -->'."\n";
        }

        return [
            'title' => mb_substr((string) $brief['page_title'], 0, 200),
            'content_html' => $html,
            'post_type' => in_array($brief['page_type'] ?? '', ['service', 'location'], true) ? 'page' : 'post',
            'excerpt' => mb_substr((string) $task->reason, 0, 300),
            'reference' => 'seo-task-'.$task->id,
        ];
    }

    /** @return array<string, mixed> */
    public function apply(ExternalWriteAction $action): array
    {
        $data = $this->client->createDraft($this->connection((int) $action->digital_asset_id), (array) $action->request_payload['draft']);
        if (! is_numeric($data['post_id'] ?? null)) {
            throw new RuntimeException('WordPress taslak kimliği dönmedi.');
        }

        return ['post_id' => (int) $data['post_id'], 'edit_url' => (string) ($data['edit_url'] ?? ''), 'preview_url' => (string) ($data['preview_url'] ?? ''), 'wp_status' => (string) ($data['status'] ?? 'draft'), 'status' => 'succeeded'];
    }

    /** @return array<string, mixed> */
    public function undo(ExternalWriteAction $action): array
    {
        $postId = (int) ($action->result['post_id'] ?? 0);
        if ($postId < 1) {
            return ['removed' => 0];
        }
        $this->client->trashDraft($this->connection((int) $action->digital_asset_id), $postId);

        return ['removed' => 1, 'post_id' => $postId];
    }

    public function connection(int $siteId): CoreConnection
    {
        $connection = CoreConnection::query()->with('credential')
            ->where('digital_asset_id', $siteId)
            ->where('type', 'wordpress_connector')
            ->where('enabled', true)
            ->first();
        if ($connection === null || data_get($connection->config, 'pairing_state') !== 'paired') {
            throw new RuntimeException('Bu sitede eşleştirilmiş WordPress Connector yok.');
        }
        $version = (string) data_get($connection->config, 'plugin_version', '0.0.0');
        if (version_compare($version, (string) config('moxdop-external-writes.wordpress.min_plugin_version', '1.2.0'), '<')) {
            throw new RuntimeException('WordPress Connector eklentisi '.$version.'; taslak için en az '.config('moxdop-external-writes.wordpress.min_plugin_version', '1.2.0').' gerekli. Eklentiyi güncelle.');
        }

        return $connection;
    }
}
