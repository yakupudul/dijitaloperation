<?php

namespace App\Livewire\Operator\GoogleAds;

use App\Jobs\GoogleAds\SyncGoogleAdsSuggestionsJob;
use App\Livewire\Demo\Concerns\ResolvesCanonicalOperatorAsset;
use App\Livewire\Operator\Concerns\HasDateRange;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\Suggestion;
use App\Services\Analyst\AnalystDecisionStore;
use App\Services\Collection\GoogleAds\GoogleAdsCentralCollectionService;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\ExternalWrites\GoogleAdsNegativeListWriter;
use App\Services\GoogleAds\GoogleAdsAssistant;
use App\Services\GoogleAds\GoogleAdsEditorCsv;
use App\Services\GoogleAds\GoogleAdsLeadQuality;
use App\Services\GoogleAds\GoogleAdsScreen;
use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Services\GoogleAds\GoogleAdsSuggestions;
use App\Services\Site\Analysis\SiteRange;
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
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Google Ads (Faz 5): Genel Bakış · Yapılacaklar · Arama Terimleri · Kampanya Stratejisi · Ölçümleme · Analiz · Ayarlar
 * for one bound account. Numbers come from the collected google_ads_* tables (GoogleAdsScreen); suggestions from the ONE
 * suggestions table (GoogleAdsSuggestions: ≤ 10 system checks + AI drafts); AI work is queued (GoogleAdsAssistant). The
 * only write to Google is ADR-064 — Admin-approved shared-list negatives, undoable. Everything else becomes an approved
 * draft in the Google Ads Editor file the operator imports. Operator edits lock a draft; AI then only leaves a proposal.
 */
#[Layout('operator.layouts.app')]
#[Title('Google Ads')]
class OverviewPage extends Component
{
    use HasDateRange;
    use ResolvesCanonicalOperatorAsset;

    public const array TABS = ['overview' => 'Genel Bakış', 'todo' => 'Yapılacaklar', 'terms' => 'Arama Terimleri', 'strategy' => 'Kampanya Stratejisi',
        'measurement' => 'Ölçümleme', 'analysis' => 'Analiz', 'settings' => 'Ayarlar'];

    /** @var array<string, string> Retired tab keys kept working for old links. */
    private const array LEGACY_TAB_MAP = [
        'advisor' => 'todo', 'operations' => 'todo', 'optimization' => 'todo', 'landing_pages' => 'todo',
        'search_demand' => 'terms', 'search_terms' => 'terms',
        'budget_bidding' => 'strategy', 'ads' => 'strategy', 'ads_assets' => 'strategy',
        'conversions' => 'measurement',
        'campaigns' => 'analysis', 'adgroups' => 'analysis', 'keywords' => 'analysis', 'performance' => 'analysis', 'auction_insights' => 'analysis',
        'changes' => 'analysis', 'pmax' => 'analysis', 'shopping' => 'analysis', 'video' => 'analysis',
        'data_connection' => 'settings', 'insights' => 'overview',
    ];

    #[Locked]
    public string $assetId = '';

    #[Url]
    public string $tab = 'overview';

    /** Date picker (Genel Bakış, Arama terimleri, Analiz): preset days, or a custom start / end (HasDateRange), and the comparison. */
    #[Url]
    public int $days = 28;

    #[Url(as: 'kars')]
    public string $compare = SiteRange::COMPARE_PREVIOUS;

    #[Url]
    public string $level = 'campaign';

    /** Arama Terimleri filter (contains). */
    #[Url(as: 'q')]
    public string $termFilter = '';

    /** @var list<string> terms selected for "Negatif öner" */
    public array $selectedTerms = [];

    /** @var list<int> shared-list negative suggestions selected for the Admin send */
    public array $selectedNegatives = [];

    /** Reklam metni: target ad group key. */
    public string $adGroupKey = '';

    public ?int $editingId = null;

    /** Ölçümleme: lead quality month (Y-m). */
    public string $leadMonth = '';

