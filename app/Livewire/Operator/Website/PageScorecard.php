<?php

namespace App\Livewire\Operator\Website;

use App\Models\DigitalAsset;
use App\Services\Measurement\PageScorecardReader;
use App\Support\Permissions;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Website › Sayfa Karnesi: every measured page with Google, GA4 (incl. channel mix), Google Ads, indexing,
 * speed and open SEO tasks side by side for the last 28 days.
 */
final class PageScorecard extends Component
{
    #[Locked]
    public int $websiteId;

    public string $search = '';

    public ?string $openKey = null;

    public function mount(int $websiteId): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->can(Permissions::ACCESS_APP), 403);
        $this->websiteId = $websiteId;
    }

    public function toggle(string $key): void
    {
        $this->openKey = $this->openKey === $key ? null : $key;
    }

    public function render(PageScorecardReader $reader): View
    {
        $site = DigitalAsset::query()->where('type', 'website')->findOrFail($this->websiteId);

        return view('livewire.operator.website.page-scorecard', [
            'card' => $reader->read($site, trim($this->search)),
        ]);
    }
}
