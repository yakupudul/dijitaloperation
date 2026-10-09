<?php

namespace App\Livewire\Operator\Repair;

use App\Models\Brand;
use App\Models\ExternalWriteAction;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Repair\RepairDesk;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Onarım masası: every prepared fix (eski → yeni) across brands; approve one or many (high risk one by one), edit a value
 * before approving, reject with a reason, and undo what was applied in the last 7 days.
 */
#[Layout('operator.layouts.app')]
#[Title('Onarım masası')]
final class RepairDeskPage extends Component
{
    #[Url(as: 'marka')]
    public ?int $brandId = null;

    #[Url(as: 'tur')]
    public string $kind = '';

    #[Url(as: 'risk')]
    public string $risk = '';

    /** @var list<int> */
    public array $selected = [];

    public string $message = '';

    public ?int $editing = null;

    public string $editField = '';

    public string $editValue = '';

    public string $rejectReason = '';

    public function approve(int $id, RepairDesk $desk): void
    {
        $this->run(fn (): array => $desk->approve([$id], auth()->user()));
    }

    public function approveSelected(RepairDesk $desk): void
    {
        $this->run(fn (): array => $desk->approve($this->selected, auth()->user()));
        $this->selected = [];
    }

    /** Every visible low-risk row (the filters apply). */
    public function approveAllLow(RepairDesk $desk): void
    {
        $ids = $desk->rows($this->brandId, $this->kind ?: null, RepairDesk::LOW)->pluck('id')->all();
        $this->run(fn (): array => $desk->approve($ids, auth()->user()));
    }

    /** Select the rows on screen (the first 300 of the filter). */
    public function selectVisible(RepairDesk $desk): void
    {
        $this->selected = $this->filtered($desk)->take(300)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /** Select every row the current filters match, also those beyond the first 300. */
    public function selectAllMatching(RepairDesk $desk): void
    {
        $this->selected = $this->filtered($desk)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    /** A filter change drops the selection so hidden rows are never approved by mistake. */
    public function updated(string $property): void
    {
        if (in_array($property, ['brandId', 'kind', 'risk'], true)) {
            $this->selected = [];
        }
    }

    public function rejectSelected(RepairDesk $desk): void
    {
        $this->authorizeAdmin();
        $count = $desk->reject($this->selected, auth()->user(), $this->rejectReason);
        $this->selected = [];
        $this->rejectReason = '';
        $this->message = $count.' öneri reddedildi; kanıt değişmedikçe geri gelmez.';
    }

    public function startEdit(int $id, string $field, string $value): void
    {
        $this->editing = $id;
        $this->editField = $field;
        $this->editValue = $value;
    }

    public function saveEdit(RepairDesk $desk): void
    {
        $this->authorizeAdmin();
        if ($this->editing === null) {
            return;
        }
        try {
            $desk->edit($this->editing, $this->editField, $this->editValue);
            $this->message = 'Değer güncellendi; onaylayınca bu hali yazılır.';
            $this->editing = null;
        } catch (ValidationException $error) {
            $this->message = (string) collect($error->errors())->flatten()->first();
        }
    }

    public function undo(int $writeId, ExternalWriteService $writes): void
    {
        $this->authorizeAdmin();
        try {
            $writes->requestUndo(auth()->user(), ExternalWriteAction::query()->findOrFail($writeId));
            $this->message = 'Geri alma sıraya alındı.';
        } catch (ValidationException $error) {
            $this->message = (string) collect($error->errors())->flatten()->first();
        }
    }

    public function render(RepairDesk $desk): View
    {
        $rows = $this->filtered($desk);

        return view('livewire.operator.repair.repair-desk', [
            'rows' => $rows->take(300),
            'total' => $rows->count(),
            'counts' => $desk->counts(),
            'brands' => Brand::query()->operational()->orderBy('name')->pluck('name', 'id'),
            'writes' => ExternalWriteAction::query()->with('digitalAsset:id,name')->whereNotNull('suggestion_id')
                ->where('created_at', '>=', now()->subDays(7))->latest('id')->limit(40)->get(),
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
        ]);
    }

    private function filtered(RepairDesk $desk): Collection
    {
        return $desk->rows($this->brandId, $this->kind ?: null, $this->risk ?: null);
    }

    /** @param  callable(): array{applied: int, skipped_high: int, failed: list<string>}  $approve */
    private function run(callable $approve): void
    {
        $this->authorizeAdmin();
        $result = $approve();
        $parts = [$result['applied'].' iş uygulamaya gönderildi (kayıtlı, geri alınabilir)'];
        if ($result['skipped_high'] > 0) {
            $parts[] = $result['skipped_high'].' yüksek riskli iş tek tek onaylanmalı';
        }
        if ($result['failed'] !== []) {
            $parts[] = count($result['failed']).' iş gönderilemedi: '.implode(' · ', array_slice($result['failed'], 0, 3));
        }
        $this->message = implode('. ', $parts).'.';
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
    }
}
