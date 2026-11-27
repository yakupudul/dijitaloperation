<?php

namespace App\Livewire\Panel;

use App\Panel\Models\PanelAudit;
use App\Panel\Models\PanelTicket;
use App\Panel\Services\PanelAccount;
use App\Panel\Services\PanelSupport;
use App\Panel\Support\PanelPage;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Hesap: the brand's services, invoices, payments and yearly renewals, for its manager only. Read from Finans. */
#[Layout('panel.layouts.app')]
#[Title('Hesap')]
final class AccountPage extends Component
{
    use PanelPage;
    use WithFileUploads;

    public const array TABS = ['hizmetler', 'faturalar', 'odemeler', 'yenilemeler'];

    public string $tab = 'hizmetler';

    /** The payment row a dekont is being added to. */
    public ?int $paying = null;

    public $receipt = null;

    protected function permission(): string
    {
        return 'manager';
    }

    public function mount(string $tab = 'hizmetler'): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'hizmetler';
    }

    /** Asks the agency for an offer on a service the brand does not buy yet: it becomes a request under Destek. */
    public function offer(string $service, PanelAccount $account, PanelSupport $support): void
    {
        $extras = $account->extras($account->agreements((int) $this->panel()->brandId()));
        abort_unless(isset($extras[$service]), 404);
        $subject = 'Teklif isteği: '.$extras[$service][0];
        if (PanelTicket::query()->where('subject', $subject)->where('status', '!=', PanelTicket::SOLVED)->exists()) {
            $this->notify('Bu hizmet için teklif isteğiniz zaten bizde, en kısa sürede dönüyoruz.');

            return;
        }
        $support->open((int) $this->panel()->brandId(), (int) $this->panelUser()->id, $subject, 'wish', $extras[$service][0].' için teklif almak istiyoruz.');
        $this->notify('İsteğiniz Moximu\'ya ulaştı. Teklif Destek bölümüne gelecek.');
    }

    public function sendReceipt(PanelAccount $account): void
    {
        abort_if($this->paying === null, 404);
        $this->validate(['receipt' => ['required', 'file', 'max:15000', 'mimes:pdf,jpg,jpeg,png,webp,heic']],
            ['receipt.required' => 'Dekont dosyasını seçin.', 'receipt.mimes' => 'PDF ya da fotoğraf yükleyin.', 'receipt.max' => 'Dosya 15 MB\'tan büyük olamaz.'], ['receipt' => 'dekont']);
        $doc = $account->receipt((int) $this->panel()->brandId(), (int) $this->paying, $this->receipt, (int) $this->panelUser()->id);
        PanelAudit::record('account.receipt', $this->panel()->brandId(), 'panel', (int) $this->panelUser()->id, ['payment_id' => $doc->payment_id]);
        $this->paying = null;
        $this->receipt = null;
        $this->notify('Dekontunuz ulaştı. Bankada görünce ödemenizi işaretliyoruz.');
    }

    public function render(PanelAccount $account): View
    {
        $brandId = (int) $this->panel()->brandId();
        $agreements = $account->agreements($brandId);

        return view('livewire.panel.account', [
            'agreements' => $agreements,
            'extras' => $this->tab === 'hizmetler' ? $account->extras($agreements) : [],
            'payments' => in_array($this->tab, ['odemeler', 'faturalar'], true) ? $account->payments($brandId) : null,
            'sent' => $this->tab === 'odemeler' ? $account->sentReceipts($brandId) : collect(),
            'invoices' => $this->tab === 'faturalar' ? $account->invoices($brandId) : collect(),
            'takesInvoices' => $account->takesInvoices($brandId),
            'renewals' => $this->tab === 'yenilemeler' ? $account->renewals($brandId) : collect(),
            'bank' => $this->tab === 'odemeler' ? $account->bank() : null,
            'canAsk' => $this->may('support.write'),
        ]);
    }
}
