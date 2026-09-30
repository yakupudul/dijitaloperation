<?php

namespace App\Livewire\Operator\Website\V2;

use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Site\ChangeApplier;
use App\Services\Site\ScopedStandards;
use App\Services\Site\SiteDiff;
use App\Services\Site\SiteOperations;
use App\Services\Site\SiteSuggestions;
use App\Services\Site\SiteSuggestionTypes;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * SEO Yapılacaklar › Öneriler: URL analysis suggestions of this site with filters (URL, tür, durum). Actions: Onayla,
 * Reddet (reason), "AI ile yap" (new version next to the current one, compliance-checked; Onayla → WordPress with
 * undo), "Bu karardan standart öner" (edit + approve → scoped, versioned standard).
 */
final class SuggestionsTab extends Component
{
    use WithPagination;

    #[Locked]
    public int $assetId = 0;

    #[Url(as: 'url')]
    public string $pageFilter = '';

    #[Url(as: 'tur')]
    public string $type = '';

    #[Url(as: 'durum')]
    public string $status = 'open';

    /** @var array<int, string> */
    public array $reasons = [];

    public ?int $openId = null;

    /** @var array{title?: string, rule?: string, condition?: string, exceptions?: string, scope?: string} */
    public array $standard = [];

    public string $message = '';

    public const array STATUS_LABELS = ['open' => 'açık', 'approved' => 'onaylı', 'applied' => 'uygulandı', 'dismissed' => 'reddedildi', 'recheck' => 'yeniden kontrol gerekli'];

    public function mount(int $assetId): void
    {
        $this->assetId = $assetId;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['pageFilter', 'type', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function open(int $id): void
    {
        $this->openId = $this->openId === $id ? null : $id;
        $draft = (array) data_get($this->suggestion($id)->action, 'standard_draft', []);
        $this->standard = $draft;
    }

    public function approve(int $id, SiteSuggestions $suggestions): void
    {
        $suggestions->approve($this->suggestion($id), auth()->user());
        $this->message = 'Onaylandı.';
    }

    public function dismiss(int $id, SiteSuggestions $suggestions): void
    {
        $suggestions->dismiss($this->suggestion($id), auth()->user(), (string) ($this->reasons[$id] ?? ''));
        unset($this->reasons[$id]);
        $this->message = 'Reddedildi.';
    }

    public function aiDo(int $id): void
    {
        $suggestion = $this->suggestion($id);
        if (! SiteSuggestionTypes::applicable((string) $suggestion->action_type)) {
            $this->message = 'Bu öneri türü elle uygulanır.';

            return;
        }
        SiteOperations::dispatch($this->assetId, SiteOperations::APPLY_CHANGE, ['suggestion_id' => $id]);
        $this->openId = $id;
        $this->message = 'Yeni sürüm hazırlanıyor.';
    }

    public function applyChange(int $id, ChangeApplier $changes): void
    {
        $count = count($changes->approve($this->suggestion($id), auth()->user()));
        $this->message = $count.' WordPress işlemi kuyruğa alındı (geri alınabilir).';
    }

    public function publishDraft(int $id, ChangeApplier $changes): void
    {
        $changes->publishDraft($this->suggestion($id), auth()->user());
        $this->message = 'Taslak canlıya alınıyor (geri alınabilir).';
    }

    public function undo(int $writeId, ExternalWriteService $writes): void
    {
        $action = ExternalWriteAction::query()->whereIn('suggestion_id', Suggestion::query()->where('brand_id', $this->brandId())->select('id'))->findOrFail($writeId);
        $writes->requestUndo(auth()->user(), $action);
        $this->message = 'Geri alma kuyruğa alındı.';
    }

    public function proposeStandard(int $id): void
    {
        $this->suggestion($id);
        SiteOperations::dispatch($this->assetId, SiteOperations::STANDARD, ['suggestion_id' => $id]);
        $this->openId = $id;
        $this->message = 'Standart önerisi hazırlanıyor.';
    }

    public function saveStandard(int $id, ScopedStandards $standards): void
    {
        $standards->save($this->suggestion($id), $this->standard, auth()->user());
        $this->standard = [];
        $this->message = 'Standart kütüphaneye eklendi (sürüm 1).';
    }

    public function render(): View
    {
        $site = DigitalAsset::query()->findOrFail($this->assetId);
        $query = Suggestion::query()->with('page:id,url,path')->where('brand_id', (int) $site->brand_id)->where('channel', 'search')
            ->where('action_type', '!=', SiteSuggestionTypes::CONTENT)
            ->where(fn (Builder $q) => $this->ofSite($q))
            ->when($this->pageFilter !== '' && ctype_digit($this->pageFilter), fn (Builder $q) => $q->where('page_id', (int) $this->pageFilter))
            ->when($this->type !== '', fn (Builder $q) => $q->where('action_type', $this->type))
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status));
        $suggestions = $query->orderBy('priority')->orderByDesc('id')->paginate(30);
        $opened = $this->openId !== null ? $suggestions->getCollection()->firstWhere('id', $this->openId) : null;
        if ($opened !== null && $this->standard === [] && is_array(data_get($opened->action, 'standard_draft'))) {
            $this->standard = (array) data_get($opened->action, 'standard_draft');
        }
        $proposal = $opened !== null ? (array) data_get($opened->action, 'proposal', []) : [];

        return view('livewire.operator.website.v2.suggestions-tab', [
            'suggestions' => $suggestions,
            'opened' => $opened,
            'proposal' => $proposal,
            'diff' => isset($proposal['new']['html']) ? SiteDiff::html((string) ($proposal['current']['html'] ?? ''), (string) $proposal['new']['html']) : null,
            'writes' => $opened !== null ? ExternalWriteAction::query()->where('suggestion_id', $opened->id)->orderBy('id')->get() : collect(),
            'pageOptions' => Page::query()->where('website_asset_id', $site->id)->whereIn('id', Suggestion::query()->where('brand_id', (int) $site->brand_id)->whereNotNull('page_id')->select('page_id'))->orderBy('path')->pluck('path', 'id')->all(),
            'applyStatus' => $opened !== null ? SiteOperations::line(SiteOperations::status($site->id, SiteOperations::APPLY_CHANGE, ['suggestion_id' => $opened->id])) : null,
            'standardStatus' => $opened !== null ? SiteOperations::line(SiteOperations::status($site->id, SiteOperations::STANDARD, ['suggestion_id' => $opened->id])) : null,
        ]);
    }

    private function brandId(): int
    {
        return (int) DigitalAsset::query()->whereKey($this->assetId)->value('brand_id');
    }

    private function suggestion(int $id): Suggestion
    {
        return Suggestion::query()->where('brand_id', $this->brandId())->where(fn (Builder $q) => $this->ofSite($q))->findOrFail($id);
    }

    /**
     * Suggestions of this site's pages, and those whose page was deleted (kept for the re-check, site in the action).
     *
     * @param  Builder<Suggestion>  $query
     * @return Builder<Suggestion>
     */
    private function ofSite(Builder $query): Builder
    {
        return $query->whereIn('page_id', Page::query()->where('website_asset_id', $this->assetId)->select('id'))
            ->orWhere(fn (Builder $gone) => $gone->whereNull('page_id')->where('status', Suggestion::RECHECK)->where('action->site_id', $this->assetId));
    }
}
