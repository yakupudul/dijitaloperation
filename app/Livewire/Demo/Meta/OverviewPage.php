<?php

namespace App\Livewire\Demo\Meta;

use App\Jobs\CollectMetaGeoResultsJob;
use App\Jobs\Meta\SyncMetaSuggestionsJob;
use App\Livewire\Demo\Concerns\ResolvesCanonicalOperatorAsset;
use App\Livewire\Operator\Concerns\HasDateRange;
use App\Models\DigitalAsset;
use App\Services\Analyst\AnalystDecisionStore;
use App\Services\Async\AsyncOperationService;
use App\Services\Meta\MetaAnalysis;
use App\Services\Meta\MetaAssistant;
use App\Services\Meta\MetaCampaignBoard;
use App\Services\Meta\MetaCampaignServices;
use App\Services\Meta\MetaChecks;
use App\Services\Meta\MetaLeads;
use App\Services\Meta\MetaScreen;
use App\Services\Meta\MetaSuggestions;
use App\Support\Demo\DemoState;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Meta (Faz 6): Kampanyalar · Analiz · Yapılacaklar · Ölçümleme · Ayarlar for one bound ad account. Kampanyalar is the
 * opening tab: every campaign with its services (Kampanya → hizmet), budget, results of its own type and alerts; a
 * campaign opens its own page (CampaignPage). Numbers come from the collected Meta tables (MetaScreen); twelve system checks and three AI
 * operations fill the ONE suggestions table (MetaChecks, MetaAssistant). Nothing is written to Meta: an approved item
 * is a copyable instruction / CSV row; "Uygulandı" stores the baseline. Lead quality is marked by hand (MetaLeads).
 */
#[Layout('operator.layouts.app')]
#[Title('Meta Reklamları')]
class OverviewPage extends Component
{
    use HasDateRange;
    use ResolvesCanonicalOperatorAsset;
    use WithFileUploads;

    public const array TABS = ['campaigns' => 'Kampanyalar', 'analysis' => 'Analiz', 'todo' => 'Yapılacaklar', 'measurement' => 'Ölçümleme', 'settings' => 'Ayarlar'];

    /** @var array<string, string> Retired tab keys kept working for old links. */
    private const array LEGACY_TAB_MAP = [
        'overview' => 'campaigns', 'creatives' => 'todo', 'strategy' => 'todo', 'adsets' => 'analysis', 'ads' => 'analysis', 'audience' => 'analysis', 'breakdowns' => 'analysis',
        'delivery' => 'analysis', 'funnel' => 'analysis', 'advisor' => 'todo', 'operations' => 'todo', 'insights' => 'todo', 'destinations' => 'measurement',
    ];

    #[Locked]
    public string $assetId = '';

    #[Url]
    public string $tab = 'campaigns';

    /** Kampanyalar filters: status (live | paused | all), service (offering id or "none"), result type, search. */
    #[Url(as: 'durum')]
    public string $status = 'all';

    #[Url(as: 'hizmet')]
    public string $service = '';

    #[Url(as: 'sonuc')]
    public string $resultType = '';

    #[Url(as: 'ara')]
    public string $search = '';

    /** Date picker (Kampanyalar, Analiz, Ölçüm): preset days, or a custom start / end (HasDateRange); `compare` below. */
    #[Url]
    public int $days = 28;

    /** Analiz: '' (whole account) | service:{offering id} | campaign:{campaign id}; compare prev | year; cost type of age × gender. */
    #[Url(as: 'odak')]
    public string $focus = '';

    #[Url(as: 'karsilastir')]
    public string $compare = 'prev';

    #[Url(as: 'tur')]
    public string $analysisType = '';

    /** Suggestion being edited by the operator (its text is then locked). */
    public ?int $editId = null;

    public string $editText = '';

    /** @var TemporaryUploadedFile|null Meta lead export */
    public $leadFile = null;

    public bool $unmarkedOnly = false;

