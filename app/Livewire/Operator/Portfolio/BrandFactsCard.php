<?php

namespace App\Livewire\Operator\Portfolio;

use App\Models\Brand;
use App\Services\Brand\BrandFacts;
use App\Services\Queries\KeywordPlanner;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Marka › Özet › Marka bilgi kartı: the brand facts the AI steps read, each with its source. "Düzenle" writes the
 * operator's text and locks the field from the nightly rebuild; "Otomatiğe dön" unlocks it; "Yenile" rebuilds now.
 */
final class BrandFactsCard extends Component
{
    #[Locked]
    public int $brandId;

    public ?string $editing = null;

    public string $text = '';

    public bool $rebuild = false;

    public function mount(int $brandId): void
    {
        $this->brandId = $brandId;
    }

    /** Loaded after the page (lazy): the first build reads reviews and Search Console. */
    public function placeholder(): string
    {
        return '<div></div>';
    }

    public function edit(string $key): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        $field = app(BrandFacts::class)->card($this->brand())['fields'][$key] ?? null;
        if ($field === null) {
            return;
        }
        $this->editing = $key;
        $this->text = $field['locked'] ? (string) $field['manual'] : implode("\n", $field['items']);
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        if ($this->editing !== null) {
            BrandFacts::write($this->brand(), $this->editing, $this->text, auth()->id());
        }
        $this->cancel();
    }

    public function unlock(string $key): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        BrandFacts::write($this->brand(), $key, '', auth()->id());
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->text = '';
    }

    public function refreshFacts(): void
    {
        $this->rebuild = true;
    }

    public function render(BrandFacts $facts): View
    {
        $card = $this->rebuild ? $facts->build($this->brand()) : $facts->card($this->brand());
        $this->rebuild = false;

        return view('livewire.operator.portfolio.brand-facts-card', ['card' => $card, 'planner' => KeywordPlanner::lastRun($this->brandId), 'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN)]);
    }

    private function brand(): Brand
    {
        return Brand::query()->findOrFail($this->brandId);
    }
}
