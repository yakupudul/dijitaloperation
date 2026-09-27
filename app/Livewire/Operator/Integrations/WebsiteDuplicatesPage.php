<?php

namespace App\Livewire\Operator\Integrations;

use App\Livewire\Concerns\ConfirmsOwnershipTransfer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Ownership\OwnershipGuard;
use App\Services\Ownership\WebsiteDuplicateMerger;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Entegrasyonlar › Kopya web siteleri: website assets that share a domain (created before the one-site-per-domain
 * guard). Lists the groups, previews what a merge moves and what collides (dry run), and merges the duplicates into
 * the chosen keeper (Admin). A group spanning customers needs the inline yetki devri confirmation.
 */
#[Layout('operator.layouts.app')]
#[Title('Kopya web siteleri')]
final class WebsiteDuplicatesPage extends Component
{
    use ConfirmsOwnershipTransfer;

    /** @var array<int|string, int|string> group key → chosen keeper asset id */
    public array $keepers = [];

    public ?int $previewGroup = null;

    public string $message = '';

    public string $error = '';

    public function preview(int $groupKey): void
    {
        $this->previewGroup = $this->previewGroup === $groupKey ? null : $groupKey;
        $this->cancelOwnershipTransfer();
    }

    public function mergeGroup(int $groupKey, WebsiteDuplicateMerger $merger, OwnershipGuard $guard): void
    {
        abort_unless($this->canTransferOwnership(), 403);
        $group = $this->group($merger, $groupKey);
        if ($group === null) {
            return;
        }
        $keeper = DigitalAsset::query()->with('brand.customer')->findOrFail($this->keeperId($group));
        $crossDuplicate = collect($this->duplicates($group, (int) $keeper->id))
            ->first(fn (DigitalAsset $duplicate): bool => $merger->isCrossCustomer($keeper, $duplicate));

        if ($crossDuplicate instanceof DigitalAsset && $keeper->brand !== null) {
            $conflict = $guard->forAssetMove($crossDuplicate, $keeper->brand);
            if ($conflict !== null) {
                $this->presentOwnershipConflict($conflict, ['group' => $groupKey, 'keeper' => (int) $keeper->id]);
                $this->ownershipConflict['consequences'] = [
                    sprintf('%s kaydının bağlı hesapları, toplanmış verisi, SEO görevleri ve site düzeltmeleri %s kaydına taşınır.', (string) $crossDuplicate->name, (string) $keeper->name),
                    sprintf('%s müşterisi bu siteyi artık görmez; site %s adına izlenir.', (string) $crossDuplicate->brand?->customer?->name, (string) $keeper->brand->customer?->name),
                    'Aynı işe iki kez sahip olan satırlarda tutulan kaydınki kalır; kopya kayıt arşivlenir, silinmez.',
                    'Taşınan hesapların sektör / hizmet eşlemesi sıfırlanır; yeni marka için yeniden eşlenir.',
                ];
                $this->previewGroup = $groupKey;

                return;
            }
        }

        $this->runMerge($merger, $group, $keeper, false, null);
    }

    public function confirmMerge(WebsiteDuplicateMerger $merger): void
    {
        $actor = $this->ownershipTransferActor();
        if (! $actor instanceof User) {
            return;
        }
        $group = $this->group($merger, (int) ($this->pendingTransfer['group'] ?? 0));
        $keeper = DigitalAsset::query()->with('brand.customer')->find((int) ($this->pendingTransfer['keeper'] ?? 0));
        if ($group === null || ! $keeper instanceof DigitalAsset) {
            $this->cancelOwnershipTransfer();

            return;
        }
        $note = $this->transferNote;
        $this->cancelOwnershipTransfer();
        $this->runMerge($merger, $group, $keeper, true, $note);
    }

    public function render(WebsiteDuplicateMerger $merger): View
    {
        $groups = $merger->findGroups();
        $plans = [];
        foreach ($groups as $group) {
            if ($group['key'] !== $this->previewGroup) {
                continue;
            }
            $keeper = DigitalAsset::query()->with('brand.customer')->find($this->keeperId($group));
            if ($keeper instanceof DigitalAsset) {
                foreach ($this->duplicates($group, (int) $keeper->id) as $duplicate) {
                    try {
                        $plans[(int) $duplicate->id] = $merger->plan($keeper, $duplicate);
                    } catch (ValidationException $exception) {
                        $plans[(int) $duplicate->id] = ['error' => (string) collect($exception->errors())->flatten()->first()];
                    }
                }
            }
        }

        return view('livewire.operator.integrations.website-duplicates', [
            'groups' => $groups,
            'plans' => $plans,
            'recent' => $merger->recentMerges(),
            'isAdmin' => $this->canTransferOwnership(),
            'keeperFor' => fn (array $group): int => $this->keeperId($group),
        ]);
    }

    /** @param  array<string, mixed>  $group */
    private function runMerge(WebsiteDuplicateMerger $merger, array $group, DigitalAsset $keeper, bool $confirmed, ?string $note): void
    {
        /** @var User $actor */
        $actor = auth()->user();
        $moved = 0;
        $dropped = 0;
        $count = 0;
        try {
            foreach ($this->duplicates($group, (int) $keeper->id) as $duplicate) {
                $merge = $merger->merge($keeper->fresh(['brand.customer']) ?? $keeper, $duplicate, $actor, $confirmed, $note);
                $moved += $merge->movedTotal();
                $dropped += $merge->droppedTotal();
                $count++;
            }
            $this->error = '';
            $this->message = sprintf('%d kopya kayıt %s (#%d) ile birleştirildi: %d satır taşındı, %d çakışan satır bırakıldı. Kopya kayıt arşivlendi.', $count, (string) $keeper->name, $keeper->id, $moved, $dropped);
            unset($this->keepers[$group['key']]);
            $this->previewGroup = null;
        } catch (ValidationException $exception) {
            $this->message = $count > 0 ? sprintf('%d kopya birleştirildi; sonraki durdu.', $count) : '';
            $this->error = (string) collect($exception->errors())->flatten()->first();
        }
    }

    /** @return array<string, mixed>|null */
    private function group(WebsiteDuplicateMerger $merger, int $groupKey): ?array
    {
        $group = collect($merger->findGroups())->firstWhere('key', $groupKey);
        if ($group === null) {
            $this->error = 'Bu kopya grubu artık yok (başka biri birleştirmiş olabilir).';
            $this->message = '';
        }

        return $group;
    }

    /** @param  array<string, mixed>  $group */
    private function keeperId(array $group): int
    {
        $chosen = (int) ($this->keepers[$group['key']] ?? 0);
        $ids = array_map(fn (array $row): int => (int) $row['id'], $group['assets']);

        return in_array($chosen, $ids, true) ? $chosen : (int) $group['suggested_keeper_id'];
    }

    /**
     * @param  array<string, mixed>  $group
     * @return list<DigitalAsset>
     */
    private function duplicates(array $group, int $keeperId): array
    {
        $ids = array_values(array_filter(array_map(fn (array $row): int => (int) $row['id'], $group['assets']), fn (int $id): bool => $id !== $keeperId));

        return DigitalAsset::query()->with('brand.customer')->whereKey($ids)->orderBy('id')->get()->all();
    }
}
