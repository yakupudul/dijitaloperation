<?php

namespace App\Livewire\Operator\Workspace;

use App\Livewire\Operator\Workspace\Concerns\HandlesAnalystDecisions;
use App\Models\AnalystDecision;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\User;
use App\Services\Analyst\AnalystWorkspace;
use App\Services\Analyst\GoogleAds\GoogleAdsAnalyst;
use App\Services\Analyst\GoogleAds\GoogleAdsFacts;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Support\ServiceScope;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * Brand workspace › Google Ads: Durum (6 numbers) · Yapılacaklar (AI cards) · the Admin review of a card's negative list
 * (ADR-064 shared list, undoable) · Kanıt (accounts, wasted terms, campaigns).
 */
class GoogleAdsTab extends Component
{
    use HandlesAnalystDecisions;

    #[Locked]
    public int $brandId;

    public string $notice = '';

    public string $noticeTone = 'success';

    /** The add_negatives card under review (from the card link ?neg=). */
    #[Locked]
    public ?int $negDecisionId = null;

    /** Editable negative list ([exact], "phrase") the Admin approves. */
    public string $negLines = '';

    public function mount(int $brandId): void
    {
        $this->brandId = $brandId;
        $neg = request()->query('neg');
        if (is_string($neg) && ctype_digit($neg) && strlen($neg) <= 18) {
            $this->openNegatives((int) $neg);
        }
    }

    protected function analystBrandId(): int
    {
        return $this->brandId;
    }

    protected function analystNotice(string $message, string $tone = 'success'): void
    {
        $this->notice = $message;
        $this->noticeTone = $tone;
    }

    /** Opens the proposed negative list of an add_negatives card for review. */
    public function openNegatives(int $decisionId): void
    {
        $decision = AnalystDecision::query()->where('brand_id', $this->brandId)->where('channel', 'google_ads')->where('action_type', 'add_negatives')->find($decisionId);
        if ($decision === null) {
            $this->analystNotice('Kart artık yok; sayfayı yenileyin.', 'error');

            return;
        }
        try {
            $proposal = app(GoogleAdsAnalyst::class)->negativeLines($decision);
        } catch (ValidationException $exception) {
            $this->analystNotice((string) (collect($exception->errors())->flatten()->first() ?? 'Liste hazırlanamadı.'), 'error');

            return;
        }
        $this->negDecisionId = (int) $decision->id;
        $this->negLines = $proposal['lines'];
    }

    public function cancelNegatives(): void
    {
        $this->negDecisionId = null;
        $this->negLines = '';
    }

    /** ADR-064: the Admin approves the (edited) list; it goes to the account's "MoxDOP negatifleri" shared list. */
    public function sendNegatives(ExternalWriteService $writes): void
    {
        $user = auth()->user();
        if (! $user instanceof User || ! ExternalWriteService::allowed($user, ExternalWriteAction::CHANNEL_GOOGLE_ADS)) {
            $this->analystNotice('Google Ads\'e yazmayı yalnız Admin onaylayabilir; listeyi kopyalayıp Admin\'e ilet.', 'error');

            return;
        }
        $decision = $this->negDecisionId !== null ? AnalystDecision::query()->where('brand_id', $this->brandId)->find($this->negDecisionId) : null;
        $assetId = $decision !== null ? GoogleAdsAnalyst::accountOf((string) ($decision->action_params['target'] ?? '')) : '';
        $asset = ctype_digit($assetId) ? DigitalAsset::query()->where('brand_id', $this->brandId)->find((int) $assetId) : null;
        if ($decision === null || $asset === null) {
            $this->analystNotice('Kart ya da reklam hesabı artık yok; yeniden analiz edin.', 'error');

            return;
        }
        try {
            $action = $writes->requestNegativeListForDecision($user, $decision, $asset, $this->negLines);
        } catch (ValidationException $exception) {
            $this->analystNotice((string) (collect($exception->errors())->flatten()->first() ?? 'Gönderilemedi.'), 'error');

            return;
        }
        $this->cancelNegatives();
        $this->analystNotice(sprintf('%d negatif terim %s hesabına gönderiliyor ("%s" listesi); geri alınabilir.', count($action->request_payload['keywords']), $asset->name,
            config('moxdop-external-writes.google_ads.shared_set_name')));
    }

