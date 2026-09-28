<?php

namespace App\Models;

use App\Services\ContentDelivery\ArticleDraft;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A written article of the content studio (Faz 4): the source-language version (source_article_id null) or one of its
 * localized versions. `payload` is ArticleDraft::toArray(); delivery goes through the compliance gate (ADR-076).
 */
class ContentArticle extends Model
{
    public const array STATUS_LABELS = [
        'writing' => 'Yazılıyor', 'ready' => 'Hazır', 'needs_fix' => 'Uyum sorunu var', 'failed' => 'Yazılamadı',
        'sent' => 'WordPress’e gönderildi', 'exported' => 'XML indirildi', 'published' => 'Yayında',
    ];

    /** Statuses whose text may be delivered (WordPress draft / WXR). */
    public const array DELIVERABLE = ['ready', 'sent', 'exported'];

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'array', 'compliance' => 'array', 'quality' => 'array', 'translate_to' => 'array', 'scheduled_at' => 'datetime', 'exported_at' => 'datetime'];
    }

    /** @return BelongsTo<ContentIdea, $this> */
    public function idea(): BelongsTo
    {
        return $this->belongsTo(ContentIdea::class, 'content_idea_id');
    }

    /** @return BelongsTo<ContentArticle, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_article_id');
    }

    /** @return HasMany<ContentArticle, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(self::class, 'source_article_id')->orderBy('language');
    }

    /** @return BelongsTo<ExternalWriteAction, $this> */
    public function writeAction(): BelongsTo
    {
        return $this->belongsTo(ExternalWriteAction::class, 'external_write_action_id');
    }

    /** @return BelongsTo<DigitalAsset, $this> */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function reference(): string
    {
        return $this->source_article_id === null ? 'moxdop-article-'.$this->id : 'moxdop-article-'.$this->source_article_id.'-'.($this->language ?? $this->id);
    }

    public function hasDraft(): bool
    {
        return filled(data_get($this->payload, 'html')) && filled(data_get($this->payload, 'title'));
    }

    /** The article as a delivery draft (reference, translation key, language and post date filled in; always a draft). */
    public function draft(): ArticleDraft
    {
        return ArticleDraft::fromArray(array_merge((array) $this->payload, [
            'reference' => $this->reference(),
            'translation_key' => $this->translation_key,
            'language' => $this->language,
            'post_date' => $this->scheduled_at !== null ? CarbonImmutable::parse($this->scheduled_at) : null,
            'schedule' => false,
        ]));
    }

    /** @return list<array<string, mixed>> */
    public function blockingViolations(): array
    {
        return array_values(array_filter((array) $this->compliance, fn ($v): bool => is_array($v) && (bool) ($v['blocking'] ?? false)));
    }
}
