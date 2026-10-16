<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One topic of a site's service map (yakup, 2026-10-16): the main page or one question, its type, the page that answers
 * it today (coverage missing / weak / covered) and the content idea made of it (status open → idea → sent → live).
 */
class ContentTopic extends Model
{
    /** Topic types in writing order (the order a service's map is closed in). */
    public const array TYPES = ['pillar', 'decision', 'process', 'fear', 'local', 'seasonal', 'abroad'];

    public const array TYPE_LABELS = ['pillar' => 'Ana sayfa', 'decision' => 'Karar sorusu', 'process' => 'Süreç ve sonrası', 'fear' => 'Korku ve itiraz',
        'local' => 'Yerel', 'seasonal' => 'Mevsim', 'abroad' => 'Yurt dışı hasta'];

    /** The idea angle each type is written with (ContentPlanner::ANGLES). */
    public const array ANGLES = ['pillar' => 'decision', 'decision' => 'decision', 'process' => 'process', 'fear' => 'objection', 'local' => 'local',
        'seasonal' => 'insight', 'abroad' => 'process'];

    public const array COVERAGES = ['missing', 'weak', 'covered'];

    /** open: nothing made yet · idea: an idea waits · sent: the article / update went to the site · live: the page is on the site · dropped. */
    public const array STATUSES = ['open', 'idea', 'sent', 'live', 'dropped'];

    /** @var list<string> */
    protected $fillable = ['website_asset_id', 'brand_id', 'service_id', 'cluster_id', 'type', 'question', 'title', 'coverage', 'status', 'season_month', 'priority',
        'position', 'page_id', 'suggestion_id', 'evidence'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['evidence' => 'array', 'priority' => 'float', 'season_month' => 'integer', 'position' => 'integer'];
    }

    /** Done for the map: answered by a sufficient page, sent or live. */
    public function done(): bool
    {
        return $this->coverage === 'covered' || in_array($this->status, ['sent', 'live', 'dropped'], true);
    }

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /** @return BelongsTo<Suggestion, $this> */
    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(Suggestion::class);
    }
}
