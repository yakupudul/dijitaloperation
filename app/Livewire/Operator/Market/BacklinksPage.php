<?php

namespace App\Livewire\Operator\Market;

use App\Enums\CustomerStatus;
use App\Models\Brand;
use App\Models\Intel\BrandIntelSetting;
use App\Services\Intel\BacklinkEngine;
use App\Services\Intel\BacklinkLinkChecker;
use App\Services\Intel\DataForSeoTaskQueue;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Pazar › Backlink fırsatları (Faz 8c): the brand's link profile vs competitors, new / lost referring domains
 * and opportunities with an outreach status. Refreshing is paid and admin-only; statuses are everyone's work.
 */
#[Layout('operator.layouts.app')]
#[Title('Backlink fırsatları')]
final class BacklinksPage extends Component
{
    #[Url]
    public ?int $brand = null;

    #[Url]
    public string $status = 'open';

    /** @var array<int, array{link_url?: string, contact?: string, note?: string}> */
    public array $edit = [];

    public string $message = '';

    public string $error = '';

    public function refresh(BacklinkEngine $engine): void
    {
        $this->admin();
        try {
            $stats = $engine->refresh($this->selectedBrand() ?? abort(404), auth()->user());
            $this->error = '';
            $this->message = sprintf('Yenilendi: %d yönlendiren alan adı (%d yeni, %d kayıp), %d fırsat; %.3f USD.', $stats['referring_domains'], $stats['new'], $stats['lost'], $stats['opportunities'], $stats['spent_usd']);
        } catch (ValidationException $exception) {
            $this->message = '';
            $this->error = (string) collect($exception->errors())->flatten()->first();
        }
    }

    public function toggleEnabled(): void
    {
        $this->admin();
        $settings = BrandIntelSetting::for($this->selectedBrand() ?? abort(404));
        $settings->forceFill(['backlinks_enabled' => ! $settings->backlinks_enabled, 'updated_by' => auth()->id()])->save();
        $this->message = $settings->backlinks_enabled ? 'Aylık otomatik yenileme açıldı.' : 'Otomatik yenileme kapatıldı.';
    }

    public function addCitations(BacklinkEngine $engine): void
    {
        $added = $engine->syncCitations($this->selectedBrand() ?? abort(404), DB::table('backlink_referring_domains')->where('brand_id', $this->brand)->whereNull('lost_on')->pluck('domain')->all());
        $this->message = $added.' rehber / atıf fırsatı eklendi (ücretsiz).';
    }

    public function setStatus(int $id, string $status): void
    {
        abort_unless(array_key_exists($status, BacklinkEngine::STATUSES), 422);
        $this->opportunity($id);
        DB::table('backlink_opportunities')->where('id', $id)->update(['status' => $status, 'updated_by' => auth()->id(), 'updated_at' => now()]);
    }

    public function saveDetails(int $id): void
    {
        $this->opportunity($id);
        $data = validator($this->edit[$id] ?? [], ['link_url' => ['nullable', 'url', 'max:500'], 'contact' => ['nullable', 'string', 'max:255'], 'note' => ['nullable', 'string', 'max:2000']])->validate();
        DB::table('backlink_opportunities')->where('id', $id)->update([
            'link_url' => $data['link_url'] ?? null, 'contact' => $data['contact'] ?? null, 'note' => $data['note'] ?? null,
            'updated_by' => auth()->id(), 'updated_at' => now(),
        ]);
        $this->message = 'Kaydedildi.';
    }

    public function checkLink(int $id, BacklinkLinkChecker $checker): void
    {
        $found = $checker->check($this->opportunity($id));
        $this->message = match ($found) {
            true => 'Link sayfada bulundu.',
            false => 'Sayfada markanın sitesine link yok.',
            default => 'Sayfa okunamadı ya da link adresi yok.',
        };
    }

    public function render(BacklinkEngine $engine, DataForSeoTaskQueue $queue): View
    {
        $brands = Brand::query()->whereHas('customer', fn ($q) => $q->where('status', CustomerStatus::Active->value))->orderBy('name')->get(['id', 'name']);
        $this->brand ??= $brands->first()?->id;
        $brand = $this->selectedBrand();
        $snapshots = $brand !== null ? DB::table('backlink_snapshots')->where('brand_id', $brand->id)->orderByDesc('observed_on')->orderByDesc('id')->get() : collect();
        $latest = $snapshots->groupBy('target')->map(fn ($rows) => ['now' => $rows->first(), 'before' => $rows->skip(1)->first()]);
        $opportunities = $brand !== null ? DB::table('backlink_opportunities')->where('brand_id', $brand->id)
            ->when($this->status === 'open', fn ($q) => $q->whereIn('status', ['new', 'contacted', 'waiting']))
            ->when(! in_array($this->status, ['open', 'all'], true), fn ($q) => $q->where('status', $this->status))
            ->orderByRaw("case when source = 'intersection' then 0 else 1 end")->orderByDesc('competitors_linking')->orderByDesc('rank')->limit(200)->get() : collect();
        foreach ($opportunities as $row) {
            $this->edit[$row->id] ??= ['link_url' => (string) $row->link_url, 'contact' => (string) $row->contact, 'note' => (string) $row->note];
        }

        return view('livewire.operator.market.backlinks', [
            'brands' => $brands,
            'settings' => $brand !== null ? BrandIntelSetting::for($brand) : null,
            'latest' => $latest,
            'competitors' => $brand !== null ? $engine->competitorDomains($brand) : [],
            'estimate' => $brand !== null ? $engine->estimate($brand) : 0.0,
            'spent' => $brand !== null ? $queue->spentThisMonth((int) $brand->id) : 0.0,
            'newDomains' => $brand !== null ? DB::table('backlink_referring_domains')->where('brand_id', $brand->id)->whereNull('lost_on')->where('created_at', '>=', now()->subDays(45))->orderByDesc('rank')->limit(30)->get() : collect(),
            'lostDomains' => $brand !== null ? DB::table('backlink_referring_domains')->where('brand_id', $brand->id)->whereNotNull('lost_on')->where('lost_on', '>=', now()->subDays(90)->toDateString())->orderByDesc('rank')->limit(30)->get() : collect(),
            'opportunities' => $opportunities,
            'statuses' => BacklinkEngine::STATUSES,
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
        ]);
    }

    private function opportunity(int $id): object
    {
        return DB::table('backlink_opportunities')->where('id', $id)->where('brand_id', $this->brand)->first() ?? abort(404);
    }

    private function selectedBrand(): ?Brand
    {
        return $this->brand !== null ? Brand::query()->find($this->brand) : null;
    }

    private function admin(): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
    }
}
