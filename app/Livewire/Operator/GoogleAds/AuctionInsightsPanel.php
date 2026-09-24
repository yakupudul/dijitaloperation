<?php

namespace App\Livewire\Operator\GoogleAds;

use App\Models\DigitalAsset;
use App\Services\GoogleAds\AuctionInsightsImporter;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Faz 14g: Google Ads › Açık artırma. The operator downloads "Auction insights" from Google Ads as CSV and
 * uploads it here; the latest upload is shown with the change in impression share against the one before.
 */
class AuctionInsightsPanel extends Component
{
    use WithFileUploads;

    #[Locked]
    public string $assetId;

    /** @var TemporaryUploadedFile|null */
    public $report = null;

    public string $periodStart = '';

    public string $periodEnd = '';

    public string $message = '';

    public string $error = '';

    public function mount(string $assetId): void
    {
        $this->assetId = $assetId;
        $this->asset();
    }

    public function upload(AuctionInsightsImporter $importer): void
    {
        $this->validate([
            'report' => ['required', 'file', 'max:2048', 'mimes:csv,txt,tsv'],
            'periodStart' => ['nullable', 'date'],
            'periodEnd' => ['nullable', 'date', 'after_or_equal:periodStart'],
        ]);
        try {
            $count = $importer->import($this->asset(), (string) file_get_contents($this->report->getRealPath()),
                $this->periodStart !== '' ? $this->periodStart : null, $this->periodEnd !== '' ? $this->periodEnd : null, auth()->user());
            $this->message = $count.' satır yüklendi.';
            $this->error = '';
            $this->report = null;
        } catch (InvalidArgumentException $exception) {
            $this->error = $exception->getMessage();
            $this->message = '';
        }
    }

    private function asset(): DigitalAsset
    {
        return DigitalAsset::query()->whereKey((int) $this->assetId)->where('type', 'google_ads')->firstOrFail();
    }

    public function render(AuctionInsightsImporter $importer): View
    {
        return view('livewire.operator.google-ads.auction-insights-panel', ['latest' => $importer->latest($this->asset())]);
    }
}
