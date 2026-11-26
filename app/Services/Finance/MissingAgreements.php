<?php

namespace App\Services\Finance;

use App\Mcp\Tools\Todos;
use App\Models\Agreement;
use App\Models\Brand;
use App\Models\Reminder;
use App\Services\Sales\Agreements;
use App\Support\Demo\DemoMenu;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Anlaşması olmayan marka (yakup 2026-11-26: "Burçin hocanın anlaşması yer almıyor ama sen beni uyarmıyorsun"): every
 * served brand is a paying customer, so a served brand with no running agreement in Finans is a hole in the money
 * picture. It is shown on Finans › Özet and on Bugün, and one to-do per brand is written in Yapılacaklar for the
 * operator to tell the fee; the to-do closes itself once an agreement is entered. Moximu's own brand pays nobody.
 * A job typed with only a payer name that is exactly a brand's name is bound to that brand first.
 */
final class MissingAgreements
{
    public const string TODO_SUFFIX = ' için anlaşmayı Finans\'a gir';

    /** @return Collection<int, Brand> served brands with no active or paused agreement, by name */
    public function brands(): Collection
    {
        $own = (int) (DemoMenu::agencyBrand()['id'] ?? 0);
        $agreements = Agreement::query()->whereIn('status', ['active', 'paused'])->get(['id', 'brand_id', 'party']);
        $covered = $agreements->pluck('brand_id')->filter()->map(fn ($id): int => (int) $id)->flip();
        $parties = $agreements->whereNull('brand_id')->pluck('party')->filter()->map(fn ($p): string => self::key((string) $p))->flip();

        return Brand::query()->operational()->orderBy('name')->get(['id', 'name', 'customer_id'])
            ->reject(fn (Brand $b): bool => (int) $b->id === $own || isset($covered[(int) $b->id]) || isset($parties[self::key((string) $b->name)]))
            ->values();
    }

    /** @return array<int, true> brand id => true, for screens that mark rows */
    public function ids(): array
    {
        return $this->brands()->mapWithKeys(fn (Brand $b): array => [(int) $b->id => true])->all();
    }

    /**
     * Binds name-only jobs to their brand, writes one to-do per brand still without an agreement, ticks the to-dos of
     * brands that got one.
     *
     * @return array{bound: int, added: int, closed: int}
     */
    public function sync(): array
    {
        $bound = $this->bindByName();
        $user = Todos::operator();
        if ($user === null) {
            return ['bound' => $bound, 'added' => 0, 'closed' => 0];
        }
        $missing = $this->brands();
        $missingIds = $missing->pluck('id')->map(fn ($id): int => (int) $id)->flip();
        $open = Reminder::query()->where('user_id', $user->id)->where('kind', 'task')->whereNull('done_at')->whereNotNull('brand_id')
            ->where('title', 'like', '%'.self::TODO_SUFFIX)->get();

        $closed = 0;
        foreach ($open as $reminder) {
            if (! isset($missingIds[(int) $reminder->brand_id])) {
                $reminder->forceFill(['done_at' => now()])->save();
                $closed++;
            }
        }
        $added = 0;
        // A to-do already asking for this brand's money (an earlier one pointing at Finans) is enough; never two.
        $asked = Reminder::query()->where('user_id', $user->id)->where('kind', 'task')->whereNull('done_at')->whereNotNull('brand_id')
            ->where('url', 'like', '%/finans%')->pluck('brand_id');
        $have = $open->pluck('brand_id')->merge($asked)->map(fn ($id): int => (int) $id)->flip();
        foreach ($missing as $brand) {
            if (isset($have[(int) $brand->id])) {
                continue;
            }
            Reminder::query()->create([
                'user_id' => $user->id, 'kind' => 'task', 'repeat' => 'none', 'all_day' => true,
                'title' => self::todoTitle($brand),
                'notes' => 'Bu marka hizmet alıyor ama Finans\'ta sürmekte olan bir anlaşması yok, ödemesi takip edilmiyor. Aylık ücreti, KDV durumunu ve başlangıç ayını gir (ya da Claude\'a yaz, o girer).',
                'url' => route('operator.finance', ['sekme' => 'anlasmalar']),
                'brand_id' => $brand->id, 'customer_id' => $brand->customer_id,
            ]);
            $added++;
        }

        return ['bound' => $bound, 'added' => $added, 'closed' => $closed];
    }

    /** An agreement was entered or changed: its brand's to-do is ticked at once, not at the next hourly round. */
    public static function settled(Agreement $agreement): void
    {
        if ($agreement->brand_id === null || ! in_array($agreement->status, ['active', 'paused'], true)) {
            return;
        }
        Reminder::query()->where('kind', 'task')->whereNull('done_at')->where('brand_id', $agreement->brand_id)
            ->where('title', 'like', '%'.self::TODO_SUFFIX)->update(['done_at' => now()]);
    }

    public static function todoTitle(Brand $brand): string
    {
        return $brand->name.self::TODO_SUFFIX;
    }

    private function bindByName(): int
    {
        $byName = Brand::query()->get(['id', 'name', 'customer_id'])->groupBy(fn (Brand $b): string => self::key((string) $b->name));
        $bound = 0;
        foreach (Agreement::query()->whereNull('brand_id')->whereNotNull('party')->get() as $agreement) {
            $matches = $byName->get(self::key((string) $agreement->party));
            if ($matches !== null && $matches->count() === 1) {
                $brand = $matches->first();
                $agreement->forceFill(['brand_id' => $brand->id, 'customer_id' => $agreement->customer_id ?? $brand->customer_id])->save();
                app(Agreements::class)->syncCustomer($brand->customer_id !== null ? (int) $brand->customer_id : null);
                $bound++;
            }
        }

        return $bound;
    }

    private static function key(string $name): string
    {
        return (string) Str::of($name)->squish()->lower();
    }
}
