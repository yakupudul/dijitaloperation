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
 * before approving, reject with a reason, and undo what was applied in the last 7 days. Since 2026-10-10 it is the one
 * work list: the "Diğer işler" section holds the rest of the work (content ideas, setup, overlaps, alerts, channel
 * checks) that used to live on Genel işler (`/work` now opens it). Since the approved panel concept (2026-10-20) the
 * history (Yapılanlar, Sonuçlar, the after-approval pipeline) is the "Geçmiş" tab instead of a section under the list.
 */
#[Layout('operator.layouts.app')]
#[Title('Onarım masası')]
final class RepairDeskPage extends Component
{
    #[Url(as: 'marka')]
    public ?int $brandId = null;

    /** Bölüm (yakup 2026-10-10, one work list): onay = prepared fixes, diger = the rest of the work (former Genel işler), gecmis = history. */
    #[Url(as: 'bolum')]
    public string $section = self::SECTION_DESK;

    public const string SECTION_DESK = 'onay';

    public const string SECTION_OTHER = 'diger';

    public const string SECTION_HISTORY = 'gecmis';

    #[Url(as: 'tur')]
    public string $kind = '';

    #[Url(as: 'serit')]
    public string $lane = '';

    /** The package opened as a compact list (brand.kind.lane). */
    #[Url(as: 'paket')]
    public string $open = '';

    /** Set when the operator picked a lane on the switch: an empty lane then stays open instead of jumping to a full one. */
    public bool $laneChosen = false;

    /** Rows shown in the opened package. */
    public int $perPage = 100;

    /** Packages rendered per step of "N paket daha göster" (every package on one page made the desk 1.8 MB). */
    public const int PACKAGES_PAGE = 20;

    /** Packages shown in the current lane; the counts and the order are those of every package. */
    public int $packagesShown = self::PACKAGES_PAGE;

    /** @var list<int> */
    public array $selected = [];

    public string $message = '';

    /** The "Yapılanlar" group whose single writes are shown; only that one is rendered (a 989-row group made the page 1.8 MB). */
    public string $doneOpen = '';

    /** Rows shown in the opened "Yapılanlar" group. */
    public int $donePerPage = 50;

    public ?int $editing = null;

    public string $editField = '';

    public string $editValue = '';

    public string $rejectReason = '';

    public function approve(int $id, RepairDesk $desk): void
    {
        $this->run(fn (): array => $desk->queueApproval([$id], auth()->user()));
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
        $this->run(fn (): array => $desk->queueApproval($this->selected, auth()->user()));
        $this->selected = [];
    }

