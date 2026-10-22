<?php

namespace App\Livewire\Demo;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Services\Operator\KomutaReader;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Services\Repair\RepairDesk;
use App\Support\Demo\DemoMenu;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;
use Throwable;

/**
 * Global search for the operator header (Ctrl / Cmd + K): panel pages, work waiting (a brand's decisions, a kind of
 * work across brands, open desk rows by title), customers, brands and digital assets, and a last row that opens
 * Sorgular filtered by the same words. Turkish letters match either way ("implant" finds "İmplant"); arrow keys move.
 */
class GlobalSearch extends Component
{
    public string $q = '';

    public bool $open = false;

    public function updatedQ(): void
    {
        $this->open = trim($this->q) !== '';
    }

    public function select(): void
    {
        $this->open = false;
    }

    public function render(): View
    {
        $needle = self::fold(trim($this->q));
        $results = [];

        if ($needle !== '') {
            foreach (self::pages() as $page) {
                if (str_contains(self::fold($page['label']), $needle)) {
                    $results[] = ['label' => $page['label'], 'meta' => __('operator.search.page'), 'url' => $page['url']];
                }
            }
            $results = [...$results, ...$this->work($needle)];

            Customer::query()
                ->orderBy('name')
                ->get()
                ->each(function (Customer $customer) use ($needle, &$results): void {
                    $name = (string) $customer->name;
                    if ($name !== '' && str_contains(self::fold($name), $needle)) {
                        $results[] = [
                            'label' => $name,
                            'meta' => __('operator.nav.customers'),
                            'url' => route('operator.customer', ['customerId' => $customer->id]),
                        ];
                    }
                });

            Brand::query()
                ->with('customer')
                ->orderBy('name')
                ->get()
                ->each(function (Brand $brand) use ($needle, &$results): void {
                    $name = (string) $brand->name;
                    if ($name !== '' && str_contains(self::fold($name), $needle)) {
                        $results[] = [
                            'label' => $name,
                            'meta' => __('operator.nav.brands').' · '.($brand->customer?->name ?? '—'),
                            'url' => route('operator.brand', ['brand' => $brand->id]),
                        ];
                    }
                });

            DigitalAsset::query()
                ->with('brand')
                ->whereNotIn('type', ['domain', 'hosting'])
                ->orderBy('name')
                ->get()
                ->each(function (DigitalAsset $asset) use ($needle, &$results): void {
                    $presented = OperatorPortfolioPresenter::asset($asset);
                    $name = (string) ($presented['name'] ?? '');
                    $type = (string) ($presented['type'] ?? '');
                    $typeLabel = (string) ($presented['type_label'] ?? '');
                    if ($asset->type === 'website') {
                        // Websites are found by domain / URL too and open the website screen.
                        if (str_contains(self::fold($name.' '.$asset->domain.' '.$asset->primary_url.' '.$type.' '.$typeLabel), $needle)) {
                            $results[] = [
                                'label' => $name !== '' ? $name : (string) $asset->domain,
                                'meta' => 'Web sitesi · '.($asset->domain ?: $asset->primary_url ?: '—').' · '.($asset->brand?->name ?? 'markasız'),
                                'url' => route('operator.website', ['assetId' => $asset->id]),
                            ];
                        }

                        return;
                    }
                    if (($name !== '' && str_contains(self::fold($name), $needle))
                        || ($type !== '' && str_contains(self::fold($type), $needle))
                        || ($typeLabel !== '' && str_contains(self::fold($typeLabel), $needle))) {
                        $results[] = [
                            'label' => $name !== '' ? $name : $typeLabel,
                            'meta' => __('operator.nav.digital_assets').' · '.($presented['brand_name'] ?? '—'),
                            'url' => route($presented['route'], ['assetId' => $asset->id]),
                        ];
                    }
                });
        }

        $results = array_slice($results, 0, 12);
        if ($needle !== '') {
            $results[] = [
                'label' => __('operator.search.in_queries', ['q' => trim($this->q)]),
                'meta' => __('operator.search.queries'),
                'url' => route('operator.library.queries', ['q' => trim($this->q)]),
            ];
        }

        return view('livewire.demo.global-search', [
            'results' => $results,
        ]);
    }

    /** Lower case with Turkish dotted/dotless I and accents folded, so either spelling matches. */
    public static function fold(string $text): string
    {
        return Str::ascii(mb_strtolower(strtr($text, ['İ' => 'i', 'I' => 'ı'])));
    }

    /**
     * Work waiting on the operator: a brand's decisions (the desk's count), a kind of work across brands (Komuta's
     * group names) and open desk rows whose title matches.
     *
     * @return list<array{label: string, meta: string, url: string}>
     */
    private function work(string $needle): array
    {
        $out = [];
        try {
            $summary = app(RepairDesk::class)->summary();
            $names = Brand::query()->whereIn('id', array_keys($summary['brands']))->pluck('name', 'id');
            foreach ($summary['brands'] as $brandId => $counts) {
                $name = (string) ($names[$brandId] ?? '');
                if ($name !== '' && (int) $counts['decide'] > 0 && str_contains(self::fold($name), $needle)) {
                    $out[] = ['label' => $name.' · '.number_format((int) $counts['decide'], 0, ',', '.').' karar', 'meta' => 'İş · Onarım masası', 'url' => route('operator.repair', ['marka' => $brandId])];
                }
            }
            foreach (KomutaReader::GROUP_TITLES as $kind => $title) {
                if (str_contains(self::fold($title.' '.(RepairDesk::KINDS[$kind] ?? '')), $needle)) {
                    $out[] = ['label' => $title, 'meta' => 'İş türü · Onarım masası', 'url' => route('operator.repair', ['tur' => $kind])];
                }
            }
            $words = trim($this->q);
            if (mb_strlen($words) >= 3) {
                Suggestion::query()->with('brand:id,name')->where('status', Suggestion::OPEN)
                    ->whereRaw('lower(title) like ?', ['%'.mb_strtolower(strtr($words, ['İ' => 'i', 'I' => 'ı'])).'%'])
                    ->latest('id')->limit(4)->get(['id', 'brand_id', 'title'])
                    ->each(function (Suggestion $row) use (&$out): void {
                        $out[] = ['label' => Str::limit((string) $row->title, 70), 'meta' => 'Açık iş · '.($row->brand?->name ?? '—'), 'url' => route('operator.repair', ['marka' => $row->brand_id])];
                    });
            }
        } catch (Throwable $error) {
            report($error);
        }

        return $out;
    }

    /**
     * Every sidebar screen and its tabs, by name (no database read).
     *
     * @return list<array{label: string, url: string}>
     */
    private static function pages(): array
    {
        $pages = [];
        foreach (DemoMenu::groups() as $group) {
            foreach ($group['items'] as $item) {
                foreach ([['label' => $item['label'], 'route' => $item['route']], ...($item['children'] ?? [])] as $entry) {
                    $pages[] = ['label' => (string) $entry['label'], 'url' => route($entry['route'])];
                }
            }
        }

        return $pages;
    }
}
