<?php

namespace App\Services\ExternalWrites;

use App\Models\BrandExpert;
use App\Models\CoreConnection;
use App\Models\ExternalWriteAction;
use App\Services\Integrations\WordPress\SiteDidNotTakeWork;
use App\Services\Integrations\WordPress\WordPressConnectorClient;
use App\Services\Site\ArticleFaqSchema;
use App\Services\Site\ContentPosts;
use RuntimeException;
use Throwable;

/**
 * ADR-064 (2): sends an operator-approved draft skeleton (title, outline, reference) to WordPress via the MoxDOP
 * Connector. Nothing is published. Undo trashes the draft only while it is still a MoxDOP draft.
 *
 * ADR-076: `payload()` turns a full article (body, slug, excerpt, date, categories, tags, SEO fields, Polylang language,
 * translation_of) into the connector's /drafts payload; `article_drafts` actions send the source language first and
 * then each translation linked to it. Older plugins ignore the extra fields.
 */
final class WordPressDraftWriter
{
    public function __construct(private readonly WordPressConnectorClient $client) {}

    /**
     * The connector /drafts payload of one article. Empty optional fields are left out; `content_html` keeps older
     * plugins working. With the brand (1.11.0, older plugins ignore both): `schema` is the article's FAQPage built from
     * its question-and-answer headings, `author` the WordPress user of the brand's expert author.
     *
     * @return array<string, mixed>
     */
    public static function payload(ArticleDraft $article, ?int $translationOf = null, ?int $brandId = null): array
    {
        $seo = array_filter(['title' => mb_substr($article->metaTitle, 0, 200), 'description' => mb_substr($article->metaDescription, 0, 400), 'focus_keyword' => mb_substr($article->focusKeyword, 0, 100)],
            fn (string $v): bool => trim($v) !== '');

        return array_filter([
            'title' => mb_substr($article->title, 0, 200),
            'content_html' => $article->html,
            'post_type' => $article->postType,
            'excerpt' => mb_substr($article->excerpt, 0, 1000),
            'reference' => $article->reference,
            'slug' => $article->effectiveSlug(),
            'post_date' => $article->postDate?->utc()->toIso8601String(),
            // Scheduling only when the operator chose a future date; otherwise the post stays a draft.
            'schedule' => $article->schedule && $article->postDate !== null && $article->postDate->isFuture() ? true : null,
            'categories' => $article->postType === 'post' ? $article->categories : [],
            'tags' => $article->postType === 'post' ? $article->tags : [],
            'seo' => $seo,
            'language' => $article->language,
            'translation_of' => $translationOf,
            'translation_key' => $article->translationKey,
            'schema' => $brandId !== null ? ArticleFaqSchema::fromHtml($article->html, $article->language) : null,
            'author' => $brandId !== null ? BrandExpert::authorOf($brandId)?->wp_author : null,
            // 1.14.0 (older plugins ignore it): an image already in the site's media library as the featured image.
            'featured_media_id' => $article->featuredMediaId,
        ], fn (mixed $v): bool => $v !== null && $v !== '' && $v !== []);
    }

    /** @return array<string, mixed> */
    public function apply(ExternalWriteAction $action): array
    {
        if ($action->action === ExternalWriteAction::ACTION_ARTICLE_DRAFTS) {
            return $this->articles($action);
        }
        if ($action->action === ExternalWriteAction::ACTION_POST_DATE) {
            return $this->dates($action, (string) data_get($action->request_payload, 'date'), (bool) data_get($action->request_payload, 'schedule'));
        }
        $data = $this->client->createDraft($this->connection((int) $action->digital_asset_id), (array) $action->request_payload['draft']);
        if (! is_numeric($data['post_id'] ?? null)) {
            throw new RuntimeException('WordPress taslak kimliği dönmedi.');
        }

        return ['post_id' => (int) $data['post_id'], 'edit_url' => (string) ($data['edit_url'] ?? ''), 'preview_url' => (string) ($data['preview_url'] ?? ''), 'wp_status' => (string) ($data['status'] ?? 'draft'), 'status' => 'succeeded'];
    }

    /**
     * ADR-076: source language first; each translation is created with translation_of = the source post. A failed
     * language does not stop the others; the created posts stay listed so undo can trash them.
     *
     * @return array<string, mixed>
     */
    private function articles(ExternalWriteAction $action): array
    {
        $connection = $this->connection((int) $action->digital_asset_id);
        $posts = [];
        $sourceId = null;
        foreach (array_values((array) ($action->request_payload['drafts'] ?? [])) as $index => $entry) {
            $draft = (array) ($entry['draft'] ?? []);
            $language = $entry['language'] ?? null;
            if ($index > 0) {
                if ($sourceId === null) {
                    $posts[] = ['language' => $language, 'ok' => false, 'error' => 'Kaynak dildeki taslak oluşmadığı için çeviri gönderilmedi.'];

                    continue;
                }
                $draft['translation_of'] = $sourceId;
            }
            try {
                $data = $this->client->createDraft($connection, $draft);
                if (! is_numeric($data['post_id'] ?? null)) {
                    throw new RuntimeException('WordPress taslak kimliği dönmedi.');
                }
                $postId = (int) $data['post_id'];
                if ($index === 0) {
                    $sourceId = $postId;
                }
                $linked = $index === 0 || $language === null || (int) (((array) ($data['translations'] ?? []))[$language] ?? 0) === $postId;
                $posts[] = ['language' => $language, 'ok' => true, 'post_id' => $postId, 'edit_url' => (string) ($data['edit_url'] ?? ''), 'preview_url' => (string) ($data['preview_url'] ?? ''),
                    'wp_status' => (string) ($data['status'] ?? 'draft'), 'translation_of' => $index > 0 ? $sourceId : null, 'translation_linked' => $linked];
            } catch (SiteDidNotTakeWork $exception) {
                if ($index === 0) {
                    // Nothing reached the site yet: the whole write waits for it (ExecuteExternalWriteJob sends it again).
                    throw $exception;
                }
                $posts[] = ['language' => $language, 'ok' => false, 'error' => mb_substr($exception->getMessage(), 0, 300)];
            } catch (Throwable $exception) {
                $posts[] = ['language' => $language, 'ok' => false, 'error' => mb_substr($exception->getMessage(), 0, 300)];
            }
        }
        $ok = count(array_filter($posts, fn (array $p): bool => $p['ok']));
        if ($ok === 0) {
            throw new RuntimeException((string) ($posts[0]['error'] ?? 'WordPress taslağı oluşturulamadı.'));
        }

        return ['posts' => $posts, 'post_id' => $sourceId, 'created' => $ok, 'failed' => count($posts) - $ok, 'status' => $ok === count($posts) ? 'succeeded' : 'partial'];
    }

