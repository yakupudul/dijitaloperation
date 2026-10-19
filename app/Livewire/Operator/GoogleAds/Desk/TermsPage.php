<?php

namespace App\Livewire\Operator\GoogleAds\Desk;

use App\Services\GoogleAds\Desk\GoogleAdsDesk;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Google Ads › Arama terimleri (`/google-ads/arama-terimleri`): the negative keyword proposals of every account in one
 * list, by the money the covered search terms wasted. A shared-list negative is sent through the Onarım masası
 * approval (logged, undoable); a campaign or ad group negative goes into the account's Editor file. The terms are
 * reviewed by AI every Monday (moxdop:google-ads:weekly-terms) and on the account page on demand.
 */
#[Layout('operator.layouts.app')]
#[Title('Google Ads · Arama terimleri')]
final class TermsPage extends Component
{
    use DeskScope;

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_active, 403);
    }

    public function render(GoogleAdsDesk $desk): View
    {
        $accounts = $this->scopedAccounts();

        return view('livewire.operator.google-ads.desk.suggestions', [
            'active' => 'operator.google-ads-terms',
            'intro' => 'Tüm hesaplarda hizmetle ilgisiz aramalara giden para. Paylaşılan listeye eklenecek negatifler onaylanınca Google Ads’e yazılır (kayıtlı, geri alınabilir); kampanya / reklam grubu negatifleri hesabın Editor dosyasına girer. Arama terimleri her pazartesi AI ile yeniden incelenir.',
            'empty' => 'Bekleyen negatif kelime önerisi yok.',
            'items' => $desk->open($accounts, GoogleAdsDesk::TERM_TYPES),
            'accounts' => $accounts->keyBy('id'),
            'brandOptions' => $desk->brandOptions(),
            'accountTab' => 'terms',
            'editor' => [],
        ]);
    }
}
