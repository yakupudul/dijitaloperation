<?php

namespace App\Livewire\Demo;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Support\Demo\DemoMenu;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Global portfolio search for the operator header (Ctrl / Cmd + K): panel pages, customers, brands and digital assets,
 * and a last row that opens Sorgular filtered by the same words.
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
        $needle = mb_strtolower(trim($this->q));
        $results = [];

        if ($needle !== '') {
            foreach (self::pages() as $page) {
                if (str_contains(mb_strtolower($page['label']), $needle)) {
                    $results[] = ['label' => $page['label'], 'meta' => __('operator.search.page'), 'url' => $page['url']];
                }
            }

            Customer::query()
                ->orderBy('name')
                ->get()
                ->each(function (Customer $customer) use ($needle, &$results): void {
                    $name = (string) $customer->name;
                    if ($name !== '' && str_contains(mb_strtolower($name), $needle)) {
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
                    if ($name !== '' && str_contains(mb_strtolower($name), $needle)) {
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
                        if (str_contains(mb_strtolower($name.' '.$asset->domain.' '.$asset->primary_url.' '.$type.' '.$typeLabel), $needle)) {
                            $results[] = [
                                'label' => $name !== '' ? $name : (string) $asset->domain,
                                'meta' => 'Web sitesi · '.($asset->domain ?: $asset->primary_url ?: '—').' · '.($asset->brand?->name ?? 'markasız'),
                                'url' => route('operator.website', ['assetId' => $asset->id]),
                            ];
                        }

                        return;
                    }
                    if (($name !== '' && str_contains(mb_strtolower($name), $needle))
                        || ($type !== '' && str_contains(mb_strtolower($type), $needle))
                        || ($typeLabel !== '' && str_contains(mb_strtolower($typeLabel), $needle))) {
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
