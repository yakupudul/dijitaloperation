<?php

namespace App\Livewire\Operator\GoogleAds\Desk;

use App\Services\GoogleAds\Desk\GoogleAdsDesk;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Google Ads › Ayarlar ve bütçe (`/google-ads/ayarlar-ve-butce`): the setting changes the nightly check prepared
 * (ADR-081: networks, location option, auto-tagging, keyword pause, budget) with before → after and the reason, plus the
 * failing system checks and the read-only advice that are done by hand in Google Ads. A change is written only on
 * the Admin's approval through the Onarım masası path; MoxDOP never raises a brand's total budget on its own.
 */
#[Layout('operator.layouts.app')]
#[Title('Google Ads · Ayarlar ve bütçe')]
final class ChangesPage extends Component
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
            'active' => 'operator.google-ads-changes',
            'intro' => 'Hesap ayarlarında hazırlanmış değişiklikler (arama ağı ortakları, görüntülü ağ, konum seçeneği, otomatik etiketleme, boşa harcayan kelime, bütçe kaybeden iyi kampanya). Onaylanınca canlı değer tekrar okunur, değişmemişse yazılır; hepsi kayıtlı ve geri alınabilir. Sistem kontrolleri ve tavsiyeler Google Ads’te elle yapılır.',
            'empty' => 'Bekleyen ayar değişikliği ya da kontrol yok.',
            'items' => $desk->open($accounts, GoogleAdsDesk::CHANGE_TYPES),
            'accounts' => $accounts->keyBy('id'),
            'brandOptions' => $desk->brandOptions(),
            'accountTab' => 'todo',
            'editor' => [],
        ]);
    }
}
