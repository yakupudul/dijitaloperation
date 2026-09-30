<?php

namespace App\Livewire\Concerns;

use App\Models\ServiceCatalogItem;
use App\Services\Queries\KeywordInsights;
use Livewire\Attributes\Locked;

/**
 * Keyword impact preview while a matching keyword is typed for a service (`newKeyword.{serviceId}` inputs): "Bu kelime N
 * sorgu yakalayacak · …" with example queries, computed before the keyword is saved (KeywordInsights::impact()).
 */
trait PreviewsKeywordImpact
{
    /** @var array<string, mixed> the current preview ('source' row|draft; empty = none) */
    #[Locked]
    public array $impact = [];

    public function updatedNewKeyword(mixed $value, string|int|null $key = null): void
    {
        if ($key !== null && ctype_digit((string) $key)) {
            $this->previewKeyword((int) $key, (string) $value);
        }
    }

    public function impactPage(int $page): void
    {
        $service = isset($this->impact['service']) ? ServiceCatalogItem::query()->find((int) $this->impact['service']) : null;
        if ($service !== null) {
            $this->impact = ['source' => $this->impact['source'] ?? 'row'] + app(KeywordInsights::class)->impact($service, (string) $this->impact['label'], max(0, $page));
        }
    }

    protected function previewKeyword(int $serviceId, string $label, string $source = 'row'): void
    {
        $service = trim($label) !== '' ? ServiceCatalogItem::query()->find($serviceId) : null;
        $this->impact = $service === null ? [] : ['source' => $source] + app(KeywordInsights::class)->impact($service, $label);
    }
}
