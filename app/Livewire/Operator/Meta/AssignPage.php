<?php

namespace App\Livewire\Operator\Meta;

use App\Jobs\Meta\MatchMetaCampaignServicesJob;
use App\Livewire\Demo\Concerns\ResolvesCanonicalOperatorAsset;
use App\Models\DigitalAsset;
use App\Services\Meta\MetaCampaignBoard;
use App\Services\Meta\MetaCampaignServices;
use App\Services\Meta\MetaScreen;
use App\Services\SeoTasks\SeoText;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Eşleşmeyenleri ata: the account's campaigns without a confirmed service in one list, each with a sample ad text, the
 * page it opens and the suggested service with its reason. Approve one by one or the ticked ones together, pick
 * another service, or mark "hizmet dışı". Campaigns no rule matched can be sent to AI (Claude queue) in one go.
 */
#[Layout('operator.layouts.app')]
#[Title('Eşleşmeyenleri ata · Meta')]
class AssignPage extends Component
{
    use ResolvesCanonicalOperatorAsset;

    #[Locked]
    public string $assetId = '';

    /** @var list<string> ticked campaign ids */
    public array $selected = [];

    /** @var array<string, string> campaign id => offering id picked in "Başka hizmet" */
    public array $pick = [];

    public function mount(string $assetId, MetaCampaignServices $services): void
    {
        $asset = $this->bindCanonicalAsset($assetId, ['meta_ads']);
        // Fresh rule pass so a just-collected campaign is listed with its suggestion; decided ones stay listed (to undo)
        // only while this visit lasts.
        $services->sync($asset);
        session()->forget('meta-assign-touched.'.$asset->id);
        $this->selected = array_column(array_filter($this->rows($services, app(MetaCampaignBoard::class)), fn (array $r): bool => $r['services'] !== []), 'id');
    }

    public function approve(string $campaignId, MetaCampaignServices $services): void
    {
        $services->confirmSuggestions($this->asset(), [$campaignId], auth()->user());
    }

    public function approveSelected(MetaCampaignServices $services): void
    {
        $done = $services->confirmSuggestions($this->asset(), array_values(array_map('strval', $this->selected)), auth()->user());
        $this->selected = [];
        DemoState::flash($done.' kampanyada öneri onaylandı.', 'success');
    }

    public function choose(string $campaignId, MetaCampaignServices $services): void
    {
        $offeringId = (int) ($this->pick[$campaignId] ?? 0);
        if ($offeringId > 0) {
            $services->confirm($this->asset(), $campaignId, $offeringId, auth()->user(), replace: true);
            unset($this->pick[$campaignId]);
        }
    }

    public function exclude(string $campaignId, MetaCampaignServices $services): void
    {
        $services->exclude($this->asset(), $campaignId, auth()->user());
    }

    public function excludeSelected(MetaCampaignServices $services): void
    {
        foreach ($this->selected as $campaignId) {
            $services->exclude($this->asset(), (string) $campaignId, auth()->user());
        }
        DemoState::flash(count($this->selected).' kampanya hizmet dışı işaretlendi.', 'info');
        $this->selected = [];
    }

    public function undo(string $campaignId, MetaCampaignServices $services): void
    {
        $services->reopen($this->asset(), $campaignId);
    }

    public function askAi(): void
    {
        Cache::put(MetaCampaignServices::stateKey((int) $this->assetId), ['status' => 'running', 'message' => 'AI eşleştiriyor…'], now()->addDay());
        MatchMetaCampaignServicesJob::dispatch((int) $this->assetId);
        DemoState::flash('Eşleşmeyen kampanyalar AI’a gönderildi; öneriler bu listeye düşer.', 'info');
    }

    public function render(MetaCampaignServices $services, MetaCampaignBoard $board, MetaScreen $screen): View
    {
        $asset = $this->asset()->loadMissing('brand');
        $rows = $this->rows($services, $board);
        $done = array_values(array_filter($rows, fn (array $r): bool => $r['done'] !== null));

        return view('livewire.operator.meta.assign', [
            'asset' => $this->presentCanonicalAsset(),
            'brand' => $asset->brand,
            'account' => $screen->account($asset),
            'rows' => $rows,
            'open' => count($rows) - count($done),
            'withoutSuggestion' => count(array_filter($rows, fn (array $r): bool => $r['done'] === null && $r['services'] === [])),
            'offerings' => $asset->brand !== null ? $services->offerings($asset->brand) : [],
            'aiState' => Cache::get(MetaCampaignServices::stateKey((int) $this->assetId)),
            'flash' => DemoState::pullFlash(),
        ]);
    }

    /**
     * Campaigns waiting for a decision, plus the ones decided on this page (to undo).
     *
     * @return list<array<string, mixed>>
     */
    private function rows(MetaCampaignServices $services, MetaCampaignBoard $board): array
    {
        $asset = $this->asset();
        $account = app(MetaScreen::class)->account($asset);
        if ($account === null) {
            return [];
        }
        $entities = app(MetaScreen::class)->entities($account);
        $list = $board->board($asset, 28)['rows'];
        $sample = [];
        foreach ($entities['ads'] as $ad) {
            $creative = $entities['creatives'][$ad['creative_id']] ?? [];
            if (! isset($sample[$ad['campaign_id']]) && (($creative['body'] ?? '') !== '' || ($creative['title'] ?? '') !== '')) {
                $sample[$ad['campaign_id']] = ['text' => (string) (($creative['body'] ?? '') ?: $creative['title']), 'link' => (string) ($creative['link_url'] ?? ''),
                    'form' => ($creative['lead_gen_form_id'] ?? '') !== ''];
            }
        }
        $touched = array_flip(array_map('strval', array_keys(session('meta-assign-touched.'.$this->assetId, []))));
        $out = [];
        foreach ($list as $row) {
            $waiting = in_array($row['service_state'], [MetaCampaignServices::STATE_NONE, MetaCampaignServices::STATE_SUGGESTED], true) && $row['status'] !== 'ended';
            if (! $waiting && ! isset($touched[$row['id']])) {
                continue;
            }
            $s = $sample[$row['id']] ?? ['text' => '', 'link' => '', 'form' => false];
            $out[] = $row + ['sample' => $s['text'], 'landing' => $s['form'] ? 'Anında form' : ($s['link'] !== '' ? SeoText::urlPath($s['link']) : 'Bağlantı yok'),
                'done' => $waiting ? null : ($row['service_state'] === MetaCampaignServices::STATE_EXCLUDED ? 'Hizmet dışı' : implode(', ', array_column($row['services'], 'name')).' · onaylandı')];
            if ($waiting) {
                session()->put('meta-assign-touched.'.$this->assetId.'.'.$row['id'], true);
            }
        }

        return $out;
    }

    private function asset(): DigitalAsset
    {
        return DigitalAsset::query()->whereKey((int) $this->assetId)->where('type', 'meta_ads')->firstOrFail();
    }
}