    public function mount(?string $assetId = null, ?string $tab = null): void
    {
        $this->bindCanonicalAsset($assetId, ['meta_ads']);
        if (filled($tab)) {
            $this->tab = $tab;
        }
        $this->normalize();
        $asset = $this->asset();
        // First visit: the system checks are computed once in the background (then daily).
        if ($asset->brand_id !== null && MetaChecks::states((int) $asset->id) === null && Cache::add('meta-checks-seeded:'.$asset->id, true, now()->addHour())) {
            SyncMetaSuggestionsJob::dispatch((int) $asset->id);
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->editId = null;
        $this->normalize();
    }

    public function refreshData(AsyncOperationService $async): void
    {
        $result = $async->queueBoundCollect($this->asset(), auth()->user(), ['trigger' => 'operator.meta.refresh']);
        DemoState::flash((string) ($result['message'] ?? __('operator_runtime.sources.collect_failed')), ($result['ok'] ?? false) ? 'success' : 'info');
    }

    /** Country + city results for Analiz: queue a collection now (the daily run keeps it fresh). */
    public function collectGeoResults(MetaScreen $screen): void
    {
        if ($screen->account($this->asset()) === null) {
            DemoState::flash('Meta reklam hesabı bağlı değil.', 'info');

            return;
        }
        Cache::put(CollectMetaGeoResultsJob::stateKey((int) $this->assetId), ['state' => 'running', 'at' => now()->toIso8601String()], now()->addHour());
        CollectMetaGeoResultsJob::dispatch((int) $this->assetId, 90);
        DemoState::flash('Bölge, yaş / cinsiyet, saat, yerleşim ve cihaz verisi Meta’dan çekiliyor; birkaç dakika sürebilir.', 'info');
    }

    /* ---------------- Kampanyalar: services ---------------- */

    public function confirmService(string $campaignId, int $offeringId, MetaCampaignServices $services): void
    {
        $services->confirm($this->asset(), $campaignId, $offeringId, auth()->user());
    }

    public function removeService(string $campaignId, int $offeringId, MetaCampaignServices $services): void
    {
        $services->remove($this->asset(), $campaignId, $offeringId, auth()->user());
    }

    /* ---------------- Yapılacaklar ---------------- */

    public function recheck(): void
    {
        SyncMetaSuggestionsJob::dispatch((int) $this->asset()->id);
        DemoState::flash('Kontroller yeniden çalışıyor.', 'info');
    }

    public function runAi(string $operation, MetaAssistant $assistant): void
    {
        try {
            $assistant->queue($this->asset(), $operation);
            DemoState::flash('AI çalışıyor; birkaç saniye sonra burada görünür.', 'info');
        } catch (ValidationException $exception) {
            DemoState::flash((string) collect($exception->errors())->flatten()->first(), 'error');
        }
    }

    public function approveSuggestion(int $id, MetaSuggestions $suggestions): void
    {
        $suggestions->approve($suggestions->find($this->asset(), $id), auth()->user());
        DemoState::flash('Onaylandı: talimatı kopyalayın ya da CSV indirin, Reklam Yöneticisi’nde uygulayın.', 'success');
    }

    public function markApplied(int $id, MetaSuggestions $suggestions): void
    {
        $suggestions->markApplied($this->asset(), $suggestions->find($this->asset(), $id), auth()->user());
        DemoState::flash('Uygulandı; sonuç 28 ve 56 gün sonra ölçülecek.', 'success');
    }

    public function dismissSuggestion(int $id, MetaSuggestions $suggestions, AnalystDecisionStore $store): void
    {
        $store->dismiss($suggestions->find($this->asset(), $id), auth()->user());
        DemoState::flash('Reddedildi.', 'info');
    }

    public function snoozeSuggestion(int $id, MetaSuggestions $suggestions, AnalystDecisionStore $store): void
    {
        $store->snooze($suggestions->find($this->asset(), $id), 7);
        DemoState::flash('7 gün ertelendi.', 'info');
    }

    public function startEdit(int $id, MetaSuggestions $suggestions): void
    {
        $suggestion = $suggestions->find($this->asset(), $id);
        $this->editId = (int) $suggestion->id;
        $this->editText = (string) ($suggestion->action['text'] ?? '');
    }

    public function saveEdit(MetaSuggestions $suggestions): void
    {
        if ($this->editId === null) {
            return;
        }
        try {
            $suggestions->edit($suggestions->find($this->asset(), $this->editId), $this->editText);
            $this->editId = null;
            DemoState::flash('Kaydedildi; AI bu metni değiştirmez.', 'success');
        } catch (ValidationException $exception) {
            DemoState::flash((string) collect($exception->errors())->flatten()->first(), 'error');
        }
    }

    public function cancelEdit(): void
    {
        $this->editId = null;
    }

    public function takeProposal(int $id, MetaSuggestions $suggestions): void
    {
        $suggestions->takeProposal($suggestions->find($this->asset(), $id));
        DemoState::flash('Değişiklik önerisi alındı.', 'success');
    }

    /** CSV of the approved items for the manual apply in Ads Manager (UTF-8 BOM, ';'). */
    public function exportCsv(MetaSuggestions $suggestions): StreamedResponse
    {
        $lines = $suggestions->csv($this->asset());

        return response()->streamDownload(static function () use ($lines): void {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            foreach ($lines as $line) {
                fputcsv($handle, $line, ';', '"', '');
            }
            fclose($handle);
        }, 'meta-uygulanacaklar-'.$this->assetId.'-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /* ---------------- Ölçümleme: lead quality ---------------- */

    public function uploadLeads(MetaLeads $leads): void
    {
        $this->validate(['leadFile' => ['required', 'file', 'max:10240', 'extensions:csv,tsv,txt']], [], ['leadFile' => 'dosya']);
        try {
            $result = $leads->import($this->asset(), (string) $this->leadFile->get());
            DemoState::flash($result['created'].' yeni, '.$result['updated'].' güncellenen lead.', 'success');
        } catch (ValidationException $exception) {
            DemoState::flash((string) collect($exception->errors())->flatten()->first(), 'error');
        }
        $this->leadFile = null;
    }

    public function markLead(int $leadId, string $mark, MetaLeads $leads): void
    {
        try {
            $leads->mark($this->asset(), $leadId, $mark === '' ? null : $mark, auth()->user());
        } catch (ValidationException $exception) {
            DemoState::flash((string) collect($exception->errors())->flatten()->first(), 'error');
        }
    }

    public function render(MetaScreen $screen, MetaSuggestions $suggestions, MetaAssistant $assistant, MetaLeads $leads, MetaCampaignBoard $board, MetaAnalysis $analysis): View
    {
        $this->normalize();
        $asset = $this->asset()->loadMissing('brand.customer');
        $assetId = (int) $asset->id;
        $account = $screen->account($asset);
        $range = $this->dateRange();
        $hasBrand = $asset->brand_id !== null;
        $aiStates = [];
        foreach (array_keys(MetaAssistant::OPERATIONS) as $operation) {
            $aiStates[$operation] = $assistant->state($assetId, $operation);
        }

        return view('livewire.demo.meta.overview', [
            'asset' => $this->presentCanonicalAsset(),
            'tabs' => self::TABS,
            'brand' => $asset->brand,
            'operational' => (bool) $asset->brand?->isOperational(),
            'account' => $account,
            'settings' => $screen->settings($asset),
            'flash' => DemoState::pullFlash(),
            'openCount' => $hasBrand ? $suggestions->open($asset)->count() : 0,
            'aiStates' => $aiStates,
            'aiLabels' => MetaAssistant::LABELS,
            'board' => $this->tab === 'campaigns' ? $this->board($board->board($asset, $range)) : null,
            'checks' => $this->tab === 'todo' ? MetaChecks::states($assetId) : null,
            'suggestions' => $hasBrand && $this->tab === 'todo' ? $suggestions->open($asset) : collect(),
            'approved' => $hasBrand && $this->tab === 'todo' ? $suggestions->approved($asset) : collect(),
            'measurement' => $this->tab === 'measurement' ? $screen->measurement($asset, $range) : null,
            'leadList' => $this->tab === 'measurement' ? $leads->list($asset, $this->unmarkedOnly) : collect(),
            'leadCampaigns' => $this->tab === 'measurement' ? $leads->byCampaign($asset) : [],
            'analysis' => $this->tab === 'analysis' ? $analysis->analysis($asset, $range, $this->focus, $this->compare, $this->analysisType) : null,
            'geoState' => $this->tab === 'analysis' ? Cache::get(CollectMetaGeoResultsJob::stateKey($assetId)) : null,
            'range' => $range,
            'lastDay' => ($account !== null ? $screen->end($account) : CarbonImmutable::yesterday())->toDateString(),
            'ranged' => in_array($this->tab, ['campaigns', 'analysis', 'measurement'], true),
            'groupLabels' => MetaSuggestions::GROUP_LABELS,
            'suggestionGroup' => fn ($s): string => $suggestions->group($s),
        ]);
    }

    /**
     * The board with the filters applied (counts per status stay for the filter buttons).
     *
     * @param  array<string, mixed>  $board
     * @return array<string, mixed>
     */
    private function board(array $board): array
    {
        $rows = $board['rows'];
        $board['counts'] = ['live' => 0, 'paused' => 0, 'all' => count($rows)];
        foreach ($rows as $row) {
            if (isset($board['counts'][$row['status']])) {
                $board['counts'][$row['status']]++;
            }
        }
        $fold = fn (string $value): string => str_replace('ı', 'i', mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], $value)));
        $needle = $fold(trim($this->search));
        $board['rows'] = array_values(array_filter($rows, function (array $row) use ($needle, $fold): bool {
            if ($this->status !== 'all' && $row['status'] !== $this->status) {
                return false;
            }
            if ($this->service === 'none' && $row['services'] !== []) {
                return false;
            }
            if ($this->service !== '' && $this->service !== 'none' && ! in_array((int) $this->service, array_column($row['services'], 'id'), true)) {
                return false;
            }
            if ($this->resultType !== '' && $row['type'] !== $this->resultType) {
                return false;
            }

            return $needle === '' || str_contains($fold($row['name']), $needle);
        }));

        return $board;
    }

    private function normalize(): void
    {
        $this->tab = self::LEGACY_TAB_MAP[$this->tab] ?? $this->tab;
        if (! isset(self::TABS[$this->tab])) {
            $this->tab = 'campaigns';
        }
        if (! in_array($this->status, ['live', 'paused', 'all'], true)) {
            $this->status = 'all';
        }
        if (! array_key_exists($this->resultType, MetaCampaignBoard::TYPES)) {
            $this->resultType = '';
        }
        $this->normalizeDateRange();
        if (! in_array($this->analysisType, ['', 'leads', 'messages', 'purchases'], true)) {
            $this->analysisType = '';
        }
    }

    protected function asset(): DigitalAsset
    {
        return DigitalAsset::query()->whereKey((int) $this->assetId)->where('type', 'meta_ads')->firstOrFail();
    }
}
