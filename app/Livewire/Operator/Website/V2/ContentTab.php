<?php

namespace App\Livewire\Operator\Website\V2;

use App\Models\Cluster;
use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Services\Site\ContentPlanner;
use App\Services\Site\SiteOperations;
use App\Services\Site\SiteSuggestions;
use App\Services\Site\SiteSuggestionTypes;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * SEO Yapılacaklar › İçerik: "Haftalık içerik öner" and "Kümeler dışında fırsat keşfet" (queued AI), the content
 * suggestions (title, kind, page type, cluster, target URL, outline, questions people ask AI assistants), "Kütüphaneye
 * ekle" for out-of-cluster ones, "Taslak hazırla" (AI article, compliance) → "WordPress'e taslak gönder" (Admin).
 */
final class ContentTab extends Component
{
    use WithPagination;

    #[Locked]
    public int $assetId = 0;

    #[Url(as: 'durum')]
    public string $status = 'open';

    public ?int $openId = null;

    /** @var array<int, string> */
    public array $reasons = [];

    public string $message = '';

    public function mount(int $assetId): void
    {
        $this->assetId = $assetId;
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function run(string $operation): void
    {
        if (! in_array($operation, [SiteOperations::WEEKLY_CONTENT, SiteOperations::DISCOVERY], true)) {
            return;
        }
        SiteOperations::dispatch($this->assetId, $operation);
        $this->message = SiteOperations::LABELS[$operation].' kuyruğa alındı.';
    }

    public function open(int $id): void
    {
        $this->openId = $this->openId === $id ? null : $id;
    }

    public function addToLibrary(int $id, ContentPlanner $planner): void
    {
        $cluster = $planner->addToLibrary($this->suggestion($id));
        $this->message = '"'.$cluster->name.'" kümesi kütüphaneye eklendi (onay bekliyor).';
    }

    public function prepareDraft(int $id): void
    {
        $this->suggestion($id);
        SiteOperations::dispatch($this->assetId, SiteOperations::WRITE_ARTICLE, ['suggestion_id' => $id]);
        $this->openId = $id;
        $this->message = 'Taslak hazırlanıyor.';
    }

    public function sendDraft(int $id, ContentPlanner $planner): void
    {
        $planner->sendDraft($this->suggestion($id), auth()->user());
        $this->message = 'WordPress taslağı kuyruğa alındı (geri alınabilir).';
    }

    public function dismiss(int $id, SiteSuggestions $suggestions): void
    {
        $suggestions->dismiss($this->suggestion($id), auth()->user(), (string) ($this->reasons[$id] ?? ''));
        $this->message = 'Reddedildi.';
    }

    public function render(): View
    {
        $site = DigitalAsset::query()->findOrFail($this->assetId);
        $items = Suggestion::query()->where('brand_id', (int) $site->brand_id)->where('action_type', SiteSuggestionTypes::CONTENT)
            ->where('action->site_id', $site->id)
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->orderByDesc('id')->paginate(30);
        $clusterNames = Cluster::query()->whereIn('id', $items->getCollection()->pluck('cluster_id')->filter())->pluck('name', 'id')->all();
        $opened = $this->openId !== null ? $items->getCollection()->firstWhere('id', $this->openId) : null;

        return view('livewire.operator.website.v2.content-tab', [
            'items' => $items,
            'clusterNames' => $clusterNames,
            'opened' => $opened,
            'capacity' => (int) ($site->brand?->weekly_content_capacity ?? 4),
            'statuses' => collect([SiteOperations::WEEKLY_CONTENT, SiteOperations::DISCOVERY])
                ->mapWithKeys(fn (string $op): array => [$op => SiteOperations::line(SiteOperations::status($site->id, $op))])->filter()->all(),
            'draftStatus' => $opened !== null ? SiteOperations::line(SiteOperations::status($site->id, SiteOperations::WRITE_ARTICLE, ['suggestion_id' => $opened->id])) : null,
        ]);
    }

    private function suggestion(int $id): Suggestion
    {
        return Suggestion::query()->where('brand_id', (int) DigitalAsset::query()->whereKey($this->assetId)->value('brand_id'))
            ->where('action_type', SiteSuggestionTypes::CONTENT)->findOrFail($id);
    }
}