    /** @var array<string, array<string, int|string>> campaign id => lead quality counts being entered */
    public array $leadRows = [];

    /** @var array<string, mixed> */
    public array $edit = [];

    public function mount(?string $assetId = null): void
    {
        $asset = $this->bindCanonicalAsset($assetId, ['google_ads']);
        $this->normalize();
        // First visit: the system checks are computed once in the background (then daily).
        if ($asset->brand_id !== null && Cache::add('google-ads-suggestions-seeded:'.$asset->id, true, now()->addHour())
            && app(GoogleAdsSuggestions::class)->checkStates($asset) === null) {
            SyncGoogleAdsSuggestionsJob::dispatch((int) $asset->id);
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->editingId = null;
        $this->normalize();
        $this->leadRows = [];
    }

    /* ---------------- Ölçümleme: lead quality ---------------- */

    public function setLeadMonth(string $month): void
    {
        $this->leadMonth = in_array($month, GoogleAdsLeadQuality::monthOptions(), true) ? $month : now()->format('Y-m');
        $this->leadRows = [];
    }

    public function saveLeadQuality(string $campaignId, GoogleAdsLeadQuality $quality): void
    {
        $row = collect($quality->month($this->asset(), $this->leadMonth()))->firstWhere('campaign_id', $campaignId);
        if ($row === null) {
            DemoState::flash('Kampanya bulunamadı.', 'error');

            return;
        }
        try {
            $quality->save($this->asset(), $this->leadMonth(), $campaignId, $row['name'], (array) ($this->leadRows[$campaignId] ?? []), auth()->user());
            DemoState::flash($row['name'].': kaydedildi.', 'success');
        } catch (ValidationException $exception) {
            DemoState::flash((string) collect($exception->errors())->flatten()->first(), 'error');
        }
    }

    private function leadMonth(): string
    {
        return in_array($this->leadMonth, GoogleAdsLeadQuality::monthOptions(), true) ? $this->leadMonth : now()->format('Y-m');
    }

    public function setLevel(string $level): void
    {
        $this->level = $level;
        $this->normalize();
    }

    public function refreshData(): void
    {
        $binding = app(GoogleAdsSpecialistBindingResolver::class)->resolve($this->assetId);
        $resource = $binding->isReal() ? CoreExternalResource::query()->with('integration')->find($binding->externalResourceId) : null;
        if ($resource === null || $resource->integration === null) {
            DemoState::flash('Google Ads hesabı bağlı değil.', 'info');

            return;
        }
        try {
            app(GoogleAdsCentralCollectionService::class)->startSmartUpdate($resource->integration, [(int) $resource->id], auth()->user());
            DemoState::flash('Google Ads verisi çekiliyor.', 'success');
        } catch (Throwable $exception) {
            DemoState::flash('Veri çekme başlatılamadı: '.$exception->getMessage(), 'warning');
        }
    }

    /* ---------------- Yapılacaklar ---------------- */

    public function recheck(): void
    {
        SyncGoogleAdsSuggestionsJob::dispatch((int) $this->asset()->id);
        DemoState::flash('Sistem kontrolleri yeniden çalışıyor.', 'info');
    }

    public function approveSuggestion(int $id, GoogleAdsSuggestions $suggestions): void
    {
        $suggestion = $suggestions->find($this->asset(), $id);
        if ($suggestion->action_type === 'ads_negative' && ($suggestion->action['scope'] ?? '') === 'shared'
            && ExternalWriteService::allowed(auth()->user(), ExternalWriteAction::CHANNEL_GOOGLE_ADS)) {
            $this->selectedNegatives = [$id];
            $this->sendNegatives(app(ExternalWriteService::class), $suggestions);

            return;
        }
        $suggestions->approve($suggestion, auth()->user());
        DemoState::flash(match (true) {
            $suggestion->action_type === 'ads_negative' && ($suggestion->action['scope'] ?? '') === 'shared' => 'Onaylandı; göndermeyi Admin yapar.',
            in_array($suggestion->action_type, GoogleAdsSuggestions::EDITOR_TYPES, true) => 'Onaylandı; Editor dosyasına eklendi.',
            default => 'Onaylandı.',
        }, 'success');
    }

    public function dismissSuggestion(int $id, GoogleAdsSuggestions $suggestions, AnalystDecisionStore $store): void
    {
        $store->dismiss($suggestions->find($this->asset(), $id), auth()->user());
        DemoState::flash('Reddedildi.', 'info');
    }

    public function snoozeSuggestion(int $id, GoogleAdsSuggestions $suggestions, AnalystDecisionStore $store): void
    {
        $store->snooze($suggestions->find($this->asset(), $id), 7);
        DemoState::flash('7 gün ertelendi.', 'info');
    }

    /** ADR-064: the selected shared-list negatives go to Google in one Admin-approved write (undo from the list). */
    public function sendNegatives(ExternalWriteService $writes, GoogleAdsSuggestions $suggestions): void
    {
        abort_unless(ExternalWriteService::allowed(auth()->user(), ExternalWriteAction::CHANNEL_GOOGLE_ADS), 403);
        try {
            $suggestions->sendNegatives($this->asset(), auth()->user(), array_map('intval', $this->selectedNegatives), $writes);
            $this->selectedNegatives = [];
            DemoState::flash('Negatifler Google Ads paylaşılan listesine gönderiliyor.', 'info');
        } catch (ValidationException $exception) {
            DemoState::flash((string) collect($exception->errors())->flatten()->first(), 'error');
        }
    }

    public function undoWrite(int $actionId, ExternalWriteService $writes): void
    {
        $action = ExternalWriteAction::query()->whereKey($actionId)->where('channel', ExternalWriteAction::CHANNEL_GOOGLE_ADS)
            ->where('digital_asset_id', $this->asset()->id)->firstOrFail();
        try {
            $writes->requestUndo(auth()->user(), $action);
            DemoState::flash('Geri alınıyor.', 'info');
        } catch (ValidationException $exception) {
            DemoState::flash((string) collect($exception->errors())->flatten()->first(), 'error');
        }
    }

    /**
     * Google Ads Editor file of the approved drafts not downloaded yet, or again of a downloaded batch. Downloading
     * changes nothing on Google: the drafts stay approved until "Editor'a aktardım".
     */
    public function downloadEditor(GoogleAdsSuggestions $suggestions, ?string $batch = null): ?StreamedResponse
    {
        $asset = $this->asset();
        $approved = $batch !== null ? $suggestions->editorBatches($asset)->get($batch, collect()) : $suggestions->editorDrafts($asset);
        if ($approved->isEmpty()) {
            DemoState::flash('Editor dosyası için onaylı taslak yok.', 'info');

            return null;
        }
        $csv = GoogleAdsEditorCsv::build($approved);
        if ($batch === null) {
            $suggestions->markDownloaded($approved);
        }

        return response()->streamDownload(function () use ($csv): void {
            echo $csv;
        }, 'google-ads-editor-'.$asset->id.'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** "Editor'a aktardım": the downloaded batch was posted from Google Ads Editor; its drafts are applied (baseline). */
    public function confirmEditorBatch(string $batch, GoogleAdsSuggestions $suggestions): void
    {
        $count = $suggestions->confirmEditorBatch($this->asset(), $batch, auth()->user());
        DemoState::flash($count > 0 ? $count.' taslak uygulandı olarak işaretlendi.' : 'Bu dosyada bekleyen taslak yok.', $count > 0 ? 'success' : 'info');
    }

    /* ---------------- draft edits (locked) ---------------- */

    public function startEdit(int $id, GoogleAdsSuggestions $suggestions): void
    {
        $s = $suggestions->find($this->asset(), $id);
        $a = (array) $s->action;
        $this->edit = match ($s->action_type) {
            'ads_rsa' => ['headlines' => implode("\n", (array) $a['headlines']), 'descriptions' => implode("\n", (array) $a['descriptions']),
                'final_url' => (string) $a['final_url'], 'path1' => (string) ($a['path1'] ?? ''), 'path2' => (string) ($a['path2'] ?? '')],
            'ads_negative' => ['text' => (string) $a['text'], 'match_type' => (string) $a['match_type'], 'scope' => (string) $a['scope'],
                'campaign' => (string) ($a['campaign'] ?? ''), 'ad_group' => (string) ($a['ad_group'] ?? '')],
            'ads_campaign' => ['name' => (string) $a['name'], 'daily_budget' => (string) $a['daily_budget']],
            default => [],
        };
        $this->editingId = $this->edit === [] ? null : $id;
        $this->resetValidation();
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->edit = [];
        $this->resetValidation();
    }

    public function saveEdit(GoogleAdsSuggestions $suggestions): void
    {
        $s = $suggestions->find($this->asset(), (int) $this->editingId);
        $action = (array) $s->action;
        unset($action['proposal'], $action['locked']);
        if ($s->action_type === 'ads_rsa') {
            $data = $this->validate([
                'edit.final_url' => ['required', 'url', 'max:500'],
                'edit.path1' => ['nullable', 'string', 'max:'.GoogleAdsAssistant::PATH_MAX],
                'edit.path2' => ['nullable', 'string', 'max:'.GoogleAdsAssistant::PATH_MAX],
            ], [], ['edit.final_url' => 'son URL'])['edit'];
            $headlines = self::lines((string) ($this->edit['headlines'] ?? ''));
            $descriptions = self::lines((string) ($this->edit['descriptions'] ?? ''));
            foreach ([['headlines', $headlines, GoogleAdsAssistant::HEADLINE_MAX, 3, GoogleAdsEditorCsv::HEADLINES, 'Başlık'], ['descriptions', $descriptions, GoogleAdsAssistant::DESCRIPTION_MAX, 2, GoogleAdsEditorCsv::DESCRIPTIONS, 'Açıklama']] as [$field, $texts, $max, $min, $limit, $label]) {
                $long = array_values(array_filter($texts, fn (string $t): bool => mb_strlen($t) > $max));
                if ($long !== [] || count($texts) < $min || count($texts) > $limit) {
                    $this->addError('edit.'.$field, $long !== [] ? $label.' en çok '.$max.' karakter: «'.$long[0].'».' : $label.' sayısı '.$min.'–'.$limit.' olmalı.');

                    return;
                }
            }
            $blocking = GoogleAdsAssistant::blockingHits($this->asset()->loadMissing('brand')->brand, implode("\n", [...$headlines, ...$descriptions]));
            if ($blocking !== []) {
                $this->addError('edit.headlines', 'Sektör uyum kuralına takılıyor: '.implode(', ', $blocking).'.');

                return;
            }
            $action = ['headlines' => $headlines, 'descriptions' => $descriptions, 'final_url' => $data['final_url'],
                'path1' => (string) ($data['path1'] ?? ''), 'path2' => (string) ($data['path2'] ?? '')] + $action;
        } elseif ($s->action_type === 'ads_negative') {
            $data = $this->validate([
                'edit.text' => ['required', 'string', 'max:80'], 'edit.match_type' => ['required', 'in:EXACT,PHRASE,BROAD'],
                'edit.scope' => ['required', 'in:shared,campaign,ad_group'], 'edit.campaign' => ['required_unless:edit.scope,shared', 'nullable', 'string', 'max:255'],
                'edit.ad_group' => ['required_if:edit.scope,ad_group', 'nullable', 'string', 'max:255'],
            ], [], ['edit.text' => 'negatif', 'edit.campaign' => 'kampanya', 'edit.ad_group' => 'reklam grubu'])['edit'];
            $parsed = GoogleAdsNegativeListWriter::parse('['.trim((string) $data['text']).']')['keywords'][0] ?? null;
            if ($parsed === null) {
                $this->addError('edit.text', 'Geçersiz negatif anahtar kelime.');

                return;
            }
            $shared = $data['scope'] === 'shared';
            $action = ['text' => $parsed['text'], 'match_type' => $shared && $data['match_type'] === 'BROAD' ? 'PHRASE' : $data['match_type'], 'scope' => $data['scope'],
                'campaign' => $shared ? '' : (string) $data['campaign'], 'ad_group' => $data['scope'] === 'ad_group' ? (string) $data['ad_group'] : ''] + $action;
        } else {
            $data = $this->validate(['edit.name' => ['required', 'string', 'max:100'], 'edit.daily_budget' => ['required', 'numeric', 'min:1']],
                [], ['edit.name' => 'kampanya adı', 'edit.daily_budget' => 'günlük bütçe'])['edit'];
            $action = ['name' => trim((string) $data['name']), 'daily_budget' => round((float) $data['daily_budget'], 2)] + $action;
        }
        $suggestions->saveEdit($s, $action);
        $this->cancelEdit();
        DemoState::flash('Kaydedildi; AI bu taslağın üzerine yazmaz.', 'success');
    }

    public function acceptProposal(int $id, GoogleAdsSuggestions $suggestions): void
    {
        $suggestions->acceptProposal($suggestions->find($this->asset(), $id));
        DemoState::flash('AI önerisi uygulandı.', 'success');
    }

    /* ---------------- AI ---------------- */

    public function reviewTerms(GoogleAdsAssistant $assistant): void
    {
        $this->queueAssistant($assistant, GoogleAdsAssistant::OP_TERMS);
    }

    /** "Negatif öner": the selected search terms only. */
    public function proposeNegatives(GoogleAdsAssistant $assistant): void
    {
        $terms = array_values(array_filter(array_map(fn ($t): string => trim((string) $t), $this->selectedTerms)));
        if ($terms === []) {
            DemoState::flash('Terim seçin.', 'info');

            return;
        }
        $this->queueAssistant($assistant, GoogleAdsAssistant::OP_TERMS, ['terms' => array_slice($terms, 0, 200)]);
        $this->selectedTerms = [];
    }

    public function proposeStructure(GoogleAdsAssistant $assistant): void
    {
        $this->queueAssistant($assistant, GoogleAdsAssistant::OP_STRUCTURE);
    }

    public function writeAds(GoogleAdsAssistant $assistant): void
    {
        $this->queueAssistant($assistant, GoogleAdsAssistant::OP_ADS, ['ad_group' => $this->adGroupKey]);
    }

    public function render(GoogleAdsScreen $screen, GoogleAdsSuggestions $suggestions, GoogleAdsAssistant $assistant, GoogleAdsLeadQuality $quality): View
    {
        $this->normalize();
        $asset = $this->asset()->loadMissing('brand.customer');
        $assetId = (int) $asset->id;
        $context = $screen->context($asset);
        $bound = $context !== null;
        $range = $this->dateRange();
        $leadQuality = $this->tab === 'measurement' ? $quality->month($asset, $this->leadMonth()) : [];
        foreach ($leadQuality as $row) {
            $this->leadRows[$row['campaign_id']] ??= $row['entry'] ?? array_fill_keys(array_keys(GoogleAdsLeadQuality::FIELDS), '');
        }
        $open = $asset->brand_id !== null ? $suggestions->open($asset) : collect();
        $state = fn (string $op): ?array => $assistant->state($assetId, $op);
        $terms = $this->tab === 'terms' && $bound ? $screen->searchTerms($asset, $range) : [];
        if ($this->termFilter !== '') {
            $needle = mb_strtolower(trim($this->termFilter));
            $terms = array_values(array_filter($terms, fn (array $t): bool => str_contains(mb_strtolower($t['term']), $needle)));
        }

        return view('livewire.operator.google-ads.overview', [
            'asset' => $this->presentCanonicalAsset(),
            'assetModel' => $asset,
            'tabs' => self::TABS,
            'bound' => $bound,
            'operational' => (bool) $asset->brand?->isOperational(),
            'flash' => DemoState::pullFlash(),
            'canWrite' => ExternalWriteService::allowed(auth()->user(), ExternalWriteAction::CHANNEL_GOOGLE_ADS),
            'numbers' => $this->tab === 'overview' && $bound ? $screen->overview($asset, $range) : null,
            'checks' => in_array($this->tab, ['overview', 'measurement'], true) ? $suggestions->checkStates($asset) : null,
            'openCount' => $open->count(),
            'suggestions' => $this->tab === 'todo' ? $open->reject(fn (Suggestion $s): bool => in_array($s->action_type, ['ads_campaign', 'ads_budget_split', 'ads_experiments', 'ads_rsa'], true))->values() : collect(),
            'strategy' => $this->tab === 'strategy' ? $open->filter(fn (Suggestion $s): bool => in_array($s->action_type, ['ads_campaign', 'ads_budget_split', 'ads_experiments', 'ads_rsa'], true))->values() : collect(),
            'approved' => in_array($this->tab, ['todo', 'strategy'], true) && $asset->brand_id !== null ? $suggestions->approved($asset) : collect(),
            'writes' => $this->tab === 'todo' ? $suggestions->negativeWrites($asset) : collect(),
            'termsState' => $state(GoogleAdsAssistant::OP_TERMS),
            'structureState' => $state(GoogleAdsAssistant::OP_STRUCTURE),
            'adsState' => $state(GoogleAdsAssistant::OP_ADS),
            'terms' => $terms,
            'adGroups' => $this->tab === 'strategy' && $bound ? $assistant->adGroupOptions($asset) : [],
            'conversionActions' => $this->tab === 'measurement' && $bound ? $screen->conversionActions($asset) : [],
            'leadQuality' => $leadQuality,
            'leadMonthValue' => $this->leadMonth(),
            'leadMonths' => GoogleAdsLeadQuality::monthOptions(),
            'leadFields' => GoogleAdsLeadQuality::FIELDS,
            'analysis' => $this->tab === 'analysis' && $bound ? $screen->analysis($asset, $this->level, $range) : [],
            'levels' => GoogleAdsScreen::LEVELS,
            'settings' => $this->tab === 'settings' ? $screen->settings($asset) : null,
            'range' => $range,
            'lastDay' => ($context['end'] ?? CarbonImmutable::yesterday())->toDateString(),
            'ranged' => in_array($this->tab, ['overview', 'terms', 'analysis'], true),
        ]);
    }

    /** @param  array{terms?: list<string>, ad_group?: string}  $params */
    private function queueAssistant(GoogleAdsAssistant $assistant, string $operation, array $params = []): void
    {
        try {
            $assistant->queue($this->asset(), $operation, $params);
            DemoState::flash('AI çalışıyor; birkaç saniye sonra burada görünür.', 'info');
        } catch (ValidationException $exception) {
            DemoState::flash((string) collect($exception->errors())->flatten()->first(), 'error');
        }
    }

    private function normalize(): void
    {
        $this->tab = self::LEGACY_TAB_MAP[$this->tab] ?? $this->tab;
        if (! isset(self::TABS[$this->tab])) {
            $this->tab = 'overview';
        }
        $this->normalizeDateRange();
        if (! isset(GoogleAdsScreen::LEVELS[$this->level])) {
            $this->level = 'campaign';
        }
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        return array_values(array_filter(array_map(fn (string $l): string => trim(preg_replace('/\s+/u', ' ', $l) ?? ''), preg_split('/\R/u', $text) ?: [])));
    }

    private function asset(): DigitalAsset
    {
        return DigitalAsset::query()->whereKey((int) $this->assetId)->where('type', 'google_ads')->firstOrFail();
    }
}
