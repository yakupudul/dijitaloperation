<?php

namespace App\Livewire\Operator\Integrations;

use App\Models\AgencySetting;
use App\Models\DigitalAsset;
use App\Services\Integrations\Bing\BingWebmasterClient;
use App\Services\Integrations\Bing\BingWebmasterSync;
use App\Support\Demo\DemoState;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Entegrasyonlar › Bing Webmaster (yakup, 2026-10-07): the agency API key, the Bing sites matched to MoxDOP websites
 * and when each was last read. "Kaydet ve eşleştir" checks the key against Bing before keeping it; reading runs every
 * morning (`moxdop:bing:collect`) or now with "Şimdi çek". Read only: nothing is sent to Bing.
 */
#[Layout('operator.layouts.app')]
#[Title('Bing Webmaster')]
class BingIntegrationPage extends Component
{
    public string $apiKey = '';

    public function save(BingWebmasterClient $client, BingWebmasterSync $sync): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        $key = trim($this->apiKey);
        if ($key === '') {
            $this->addError('apiKey', 'Bing Webmaster API anahtarını yapıştırın.');

            return;
        }
        try {
            $client->sites($key);
        } catch (Throwable $exception) {
            $this->addError('apiKey', $exception->getMessage());

            return;
        }
        $settings = AgencySetting::query()->first() ?? new AgencySetting;
        $settings->forceFill(['bing_webmaster_api_key' => $key])->save();
        $this->apiKey = '';
        $this->matchAndCollect($sync);
    }

    public function collectNow(BingWebmasterSync $sync): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        $this->matchAndCollect($sync);
    }

    public function forget(): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        AgencySetting::query()->first()?->forceFill(['bing_webmaster_api_key' => null])->save();
        DemoState::flash('Bing anahtarı silindi; okunmuş veriler duruyor.');
    }

    private function matchAndCollect(BingWebmasterSync $sync): void
    {
        try {
            $match = $sync->match();
            $collected = $sync->collect();
            DemoState::flash(sprintf('Bing\'de %d site var, %d tanesi MoxDOP sitesiyle eşleşti; %d sorgu satırı okundu%s.',
                $match['sites'], $match['matched'], $collected['rows'], $collected['failed'] > 0 ? ', '.$collected['failed'].' site okunamadı' : ''));
        } catch (Throwable $exception) {
            DemoState::flash('Bing okunamadı: '.$exception->getMessage(), 'error');
        }
    }

    public function render(): View
    {
        $sites = DB::table('bing_sites')->orderBy('site_url')->get();
        $assets = DigitalAsset::query()->whereIn('id', $sites->pluck('digital_asset_id')->all() ?: [0])->with('brand:id,name')->get()->keyBy('id');
        $unmatched = DigitalAsset::query()->where('type', 'website')->where('status', 'active')->whereNotIn('id', $sites->pluck('digital_asset_id')->all() ?: [0])->count();

        return view('livewire.operator.integrations.bing-integration-page', [
            'configured' => BingWebmasterClient::configured(),
            'sites' => $sites->map(fn (object $s): array => [
                'site_url' => (string) $s->site_url, 'verified' => (bool) $s->verified, 'error' => $s->error, 'collected_at' => $s->collected_at,
                'asset' => $assets->get($s->digital_asset_id), 'summary' => BingWebmasterSync::summary((int) $s->digital_asset_id),
            ])->all(),
            'unmatched' => $unmatched,
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
