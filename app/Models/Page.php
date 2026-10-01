<?php

namespace App\Models;

use App\Services\Site\BrandMemoryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One website URL, latest version only: main content text (no header / footer / head), title, meta description,
 * H1–H3, canonical, language, category, content summary (for AI), content hash and changed_at. Re-analyzed only
 * when the hash changes.
 */
class Page extends Model
{
    public const array CATEGORIES = ['hizmet', 'blog', 'kurumsal', 'sss', 'lokasyon', 'diger'];

    /** Operator labels (Turkish). */
    public const array CATEGORY_LABELS = ['hizmet' => 'hizmet', 'blog' => 'blog', 'kurumsal' => 'kurumsal', 'sss' => 'sss', 'lokasyon' => 'lokasyon', 'diger' => 'diğer'];

    /** @var list<string> */
    protected $fillable = [
        'website_asset_id',
        'url',
        'url_hash',
        'path',
        'category',
        'category_locked',
        'category_source',
        'language',
        'title',
        'meta_description',
        'canonical',
        'h1',
        'headings',
        'content_text',
        'content_summary',
        'content_hash',
        'word_count',
        'wp_post_id',
        'wp_post_type',
        'is_indexable',
        'changed_at',
        'analyzed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'headings' => 'array',
            'word_count' => 'integer',
            'wp_post_id' => 'integer',
            'is_indexable' => 'boolean',
            'category_locked' => 'boolean',
            'changed_at' => 'immutable_datetime',
            'analyzed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        // Faz 4a: a new content version invalidates what was derived from the old one (summary, open suggestions).
        static::updated(function (Page $page): void {
            if ($page->wasChanged('content_hash') && $page->getOriginal('content_hash') !== null) {
                app(BrandMemoryService::class)->pageChanged($page);
            }
        });
    }

    /** @return BelongsTo<DigitalAsset, $this> */
    public function website(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class, 'website_asset_id');
    }

    /** @return HasMany<OfferingPage, $this> */
    public function offeringLinks(): HasMany
    {
        return $this->hasMany(OfferingPage::class);
    }

    /**
     * Heading texts in order; headings are stored as {level, text} (older rows / tests may hold plain strings).
     *
     * @return list<string>
     */
    public function headingTexts(): array
    {
        return array_values(array_filter(array_map(fn (mixed $h): string => trim(is_array($h) ? (string) ($h['text'] ?? '') : (string) (is_scalar($h) ? $h : '')),
            (array) $this->headings), fn (string $t): bool => $t !== ''));
    }

    /**
     * "H2 Başlık" lines (H2–H4) — the page skeleton without its text.
     *
     * @return list<string>
     */
    public function outline(): array
    {
        return array_values(array_filter(array_map(function (mixed $h): ?string {
            if (! is_array($h)) {
                return is_scalar($h) && trim((string) $h) !== '' ? 'H2 '.trim((string) $h) : null;
            }
            $level = (int) ($h['level'] ?? 0);

            return $level >= 2 && $level <= 4 && trim((string) ($h['text'] ?? '')) !== '' ? 'H'.$level.' '.trim((string) $h['text']) : null;
        }, (array) $this->headings)));
    }

    public function categoryLabel(): string
    {
        return self::CATEGORY_LABELS[(string) $this->category] ?? '—';
    }
}
