<?php

namespace App\Livewire\Operator\Sales;

use App\Livewire\Concerns\AdminOnly;
use App\Services\Access\StartLink;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Component;

/**
 * Satış › Ayarlar › "Sözleşme ve ödeme" (Satış Düzeni 3.2): the account the customer pays into and the contract text
 * the start link shows. The words in braces are filled per customer from the agreement.
 */
final class StartLinkSettings extends Component
{
    use AdminOnly;

    public string $holder = '';

    public string $iban = '';

    public string $bank = '';

    public string $contract = '';

    public string $message = '';

    public function mount(StartLink $start): void
    {
        ['holder' => $this->holder, 'iban' => $this->iban, 'bank' => $this->bank] = $start->bank();
        $this->contract = $start->contractText();
    }

    public function save(StartLink $start): void
    {
        $this->adminOnly();
        $this->resetValidation();
        try {
            $start->save($this->holder, $this->iban, $this->bank, $this->contract);
        } catch (InvalidArgumentException $error) {
            $this->addError('iban', $error->getMessage());

            return;
        }
        $this->mount($start);
        $this->message = 'Kaydedildi. Yeni açılan başlangıç bağlantıları bu metni ve hesabı gösterir.';
    }

    public function render(): View
    {
        return view('livewire.operator.sales.start-link-settings');
    }
}