    /**
     * 1.14.0: one date for every language post of the article; a failed language does not stop the others. Each
     * post's previous state is kept for undo.
     *
     * @return array<string, mixed>
     */
    private function dates(ExternalWriteAction $action, string $date, bool $schedule): array
    {
        $connection = $this->connection((int) $action->digital_asset_id);
        $posts = [];
        foreach ((array) data_get($action->request_payload, 'posts', []) as $language => $postId) {
            try {
                $data = $this->client->changePostDate($connection, (int) $postId, $date, $schedule);
                ContentPosts::remember((int) $action->digital_asset_id, (int) $postId, $data);
                $posts[] = ['language' => (string) $language, 'ok' => true, 'post_id' => (int) $postId, 'status' => (string) ($data['status'] ?? ''),
                    'date_gmt' => $data['date_gmt'] ?? null, 'previous' => array_intersect_key((array) ($data['previous'] ?? []), array_flip(['status', 'date_gmt']))];
            } catch (Throwable $exception) {
                $posts[] = ['language' => (string) $language, 'ok' => false, 'post_id' => (int) $postId, 'error' => mb_substr($exception->getMessage(), 0, 300)];
            }
        }
        $ok = count(array_filter($posts, fn (array $p): bool => $p['ok']));
        if ($ok === 0) {
            throw new RuntimeException((string) ($posts[0]['error'] ?? 'Yazının tarihi değiştirilemedi.'));
        }

        return ['posts' => $posts, 'status' => $ok === count($posts) ? 'succeeded' : 'partial'];
    }

    /** @return array<string, mixed> */
    public function undo(ExternalWriteAction $action): array
    {
        if ($action->action === ExternalWriteAction::ACTION_POST_DATE) {
            $connection = $this->connection((int) $action->digital_asset_id);
            $restored = [];
            foreach ((array) data_get($action->result, 'posts', []) as $post) {
                if (! ($post['ok'] ?? false)) {
                    continue;
                }
                // A draft that had no date of its own keeps the planned one but goes back to being a draft.
                $previous = (array) ($post['previous'] ?? []);
                $date = (string) ($previous['date_gmt'] ?? $post['date_gmt'] ?? data_get($action->request_payload, 'date'));
                $data = $this->client->changePostDate($connection, (int) $post['post_id'], $date, ($previous['status'] ?? '') === 'future');
                ContentPosts::remember((int) $action->digital_asset_id, (int) $post['post_id'], $data);
                $restored[] = (int) $post['post_id'];
            }

            return ['restored' => count($restored), 'post_ids' => $restored];
        }
        if ($action->action === ExternalWriteAction::ACTION_ARTICLE_DRAFTS) {
            $connection = $this->connection((int) $action->digital_asset_id);
            $removed = [];
            $errors = [];
            // Translations first, the source last. A post published in the meantime is refused by the plugin and kept.
            foreach (array_reverse((array) ($action->result['posts'] ?? [])) as $post) {
                if (($post['ok'] ?? false) && (int) ($post['post_id'] ?? 0) > 0) {
                    try {
                        $this->client->trashDraft($connection, (int) $post['post_id']);
                        $removed[] = (int) $post['post_id'];
                    } catch (Throwable $exception) {
                        $errors[] = '#'.$post['post_id'].': '.mb_substr($exception->getMessage(), 0, 150);
                    }
                }
            }
            if ($removed === [] && $errors !== []) {
                throw new RuntimeException('Taslaklar çöpe taşınamadı ('.implode('; ', $errors).').');
            }

            return ['removed' => count($removed), 'post_ids' => $removed, 'errors' => $errors];
        }
        $postId = (int) ($action->result['post_id'] ?? 0);
        if ($postId < 1) {
            return ['removed' => 0];
        }
        $this->client->trashDraft($this->connection((int) $action->digital_asset_id), $postId);

        return ['removed' => 1, 'post_id' => $postId];
    }

    /** ADR-076: rich drafts (categories, SEO fields, language, translation link) need plugin ≥ rich_drafts_min_plugin_version. */
    public function richConnection(int $siteId): CoreConnection
    {
        $connection = $this->connection($siteId);
        $version = (string) data_get($connection->config, 'plugin_version', '0.0.0');
        $minimum = (string) config('moxdop-wordpress.rich_drafts_min_plugin_version', '1.5.0');
        if (version_compare($version, $minimum, '<')) {
            throw new RuntimeException('WordPress Connector eklentisi '.$version.'; dil, kategori ve SEO alanlı taslak için en az '.$minimum.' gerekli. Eklentiyi güncelle.');
        }

        return $connection;
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
