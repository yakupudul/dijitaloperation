<?php

namespace App\Livewire\Operator\Repair;

use App\Models\Brand;
use App\Models\ExternalWriteAction;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Repair\RepairDesk;
use App\Services\Work\WorkDesk;
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
 * before approving, reject with a reason, and undo what was applied in the last 7 days. Since 2026-10-10 it is the one
 * work list: the "Diğer işler" section holds the rest of the work (content ideas, setup, overlaps, alerts, channel
 * checks) that used to live on Genel işler (`/work` now opens it).
 */
#[Layout('operator.layouts.app')]
#[Title('Onarım masası')]
final class RepairDeskPage extends Component
{
    #[Url(as: 'marka')]
    public ?int $brandId = null;

    /** Bölüm (yakup 2026-10-10, one work list): onay = prepared fixes, diger = the rest of the work (former Genel işler). */
    #[Url(as: 'bolum')]
    public string $section = self::SECTION_DESK;

    public const string SECTION_DESK = 'onay';

    public const string SECTION_OTHER = 'diger';

    #[Url(as: 'tur')]
    public string $kind = '';

    #[Url(as: 'serit')]
    public string $lane = '';

    /** The package opened as a compact list (brand.kind.lane). */
    #[Url(as: 'paket')]
    public string $open = '';

    /** Rows shown in the opened package. */
    public int $perPage = 100;

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
        $this->selected = array_values(array_diff($this->selected, [$id]));
    }

    public function rejectOne(int $id, RepairDesk $desk): void
    {
        $this->authorizeAdmin();
        $desk->reject([$id], auth()->user(), $this->rejectReason);
        $this->selected = array_values(array_diff($this->selected, [$id]));
        $this->message = '1 öneri reddedildi; kanıt değişmedikçe geri gelmez.';
    }

    public function approveSelected(RepairDesk $desk): void
    {
        $this->run(fn (): array => $desk->approve($this->selected, auth()->user()));
        $this->selected = [];
    }

    /** Hepsini onayla: one package in one decision (high-risk rows still wait for a single approval). */
    public function approvePackage(string $key, RepairDesk $desk): void
    {
        $ids = $this->packageRows($desk, $key)->pluck('id')->all();
        $this->run(fn (): array => $desk->approve($ids, auth()->user()));
        $this->selected = array_values(array_diff($this->selected, $ids));
    }

    public function rejectPackage(string $key, RepairDesk $desk): void
    {
        $this->authorizeAdmin();
        $ids = $this->packageRows($desk, $key)->pluck('id')->all();
        $count = $desk->reject($ids, auth()->user(), $this->rejectReason);
        $this->selected = array_values(array_diff($this->selected, $ids));
        $this->rejectReason = '';
        $this->message = $count.' öneri reddedildi; kanıt değişmedikçe geri gelmez.';
    }

    public function togglePackage(string $key): void
    {
        $this->open = $this->open === $key ? '' : $key;
        $this->perPage = 100;
    }

    public function showMore(): void
    {
        $this->perPage += 100;
    }

    /** Every visible low-risk row (the filters apply). */
    public function approveAllLow(RepairDesk $desk): void
    {
        $ids = $this->filtered($desk)->where('risk', RepairDesk::LOW)->pluck('id')->all();
        $this->run(fn (): array => $desk->approve($ids, auth()->user()));
    }

    /** Select every row of one package. */
    public function selectPackage(string $key, RepairDesk $desk): void
    {
        $this->selected = array_values(array_unique([...$this->selected, ...$this->packageRows($desk, $key)->pluck('id')->map(fn ($id): int => (int) $id)->all()]));
    }

    /** Select every row of the current lane that the brand / kind filters match. */
    public function selectAllMatching(RepairDesk $desk): void
    {
        $this->selected = $this->inLane($this->filtered($desk))->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    /** A filter change drops the selection so hidden rows are never approved by mistake. */
    public function updated(string $property): void
    {
        if (in_array($property, ['brandId', 'kind', 'lane'], true)) {
            $this->selected = [];
            $this->open = '';
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

    /** Hepsini geri al: every undoable write of one "Yapılanlar" group (day · asset · kind). */
    public function undoGroup(string $key, RepairDesk $desk, ExternalWriteService $writes): void
    {
        $this->authorizeAdmin();
        $group = $desk->done($this->brandId)->firstWhere('key', $key);
        $count = 0;
        foreach (collect($group['items'] ?? [])->where('undoable', true) as $item) {
            try {
                $writes->requestUndo(auth()->user(), ExternalWriteAction::query()->findOrFail($item['id']));
                $count++;
            } catch (ValidationException) {
                continue;
            }
        }
        $this->message = $count.' iş için geri alma sıraya alındı.';
    }

    public function render(RepairDesk $desk): View
    {
        $all = $desk->rows();
        $filtered = $this->filter($all);
        $lanes = $filtered->countBy(fn (array $r): string => RepairDesk::lane($r))->all();
        if (! array_key_exists($this->lane, RepairDesk::LANES) || (($lanes[$this->lane] ?? 0) === 0 && $filtered->isNotEmpty())) {
            $this->lane = (string) (collect(array_keys(RepairDesk::LANES))->first(fn (string $l): bool => ($lanes[$l] ?? 0) > 0) ?? RepairDesk::LANE_READY);
        }
        $inLane = $this->inLane($filtered);
        $openRows = $this->open !== '' ? $inLane->filter(fn (array $r): bool => RepairDesk::packageKey($r) === $this->open)->values() : collect();

        $this->section = $this->section === self::SECTION_OTHER ? self::SECTION_OTHER : self::SECTION_DESK;

        return view('livewire.operator.repair.repair-desk', [
            'otherCount' => array_sum(app(WorkDesk::class)->counts($this->brandId)),
            'packages' => $desk->packages($inLane),
            'openRows' => $openRows->take($this->perPage),
            'openTotal' => $openRows->count(),
            'laneCounts' => $lanes,
            'laneTotal' => $inLane->count(),
            'counts' => ['total' => $all->count(), 'kinds' => $this->filter($all, kind: false)->countBy('kind')->all(), 'brands' => $all->countBy('brand_id')->all()],
            'health' => $desk->health($all),
            'pipeline' => $desk->pipeline($this->brandId),
            'brands' => Brand::query()->operational()->orderBy('name')->pluck('name', 'id'),
            'done' => $desk->done($this->brandId),
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function filtered(RepairDesk $desk): Collection
    {
        return $this->filter($desk->rows());
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function filter(Collection $rows, bool $kind = true): Collection
    {
        return $rows->filter(fn (array $r): bool => ($this->brandId === null || $r['brand_id'] === $this->brandId)
            && (! $kind || $this->kind === '' || $r['kind'] === $this->kind))->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function inLane(Collection $rows): Collection
    {
        $lane = array_key_exists($this->lane, RepairDesk::LANES) ? $this->lane : RepairDesk::LANE_READY;

        return $rows->filter(fn (array $r): bool => RepairDesk::lane($r) === $lane)->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function packageRows(RepairDesk $desk, string $key): Collection
    {
        return $this->filtered($desk)->filter(fn (array $r): bool => RepairDesk::packageKey($r) === $key)->values();
    }

    /** @param  callable(): array{applied: int, skipped_high: int, failed: list<string>}  $approve */
    private function run(callable $approve): void
    {
        $this->authorizeAdmin();
        $result = $approve();
        $parts = [$result['applied'].' iş uygulamaya gönderildi; ne yazıldığı birkaç dakika içinde aşağıda "Yapılanlar"da görünür (kayıtlı, geri alınabilir)'];
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
