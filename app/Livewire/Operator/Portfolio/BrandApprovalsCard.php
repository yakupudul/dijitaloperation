<?php

namespace App\Livewire\Operator\Portfolio;

use App\Models\Brand;
use App\Services\Repair\RepairDesk;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * Marka › Özet › Bekleyen onaylar: the brand's prepared fixes on the Onarım masası waiting for the operator's approval,
 * counted in packages, the most expected gain first (RepairDesk::packages), each linking to its package on the desk. Tasks only the operator can do by hand are not
 * approvals and are left out. Loaded after the page (lazy) and not re-read on the brand page's own clicks: the desk
 * read is the heavy part of the page.
 */
final class BrandApprovalsCard extends Component
{
    /** Packages listed before "+N düzeltme daha". */
    public const int PACKAGES = 3;

    #[Locked]
    public int $brandId;

    public function mount(int $brandId): void
    {
        $this->brandId = $brandId;
    }

    public function placeholder(): string
    {
        return '<div></div>';
    }

    public function render(RepairDesk $desk): View
    {
        return view('livewire.operator.portfolio.brand-approvals-card', ['approvals' => $this->approvals($desk)]);
    }

    /** @return array{total: int, rows: int, url: string, packages: list<array{key: string, label: string, lane: string, count: int, url: string}>}|null */
    private function approvals(RepairDesk $desk): ?array
    {
        $brand = Brand::query()->findOrFail($this->brandId);
        try {
            $rows = $desk->rows((int) $brand->id)->reject(fn (array $row): bool => RepairDesk::lane($row) === RepairDesk::LANE_MANUAL)->values();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
        $all = $desk->packages($rows);
        $packages = $all->take(self::PACKAGES)->map(fn (array $p): array => [
            'key' => $p['key'], 'label' => RepairDesk::KINDS[$p['kind']] ?? $p['kind'], 'lane' => RepairDesk::LANES[$p['lane']] ?? $p['lane'],
            'count' => $p['count'], 'url' => route('operator.repair', ['marka' => $brand->id, 'paket' => $p['key']]),
        ])->values()->all();

        return ['total' => $all->count(), 'rows' => $rows->count(), 'url' => route('operator.repair', ['marka' => $brand->id]), 'packages' => $packages];
    }
}
