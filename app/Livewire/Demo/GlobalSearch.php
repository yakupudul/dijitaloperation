<?php

namespace App\Livewire\Demo;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Services\Findings\FindingReadService;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Services\Playbooks\PlaybookReadService;
use App\Services\Work\WorkReadService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Global portfolio search for the operator header.
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

            foreach (app(FindingReadService::class)->query([], 200) as $findingDto) {
                $title = (string) $findingDto->title;
                if ($title !== '' && str_contains(mb_strtolower($title), $needle)) {
                    $results[] = [
                        'label' => $title,
                        'meta' => __('operator.nav.findings'),
                        'url' => route('operator.findings'),
                    ];
                }
            }

            foreach (app(WorkReadService::class)->workItems() as $task) {
                $title = (string) ($task['title'] ?? '');
                if ($title !== '' && str_contains(mb_strtolower($title), $needle)) {
                    $results[] = [
                        'label' => $title,
                        'meta' => __('operator.nav.tasks').' · '.($task['brand'] ?? ''),
                        'url' => isset($task['id'], $task['type'])
                            ? route('operator.work.show', ['workId' => $task['id'], 'type' => $task['type']])
                            : route('operator.tasks'),
                    ];
                }
            }

            foreach (app(PlaybookReadService::class)->forList(['status' => 'active', 'search' => $needle], 20) as $playbook) {
                $name = (string) ($playbook['name'] ?? '');
                if ($name !== '') {
                    $results[] = [
                        'label' => $name,
                        'meta' => 'Playbook',
                        'url' => route('operator.settings.playbook', ['playbookId' => $playbook['id']]),
                    ];
                }
            }
        }

        return view('livewire.demo.global-search', [
            'results' => array_slice($results, 0, 12),
        ]);
    }
}
