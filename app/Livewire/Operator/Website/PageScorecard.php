<?php

namespace App\Livewire\Operator\Website;

use App\Models\DigitalAsset;
use App\Models\WebsiteUrlAudit;
use App\Models\WebsiteUrlVerdict;
use App\Services\Website\UrlAudit\UrlAuditService;
use App\Support\Permissions;
use App\Support\ServiceScope;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Website › Sayfa Karnesi (Faz 5 URL karnesi): every document URL of the site — crawled, sitemap-only, WordPress and
 * measured pages — with one verdict (Düzelt / Birleştir / Güçlendir / Dizinden çıkar / Kontrol et / Sorun yok),
 * its reason, solution and action, next to the 28-day Google, GA4 (channel mix), Google Ads, indexing and speed
 * metrics. Rows are precomputed (website_url_verdicts); "Yenile" recomputes them in the queue.
 */
final class PageScorecard extends Component
{
    use WithPagination;

    public const int PER_PAGE = 50;

    #[Locked]
    public int $websiteId;

    #[Url(as: 'url_q')]
    public string $search = '';

    #[Url(as: 'karar')]
    public string $verdict = '';

    public ?int $openId = null;

    public string $message = '';

    public string $tone = 'success';

    public function mount(int $websiteId): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->can(Permissions::ACCESS_APP), 403);
        $this->websiteId = $websiteId;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'verdict'], true)) {
            $this->resetPage('urls');
            $this->openId = null;
        }
    }

    public function filter(string $verdict): void
    {
        $this->verdict = $verdict !== '' && isset(WebsiteUrlVerdict::VERDICTS[$verdict]) && $this->verdict !== $verdict ? $verdict : '';
        $this->resetPage('urls');
        $this->openId = null;
    }

    public function toggle(int $id): void
    {
        $this->openId = $this->openId === $id ? null : $id;
    }

    public function refresh(UrlAuditService $audit): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->can(Permissions::ACCESS_APP), 403);
        $result = $audit->queue($this->site(), auth()->user());
        $this->message = $result['message'];
        $this->tone = $result['ok'] ? 'success' : 'error';
    }

    public function render(ServiceScope $scope): View
    {
        $site = $this->site();
        $served = $scope->isAssetOperational($site->id);
        $audit = WebsiteUrlAudit::query()->where('digital_asset_id', $site->id)->first();
        $rows = null;
        $open = null;
        if ($served) {
            $query = WebsiteUrlVerdict::query()->where('digital_asset_id', $site->id)
                ->when($this->verdict !== '', fn ($q) => $q->where('verdict', $this->verdict))
                ->when(trim($this->search) !== '', fn ($q) => $q->where('url', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%'))
                ->orderByDesc('priority')->orderBy('path');
            $rows = $query->paginate(self::PER_PAGE, pageName: 'urls');
            $open = $this->openId !== null ? WebsiteUrlVerdict::query()->where('digital_asset_id', $site->id)->find($this->openId) : null;
        }

        return view('livewire.operator.website.page-scorecard', [
            'site' => $site, 'served' => $served, 'audit' => $audit, 'rows' => $rows, 'open' => $open,
            'verdicts' => WebsiteUrlVerdict::VERDICTS,
        ]);
    }

    private function site(): DigitalAsset
    {
        return DigitalAsset::query()->where('type', 'website')->findOrFail($this->websiteId);
    }
}
