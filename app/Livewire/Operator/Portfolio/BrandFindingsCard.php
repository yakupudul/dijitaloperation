<?php

namespace App\Livewire\Operator\Portfolio;

use App\Livewire\Concerns\AdminOnly;
use App\Models\Brand;
use App\Models\BrandFinding;
use App\Models\BrandSession;
use App\Models\SalesLead;
use App\Services\Brand\Findings;
use App\Services\Brand\MonthlySession;
use App\Services\Brand\NewOrder;
use App\Services\Sales\CustomerSignals;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Marka sayfası › Bulgular (Marka Düzeni 2.1 / 2.2, the approved prototype): what Claude saw on this brand with the
 * evidence, "Çöz" / "Hepsini çöz" that hand the work to Claude, the operator's own jobs, what was looked at and left
 * alone, what could not be seen, and what was done with its result. Shown at the top of the brand's Genel tab once
 * the brand has had its first deep look.
 */
final class BrandFindingsCard extends Component
{
    use AdminOnly;

    #[Locked]
    public int $brandId;

    public string $message = '';

    public function mount(int $brandId): void
    {
        $this->brandId = $brandId;
    }

    public function solve(int $id, Findings $findings): void
    {
        $this->adminOnly();
        $row = $this->row($id);
        $findings->solve($row, auth()->user());
        $this->message = '"'.$row->title.'" Claude\'a iletildi. Sonuç bu satıra döner.';
    }

    public function solveAll(Findings $findings): void
    {
        $this->adminOnly();
        $count = $findings->solveAll(Brand::query()->findOrFail($this->brandId), auth()->user());
        $this->message = $count > 0 ? $count.' bulgu Claude\'a iletildi. Sonuçlar satırlarına döner.' : 'İletilecek açık bulgu yok.';
    }

    /** Geri al / Kapat / Onayla (Yapıldı) / Yeniden aç. */
    public function mark(int $id, string $status, Findings $findings): void
    {
        $this->adminOnly();
        $findings->setStatus($this->row($id), $status);
        $this->message = '';
    }

    /** Marka Düzeni 2.5: the brand's "yeni düzen" switch (open rows are set aside, never deleted; switching back returns them). */
    public function newOrder(bool $on, NewOrder $order): void
    {
        $this->adminOnly();
        $brand = Brand::query()->findOrFail($this->brandId);
        $count = $on ? $order->on($brand) : $order->off($brand);
        $this->message = $on ? 'Yeni düzen açıldı. '.$count.' açık satır kenara alındı.' : 'Eski düzene dönüldü. '.$count.' satır geri geldi.';
    }

    /** Marka Düzeni 2.6: the month's one approval; the session's jobs become findings in Claude's hands. */
    public function approveSession(int $id, MonthlySession $sessions): void
    {
        $this->adminOnly();
        $session = BrandSession::query()->where('brand_id', $this->brandId)->findOrFail($id);
        $count = $sessions->approve($session, auth()->user());
        $this->message = $session->monthLabel().' oturumu onaylandı. '.$count.' iş Claude\'a iletildi; müşteri raporu hazır.';
    }

    /** The "rapor gönderildi" tick of the session's month (sending itself is the operator's own message). */
    public function reportSent(int $id, bool $sent, CustomerSignals $signals): void
    {
        $this->adminOnly();
        $session = BrandSession::query()->where('brand_id', $this->brandId)->findOrFail($id);
        $brand = Brand::query()->findOrFail($this->brandId);
        $sent ? $signals->markReportSent($brand, $session->month, auth()->user(), 'link') : $signals->unmarkReport($brand, $session->month);
        $this->message = $sent ? $session->monthLabel().' raporu gönderildi olarak işlendi.' : 'Gönderildi işareti kaldırıldı.';
    }

    private function row(int $id): BrandFinding
    {
        return BrandFinding::query()->where('brand_id', $this->brandId)->findOrFail($id);
    }

    public function render(Findings $findings, MonthlySession $sessions, CustomerSignals $signals): View
    {
        $brand = Brand::query()->findOrFail($this->brandId);
        $session = $sessions->current($brand);

        return view('livewire.operator.portfolio.brand-findings-card', ['brand' => $brand, 'page' => $findings->page($brand), 'held' => $brand->new_order_at !== null ? NewOrder::heldCount((int) $brand->id) : 0,
            'session' => $session, 'reportSent' => $session !== null ? $signals->report($brand, $session->month)?->sent_at : null,
            // Referans isteme anı (Satış Düzeni 4.5): the brand's first report has gone and this customer has not referred anyone yet.
            'referralAsk' => $session !== null && $session->isApproved() && ! BrandSession::query()->where('brand_id', $brand->id)->where('id', '<', $session->id)->where('status', 'approved')->exists()
                && ($brand->customer_id === null || ! SalesLead::query()->where('referrer_customer_id', $brand->customer_id)->exists())]);
    }
}