    /** Hepsini onayla: one package in one decision (high-risk rows still wait for a single approval). */
    public function approvePackage(string $key, RepairDesk $desk): void
    {
        $ids = $this->packageRows($desk, $key)->pluck('id')->all();
        $this->run(fn (): array => $desk->queueApproval($ids, auth()->user()));
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

    /** The one switch on top (yakup 2026-10-11): a lane of prepared fixes, or the rest of the work. */
    public function showLane(string $lane): void
    {
        $this->section = self::SECTION_DESK;
        $this->lane = array_key_exists($lane, RepairDesk::LANES) ? $lane : RepairDesk::LANE_READY;
        $this->laneChosen = true;
        $this->selected = [];
        $this->open = '';
        $this->packagesShown = self::PACKAGES_PAGE;
    }

    public function showOther(): void
    {
        $this->section = self::SECTION_OTHER;
        $this->selected = [];
        $this->open = '';
        $this->packagesShown = self::PACKAGES_PAGE;
    }

    /** Geçmiş: what was written in the last 7 days (undoable), the after-approval pipeline and the 30 / 90 day results. */
    public function showHistory(): void
    {
        $this->section = self::SECTION_HISTORY;
        $this->selected = [];
        $this->open = '';
    }

    public function togglePackage(string $key): void
    {
        $this->open = $this->open === $key ? '' : $key;
        $this->perPage = 100;
    }

    public function toggleDone(string $key): void
    {
        $this->doneOpen = $this->doneOpen === $key ? '' : $key;
        $this->donePerPage = 50;
    }

    public function showMoreDone(): void
    {
        $this->donePerPage += 100;
    }

    public function showMore(): void
    {
        $this->perPage += 100;
    }

    public function showMorePackages(): void
    {
        $this->packagesShown += self::PACKAGES_PAGE;
    }

    /** Every visible low-risk row (the filters apply). */
    public function approveAllLow(RepairDesk $desk): void
    {
        $ids = $this->filtered($desk)->where('risk', RepairDesk::LOW)->pluck('id')->all();
        $this->run(fn (): array => $desk->queueApproval($ids, auth()->user()));
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
        if (in_array($property, ['brandId', 'kind', 'lane', 'section'], true)) {
            $this->selected = [];
            $this->open = '';
            $this->packagesShown = self::PACKAGES_PAGE;
            $this->laneChosen = $property === 'lane' && $this->laneChosen;
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
        $summary = RepairDesk::rememberSummary($all, $desk->overflow($all));
        $filtered = $this->filter($all);
        $lanes = $filtered->countBy(fn (array $r): string => RepairDesk::lane($r))->all();
        if (! array_key_exists($this->lane, RepairDesk::LANES) || (! $this->laneChosen && ($lanes[$this->lane] ?? 0) === 0 && $filtered->isNotEmpty())) {
            $this->lane = (string) (collect(array_keys(RepairDesk::LANES))->first(fn (string $l): bool => ($lanes[$l] ?? 0) > 0) ?? RepairDesk::LANE_READY);
        }
        $inLane = $this->inLane($filtered);
        $openRows = $this->open !== '' ? $inLane->filter(fn (array $r): bool => RepairDesk::packageKey($r) === $this->open)->values() : collect();

        $this->section = in_array($this->section, [self::SECTION_OTHER, self::SECTION_HISTORY], true) ? $this->section : self::SECTION_DESK;
        $done = $desk->done($this->brandId);
        $packages = $desk->packages($inLane)->values();
        // One brand's packages sit together (panel concept 2026-10-20), brands in the order of their best package.
        $firstOfBrand = [];
        foreach ($packages as $i => $package) {
            $firstOfBrand[$package['brand_id']] ??= $i;
        }
        $packages = $packages->sortBy(fn (array $package, int $i): array => [$firstOfBrand[$package['brand_id']], $i])->values();
        // A package opened from a link (?paket=…) past the first pages is shown with the packages before it.
        $openIndex = $this->open !== '' ? $packages->search(fn (array $p): bool => $p['key'] === $this->open) : false;
        if ($openIndex !== false && $openIndex >= $this->packagesShown) {
            $this->packagesShown = (int) (ceil(($openIndex + 1) / self::PACKAGES_PAGE) * self::PACKAGES_PAGE);
        }
        $selectedIds = array_map('intval', $this->selected);

        return view('livewire.operator.repair.repair-desk', [
            'lowSample' => $inLane->where('risk', RepairDesk::LOW)->take(3)->values(),
            'lowTotal' => $inLane->where('risk', RepairDesk::LOW)->count(),
            'selectedSample' => $selectedIds === [] ? collect() : $all->whereIn('id', $selectedIds)->take(3)->values(),
            'doneCount' => (int) $done->sum(fn (array $g): int => (int) $g['ok'] + (int) $g['partial']),
            'doneFailed' => (int) $done->sum('failed'),
            'doneReturned' => (int) $done->sum('returned'),
            'packages' => $packages->take(max(self::PACKAGES_PAGE, $this->packagesShown))->values(),
            'packagesTotal' => $packages->count(),
            'openRows' => $openRows->take($this->perPage),
            'openTotal' => $openRows->count(),
            'laneCounts' => $lanes,
            'laneTotal' => $inLane->count(),
            'summary' => $summary,
            'scope' => $this->brandId !== null ? ($summary['brands'][$this->brandId] ?? ['decide' => 0, 'manual' => 0]) : $summary,
            'counts' => ['kinds' => $this->filter($all, kind: false)->countBy('kind')->all()],
            'health' => $desk->health($all),
            'pipeline' => $desk->pipeline($this->brandId),
            'brands' => ($brands = Brand::query()->operational()->orderBy('name')->get(['id', 'name', 'logo_url']))->pluck('name', 'id'),
            'logos' => $brands->pluck('logo_url', 'id'),
            'done' => $done,
            'results' => $this->section === self::SECTION_HISTORY ? $desk->results($this->brandId) : null,
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
            'queuedCount' => $desk->queuedCount($this->brandId),
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

    /** @param  callable(): array{applied: int, skipped_high: int, failed: list<string>}  $approve  queues the approval (ApproveRepairRowsJob) */
    private function run(callable $approve): void
    {
        $this->authorizeAdmin();
        $result = $approve();
        $parts = [$result['applied'].' iş uygulamaya gönderildi; arka planda yazılıyor, ne yazıldığı aşağıda "Yapılanlar"da görünür (kayıtlı, geri alınabilir). Gönderilemeyen olursa nedeniyle listeye geri döner'];
        if ($result['skipped_high'] > 0) {
            $parts[] = $result['skipped_high'].' yüksek riskli iş tek tek onaylanmalı';
        }
        $this->message = implode('. ', $parts).'.';
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
    }
}
