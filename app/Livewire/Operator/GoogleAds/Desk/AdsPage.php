<?php

namespace App\Livewire\Operator\GoogleAds\Desk;

use App\Services\GoogleAds\Desk\GoogleAdsDesk;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Google Ads › Reklamlar ve kampanyalar (`/google-ads/reklamlar`): AI drafts of every account (campaign structure,
 * budget split, experiments, responsive search ad texts). Approved drafts go into the account's Google Ads Editor
 * file; the accounts with a file waiting are listed with a link to download it. Nothing is written to Google here.
 */
#[Layout('operator.layouts.app')]
#[Title('Google Ads · Reklamlar ve kampanyalar')]
final class AdsPage extends Component
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
            'active' => 'operator.google-ads-ads',
            'intro' => 'Kampanya yapısı, bütçe dağılımı, deney planı ve reklam metni taslakları. Onaylanan taslak hesabın Google Ads Editor dosyasına girer; Google’a buradan bir şey yazılmaz. Yeni taslak hesabın Kampanya stratejisi sekmesinden istenir.',
            'empty' => 'Bekleyen reklam ya da kampanya taslağı yok.',
            'items' => $desk->open($accounts, GoogleAdsDesk::AD_TYPES),
            'accounts' => $accounts->keyBy('id'),
            'brandOptions' => $desk->brandOptions(),
            'accountTab' => 'strategy',
            'editor' => $desk->editorWaiting($accounts),
        ]);
    }
}
