<?php

namespace App\Models;

use App\Enums\SeoTaskStatus;
use App\Enums\SeoTaskType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A concrete SEO task produced by the rule engine (optionally enriched by the LLM).
 * Deliberately separate from Task / Finding / Recommendation.
 */
class SeoTask extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<DigitalAsset, $this> */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    /** @return BelongsTo<BrandOffering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(BrandOffering::class, 'brand_offering_id');
    }

    /** @return BelongsTo<SeoPlan, $this> */
    public function firstSeenPlan(): BelongsTo
    {
        return $this->belongsTo(SeoPlan::class, 'first_seen_plan_id');
    }

    /** @return BelongsTo<SeoPlan, $this> */
    public function lastSeenPlan(): BelongsTo
    {
        return $this->belongsTo(SeoPlan::class, 'last_seen_plan_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** @param Builder<SeoTask> $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', SeoTaskStatus::Open->value);
    }

    public function isOpen(): bool
    {
        return $this->status === SeoTaskStatus::Open;
    }

    public function severityLabel(): string
    {
        return match ($this->severity) {
            'critical' => 'Kritik',
            'high' => 'Yüksek',
            'medium' => 'Orta',
            default => 'Düşük',
        };
    }

    public function severityColor(): string
    {
        return match ($this->severity) {
            'critical', 'high' => 'error',
            'medium' => 'warning',
            default => 'light',
        };
    }

    /** Plain-language business impact: search upside for content work, severity for fixes. */
    public function impactLabel(): string
    {
        $clicks = (float) ($this->estimated_extra_clicks ?? 0);
        if ($this->severity === 'critical' || $clicks >= 50) {
            return 'Yüksek';
        }
        if ($this->severity === 'high' || $clicks >= 15) {
            return 'Orta';
        }

        return 'Düşük';
    }

    /** Rough effort so the operator can plan a week: new pages cost more than metadata edits. */
    public function effortLabel(): string
    {
        return match ($this->type) {
            SeoTaskType::Create => $this->is_new_page ? 'Yüksek' : 'Orta',
            SeoTaskType::Strengthen => 'Orta',
            SeoTaskType::Fix => ($this->evidence['template_level'] ?? false) ? 'Orta' : 'Düşük',
            default => 'Düşük',
        };
    }

    /** Writer-ready brief as plain text (copied from the UI into a doc or the CMS). */
    public function briefText(): ?string
    {
        $brief = is_array($this->content_brief) ? $this->content_brief : null;
        if ($brief === null) {
            return null;
        }
        $lines = [
            'Görev: '.$this->title,
            'Sayfa başlığı (title/H1): '.($brief['page_title'] ?? ''),
            'Hedef URL: '.($brief['target_url'] ?? $this->target_url ?? ''),
            'Tür: '.($brief['page_type'] ?? '').' · '.(($brief['decision'] ?? '') === 'existing_page_section' ? 'mevcut sayfaya bölüm' : 'yeni sayfa').' · ~'.($brief['target_words'] ?? '').' kelime',
            '',
            'H2 taslağı:',
        ];
        foreach ($brief['h2_outline'] ?? [] as $index => $heading) {
            $lines[] = ($index + 1).'. '.$heading;
        }
        $lines[] = '';
        $lines[] = 'Kapsanacak sorgular: '.implode(', ', $brief['queries'] ?? []);
        if (! empty($brief['internal_links'])) {
            $lines[] = 'İç linkler: '.implode(', ', $brief['internal_links']);
        }
        $lines[] = '';
        $lines[] = 'Yapılacaklar:';
        foreach ($this->checklist ?? [] as $step) {
            $lines[] = '- '.$step;
        }

        return implode("\n", $lines);
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'type' => SeoTaskType::class,
            'status' => SeoTaskStatus::class,
            'priority_score' => 'float',
            'estimated_extra_clicks' => 'float',
            'evidence' => 'array',
            'checklist' => 'array',
            'content_brief' => 'array',
            'llm_payload' => 'array',
            'is_new_page' => 'boolean',
            'resolved_at' => 'datetime',
            'outcome' => 'array',
            'measured_at' => 'datetime',
        ];
    }
}
