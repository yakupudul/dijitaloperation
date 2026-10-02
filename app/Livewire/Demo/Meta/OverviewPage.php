<?php

namespace App\Livewire\Demo\Meta;

use App\Jobs\CollectMetaGeoResultsJob;
use App\Jobs\Meta\SyncMetaSuggestionsJob;
use App\Livewire\Demo\Concerns\ResolvesCanonicalOperatorAsset;
use App\Models\DigitalAsset;
use App\Services\Analyst\AnalystDecisionStore;
use App\Services\Async\AsyncOperationService;
use App\Services\Meta\MetaAssistant;
use App\Services\Meta\MetaChecks;
use App\Services\Meta\MetaLeads;
use App\Services\Meta\MetaScreen;
use App\Services\Meta\MetaSuggestions;
use App\Support\Demo\DemoState;
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
 * Meta (Faz 6): Genel Bakış · Yapılacaklar · Kreatifler · Kampanya Stratejisi · Ölçümleme · Analiz · Ayarlar for one
 * bound ad account. Numbers come from the collected Meta tables (MetaScreen); ten system checks and three AI
 * operations fill the ONE suggestions table (MetaChecks, MetaAssistant). Nothing is written to Meta: an approved item
 * is a copyable instruction / CSV row; "Uygulandı" stores the baseline. Lead quality is marked by hand (MetaLeads).
 */
#[Layout('operator.layouts.app')]
#[Title('Meta Reklamları')]
class OverviewPage extends Component
{
    use ResolvesCanonicalOperatorAsset;
    use WithFileUploads;

    public const array TABS = ['overview' => 'Genel Bakış', 'todo' => 'Yapılacaklar', 'creatives' => 'Kreatifler', 'strategy' => 'Kampanya Stratejisi',
        'measurement' => 'Ölçümleme', 'analysis' => 'Analiz', 'settings' => 'Ayarlar'];

    /** @var array<string, string> Retired tab keys kept working for old links. */
    private const array LEGACY_TAB_MAP = [
        'campaigns' => 'analysis', 'adsets' => 'analysis', 'ads' => 'analysis', 'audience' => 'analysis', 'breakdowns' => 'analysis',
        'delivery' => 'analysis', 'funnel' => 'analysis', 'advisor' => 'todo', 'operations' => 'todo', 'insights' => 'todo', 'destinations' => 'measurement',
    ];

    /** @var list<int> */
    private const array DAY_OPTIONS = [7, 28, 90];

    #[Locked]
    public string $assetId = '';

    #[Url]
    public string $tab = 'overview';

    /** Analiz window in days. */
    #[Url]
    public int $days = 28;

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

    public function setDays(int $days): void
    {
        $this->days = $days;
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
        DemoState::flash('Bölge verisi Meta’dan çekiliyor; birkaç dakika sürebilir.', 'info');
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

    public function render(MetaScreen $screen, MetaSuggestions $suggestions, MetaAssistant $assistant, MetaLeads $leads): View
    {
        $this->normalize();
        $asset = $this->asset()->loadMissing('brand.customer');
        $assetId = (int) $asset->id;
        $account = $screen->account($asset);
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
            'overview' => $this->tab === 'overview' ? $screen->overview($asset) : null,
            'checks' => $this->tab === 'todo' ? MetaChecks::states($assetId) : null,
            'suggestions' => $hasBrand && $this->tab === 'todo' ? $suggestions->open($asset) : collect(),
            'approved' => $hasBrand && $this->tab === 'todo' ? $suggestions->approved($asset) : collect(),
            'creativeRows' => $this->tab === 'creatives' ? $screen->creatives($asset, 28) : [],
            'creativeSuggestions' => $hasBrand && $this->tab === 'creatives' ? $suggestions->open($asset, 'creative') : collect(),
            'strategy' => $this->tab === 'strategy' ? $screen->analysis($asset, 28) : null,
            'strategySuggestions' => $hasBrand && $this->tab === 'strategy' ? $suggestions->open($asset, 'structure')->concat($suggestions->open($asset, 'landing')) : collect(),
            'measurement' => $this->tab === 'measurement' ? $screen->measurement($asset, 28) : null,
            'leadList' => $this->tab === 'measurement' ? $leads->list($asset, $this->unmarkedOnly) : collect(),
            'leadCampaigns' => $this->tab === 'measurement' ? $leads->byCampaign($asset) : [],
            'analysis' => $this->tab === 'analysis' ? $screen->analysis($asset, $this->days) : null,
            'geoState' => $this->tab === 'analysis' ? Cache::get(CollectMetaGeoResultsJob::stateKey($assetId)) : null,
            'dayOptions' => self::DAY_OPTIONS,
            'groupLabels' => MetaSuggestions::GROUP_LABELS,
            'suggestionGroup' => fn ($s): string => $suggestions->group($s),
        ]);
    }

    private function normalize(): void
    {
        $this->tab = self::LEGACY_TAB_MAP[$this->tab] ?? $this->tab;
        if (! isset(self::TABS[$this->tab])) {
            $this->tab = 'overview';
        }
        if (! in_array($this->days, self::DAY_OPTIONS, true)) {
            $this->days = 28;
        }
    }

    protected function asset(): DigitalAsset
    {
        return DigitalAsset::query()->whereKey((int) $this->assetId)->where('type', 'meta_ads')->firstOrFail();
    }
}
