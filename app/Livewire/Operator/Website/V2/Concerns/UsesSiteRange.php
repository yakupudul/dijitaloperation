<?php

namespace App\Livewire\Operator\Website\V2\Concerns;

use App\Services\Site\Analysis\SiteRange;
use Livewire\Attributes\Locked;

/**
 * A tab that reads Search Console / GA4: the website screen's date picker range arrives as `range` (days, start, end,
 * compare) and is bound for the request before the readers run, so every tab shows the same period.
 */
trait UsesSiteRange
{
    /** @var array{days?: int, start?: ?string, end?: ?string, compare?: string} */
    #[Locked]
    public array $range = [];

    protected function siteRange(): SiteRange
    {
        return SiteRange::from((int) ($this->range['days'] ?? 28), $this->range['start'] ?? null, $this->range['end'] ?? null, $this->range['compare'] ?? null)->bind();
    }

    protected function hasScreenRange(): bool
    {
        return $this->range !== [];
    }
}
