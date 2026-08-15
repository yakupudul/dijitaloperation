<?php

namespace App\Livewire\Demo;

use App\Services\Playbooks\PlaybookReadService;
use App\Support\Demo\ClientValueFixtures;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
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
            foreach (DemoState::all()['customers'] ?? [] as $customer) {
                $name = (string) ($customer['name'] ?? '');
                if ($name !== '' && str_contains(mb_strtolower($name), $needle)) {
                    $results[] = [
                        'label' => $name,
                        'meta' => __('operator.nav.customers'),
                        'url' => route('demo.customer', ['customerId' => $customer['id']]),
                    ];
                }
            }

            foreach (DemoState::all()['brands'] ?? [] as $brand) {
                $name = (string) ($brand['name'] ?? '');
                if ($name !== '' && str_contains(mb_strtolower($name), $needle)) {
                    $results[] = [
                        'label' => $name,
                        'meta' => __('operator.nav.brands').' · '.($brand['customer_name'] ?? DemoCatalog::customer()['name'] ?? ''),
                        'url' => route('demo.brand', ['brand' => $brand['id']]),
                    ];
                }
            }

            foreach (DemoCatalog::assets() as $asset) {
                $name = (string) ($asset['name'] ?? '');
                $type = (string) ($asset['type'] ?? '');
                if (($name !== '' && str_contains(mb_strtolower($name), $needle))
                    || ($type !== '' && str_contains(mb_strtolower($type), $needle))) {
                    $route = match ($type) {
                        'website' => 'demo.website',
                        'google_ads' => 'demo.google-ads.overview',
                        'meta_ads' => 'demo.meta.overview',
                        'gbp', 'google_business_profile' => 'demo.gbp',
                        'ga4', 'google_analytics' => 'demo.analytics',
                        'gsc', 'search_console' => 'demo.search-console',
                        'instagram' => 'demo.instagram',
                        default => 'demo.assets',
                    };
                    $results[] = [
                        'label' => $name !== '' ? $name : $type,
                        'meta' => __('operator.nav.digital_assets').' · '.($asset['brand'] ?? 'Atlas Dental'),
                        'url' => route($route, array_filter(['assetId' => $asset['id'] ?? null])),
                    ];
                }
            }

            foreach (DemoState::findingsWithStatus() as $finding) {
                $title = (string) ($finding['title'] ?? '');
                if ($title !== '' && str_contains(mb_strtolower($title), $needle)) {
                    $results[] = [
                        'label' => $title,
                        'meta' => __('operator.nav.findings').' · '.($finding['brand'] ?? ''),
                        'url' => route('demo.findings'),
                    ];
                }
            }

            foreach (DemoState::all()['tasks'] ?? [] as $task) {
                $title = (string) ($task['title'] ?? '');
                if ($title !== '' && str_contains(mb_strtolower($title), $needle)) {
                    $results[] = [
                        'label' => $title,
                        'meta' => __('operator.nav.tasks').' · '.($task['brand'] ?? ''),
                        'url' => route('demo.task', ['taskId' => $task['id']]),
                    ];
                }
            }

            foreach (app(PlaybookReadService::class)->forList(['status' => 'active', 'search' => $needle], 20) as $playbook) {
                $name = (string) ($playbook['name'] ?? '');
                if ($name !== '') {
                    $results[] = [
                        'label' => $name,
                        'meta' => 'Playbook',
                        'url' => route('demo.settings.playbook', ['playbookId' => $playbook['id']]),
                    ];
                }
            }

            foreach (ClientValueFixtures::meaningfulDecisions() as $decision) {
                $title = (string) ($decision['title'] ?? '');
                if ($title !== '' && str_contains(mb_strtolower($title), $needle)) {
                    $results[] = [
                        'label' => $title,
                        'meta' => 'Decision',
                        'url' => route('demo.brand', [
                            'brand' => DemoCatalog::BRAND_ID,
                            'tab' => 'value',
                            'value' => 'decisions',
                        ]),
                    ];
                }
            }
        }

        return view('livewire.demo.global-search', [
            'results' => array_slice($results, 0, 12),
        ]);
    }
}
