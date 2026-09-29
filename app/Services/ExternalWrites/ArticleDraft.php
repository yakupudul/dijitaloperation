<?php

namespace App\Services\ExternalWrites;

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