    public function undoNegativeWrite(int $actionId, ExternalWriteService $writes): void
    {
        $action = ExternalWriteAction::query()->where('brand_id', $this->brandId)->where('channel', ExternalWriteAction::CHANNEL_GOOGLE_ADS)->find($actionId);
        $user = auth()->user();
        if ($action === null || ! $user instanceof User || ! ExternalWriteService::allowed($user, ExternalWriteAction::CHANNEL_GOOGLE_ADS)) {
            $this->analystNotice('Geri alma yapılamadı.', 'error');

            return;
        }
        try {
            $writes->requestUndo($user, $action);
        } catch (ValidationException $exception) {
            $this->analystNotice((string) (collect($exception->errors())->flatten()->first() ?? 'Geri alınamadı.'), 'error');

            return;
        }
        $this->analystNotice('Geri alınıyor: bu gönderimle eklenen terimler listeden çıkarılacak.');
    }

    public function render(GoogleAdsFacts $facts, GoogleAdsAnalyst $analyst, AnalystWorkspace $workspace): View
    {
        $brand = Brand::query()->findOrFail($this->brandId);
        $operational = app(ServiceScope::class)->isBrandOperational($brand->id);
        $stats = [];
        $accounts = [];
        $terms = [];
        $campaigns = [];
        $missing = $operational ? null : ServiceScope::NOT_SERVED;
        if ($operational) {
            try {
                $missing = $facts->missing($brand);
                if ($missing === null) {
                    $stats = $analyst->stats($brand);
                    foreach ($facts->accounts($brand) as $account) {
                        $money = fn (float $v): string => GoogleAdsFacts::money($v, $account['currency']);
                        $accounts[] = ['name' => $account['name'], 'currency' => $account['currency'], 'cost' => $money($account['cost']),
                            'conversions' => $account['conversions'], 'cpa' => $account['conversions'] > 0 ? $money($account['cost'] / $account['conversions']) : '—', 'wasted' => $money($account['wasted'])];
                        foreach (array_slice(array_values(array_filter($account['terms'], fn (array $t): bool => $t['wasted'])), 0, 15) as $term) {
                            $terms[] = ['text' => $term['text'], 'list' => ['competitor' => 'rakip marka', 'banned' => 'yasaklı', 'irrelevant' => 'alakasız'][$term['kind']] ?? '—',
                                'account' => $account['name'], 'cost' => $money($term['cost']), 'clicks' => $term['clicks'], 'excluded' => $term['excluded']];
                        }
                        foreach (array_slice($account['campaigns'], 0, 10) as $campaign) {
                            $campaigns[] = ['name' => $campaign['name'], 'type' => $campaign['type'], 'cost' => $money($campaign['cost']), 'conversions' => $campaign['conversions'],
                                'cpa' => $campaign['cpa'] !== null ? $money($campaign['cpa']) : '—', 'lost' => $campaign['lost_is_budget'] !== null ? '%'.$campaign['lost_is_budget'].' / %'.$campaign['lost_is_rank'] : '—'];
                        }
                    }
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        $negDecision = $this->negDecisionId !== null ? AnalystDecision::query()->where('brand_id', $brand->id)->find($this->negDecisionId) : null;
        $negAsset = $negDecision !== null ? DigitalAsset::query()->find((int) GoogleAdsAnalyst::accountOf((string) ($negDecision->action_params['target'] ?? ''))) : null;

        return view('livewire.operator.workspace.google-ads-tab', [
            'brand' => $brand,
            'operational' => $operational,
            'missing' => $missing,
            'stats' => $stats,
            'accounts' => $accounts,
            'terms' => $terms,
            'campaigns' => $campaigns,
            'negDecision' => $negDecision,
            'negAsset' => $negAsset,
            'canWrite' => ExternalWriteService::allowed(auth()->user(), ExternalWriteAction::CHANNEL_GOOGLE_ADS),
            'writes' => ExternalWriteAction::query()->where('brand_id', $brand->id)->where('channel', ExternalWriteAction::CHANNEL_GOOGLE_ADS)->latest('id')->limit(5)->get(),
            'decisions' => $operational ? $workspace->forChannel($brand, 'google_ads') : [],
            'lastRun' => $workspace->lastRun($brand, 'google_ads'),
        ]);
    }
}
