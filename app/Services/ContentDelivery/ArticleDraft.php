<?php

namespace App\Services\ContentDelivery;

use App\Models\SiteFixItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * One article (page / post) ready for delivery: to WordPress as a draft (ADR-064 / ADR-076) or into a WXR export.
 * Immutable; `with()` returns a changed copy (localized versions, operator edits).
 */
final readonly class ArticleDraft
{
    /**
     * @param  list<array{name: string, source?: string}>  $categories  `source` = the source-language term this one translates
     * @param  list<array{name: string, source?: string}>  $tags
     */
    public function __construct(
        public string $title,
        public string $html,
        public string $reference,
        public string $slug = '',
        public string $excerpt = '',
        public string $metaTitle = '',
        public string $metaDescription = '',
        public string $focusKeyword = '',
        public array $categories = [],
        public array $tags = [],
        public ?string $language = null,
        public string $postType = 'post',
        public ?CarbonImmutable $postDate = null,
        public bool $schedule = false,
        public ?string $translationKey = null,
    ) {
        if (trim($title) === '' || trim($html) === '' || trim($reference) === '') {
            throw new InvalidArgumentException('Makalenin başlığı, metni ve referansı olmalı.');
        }
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $date = $data['post_date'] ?? $data['postDate'] ?? null;

        return new self(
            title: trim((string) ($data['title'] ?? '')),
            html: (string) ($data['html'] ?? $data['content_html'] ?? $data['content'] ?? ''),
            reference: trim((string) ($data['reference'] ?? '')),
            slug: (string) ($data['slug'] ?? ''),
            excerpt: (string) ($data['excerpt'] ?? ''),
            metaTitle: (string) ($data['meta_title'] ?? $data['metaTitle'] ?? ''),
            metaDescription: (string) ($data['meta_description'] ?? $data['metaDescription'] ?? ''),
            focusKeyword: (string) ($data['focus_keyword'] ?? $data['focusKeyword'] ?? ''),
            categories: self::terms($data['categories'] ?? []),
            tags: self::terms($data['tags'] ?? []),
            language: filled($data['language'] ?? null) ? strtolower((string) $data['language']) : null,
            postType: in_array($data['post_type'] ?? $data['postType'] ?? 'post', ['post', 'page'], true) ? (string) ($data['post_type'] ?? $data['postType'] ?? 'post') : 'post',
            postDate: $date instanceof CarbonImmutable ? $date : (filled($date) ? CarbonImmutable::parse((string) $date) : null),
            schedule: (bool) ($data['schedule'] ?? false),
            translationKey: filled($data['translation_key'] ?? $data['translationKey'] ?? null) ? (string) ($data['translation_key'] ?? $data['translationKey']) : null,
        );
    }

    /**
     * An AI page proposal of the site fixes (ADR-070) as an article. SEO title / description / slug come from the proposal
     * when the operator set them, otherwise from the title and the first paragraph.
     */
    public static function fromSiteFixItem(SiteFixItem $item): self
    {
        $title = trim((string) data_get($item->proposed, 'value.title', $item->label));
        $html = (string) data_get($item->proposed, 'value.html', '');
        $firstParagraph = preg_match('/<p>(.*?)<\/p>/su', $html, $m) === 1 ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';

        return new self(
            title: $title,
            html: $html,
            reference: 'site-fix-'.$item->id,
            slug: (string) (data_get($item->proposed, 'value.slug') ?: ($item->type === 'new_page' ? Str::slug($title, '-', 'tr') : '')),
            excerpt: (string) data_get($item->proposed, 'value.excerpt', ''),
            metaTitle: (string) (data_get($item->proposed, 'value.meta_title') ?: Str::limit($title, 60, '')),
            metaDescription: (string) (data_get($item->proposed, 'value.meta_description') ?: Str::limit($firstParagraph, 155, '')),
            focusKeyword: (string) (data_get($item->proposed, 'value.focus_keyword') ?: (string) data_get($item->current, 'brief.queries.0', '')),
            postType: 'page',
        );
    }

    /** @param  array<string, mixed>  $changes */
    public function with(array $changes): self
    {
        return self::fromArray(array_merge($this->toArray(), $changes));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'title' => $this->title, 'html' => $this->html, 'reference' => $this->reference, 'slug' => $this->slug, 'excerpt' => $this->excerpt,
            'meta_title' => $this->metaTitle, 'meta_description' => $this->metaDescription, 'focus_keyword' => $this->focusKeyword,
            'categories' => $this->categories, 'tags' => $this->tags, 'language' => $this->language, 'post_type' => $this->postType,
            'post_date' => $this->postDate?->toIso8601String(), 'schedule' => $this->schedule, 'translation_key' => $this->translationKey,
        ];
    }

    /** Slug for delivery: the given one, else from the title. */
    public function effectiveSlug(): string
    {
        $slug = Str::slug($this->slug !== '' ? $this->slug : $this->title, '-', $this->language ?? 'tr');

        return mb_substr($slug, 0, 190);
    }

    /**
     * @return list<array{name: string, source?: string}>
     */
    private static function terms(mixed $terms): array
    {
        $out = [];
        foreach (array_slice(is_array($terms) ? array_values($terms) : [], 0, 10) as $term) {
            $name = trim((string) (is_array($term) ? ($term['name'] ?? '') : $term));
            if ($name === '') {
                continue;
            }
            $source = is_array($term) ? trim((string) ($term['source'] ?? '')) : '';
            $out[] = $source !== '' ? ['name' => mb_substr($name, 0, 200), 'source' => mb_substr($source, 0, 200)] : ['name' => mb_substr($name, 0, 200)];
        }

        return $out;
    }
}
