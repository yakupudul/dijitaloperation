<?php

namespace App\Livewire\Demo\Portfolio;

use App\Support\Demo\BrandPublicDiscoveryFixtures;
use App\Support\Demo\ConnectorWorkspaceFixtures;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use App\Support\Options\CountryOptions;
use App\Support\Options\LanguageOptions;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Portfolio Setup')]
class PortfolioSetupWizard extends Component
{
    #[Url]
    public string $entry = 'customer';

    #[Url]
    public int $step = 1;

    public string $customerId = '';

    public string $brandId = '';

    public string $customer_name = '';

    public string $contact_name = '';

    public string $contact_email = '';

    public string $contact_phone = '';

    public string $account_owner = '';

    public string $brand_name = '';

    public string $website_url = '';

    public string $primary_country = 'TR';

    public string $primary_language = 'tr';

    /** @var list<string> */
    public array $responsible_user_ids = [];

    /** @var list<string> */
    public array $selected_assets = [];

    /** @var array<string, string|null> */
    public array $resource_selections = [];

    /** @var array<string, bool> */
    public array $skipped_providers = [];

    /** @var list<string> */
    public array $accepted_candidate_ids = [];

    public ?string $reviewConflictId = null;

    public bool $committed = false;

    public string $duplicateBrandWarning = '';

    /**
     * @var list<string>
     */
    private const ASSET_OPTIONS = [
        'website',
        'gbp',
        'google_ads',
        'meta_ads',
        'ga4',
        'gsc',
        'instagram',
    ];

    public function mount(): void
    {
        if (! in_array($this->entry, ['customer', 'brand', 'asset'], true)) {
            $this->entry = 'customer';
        }

        $saved = DemoState::wizardState();
        if (is_array($saved) && ($saved['entry'] ?? null) === $this->entry && ! ($saved['committed'] ?? false)) {
            foreach ($saved as $key => $value) {
                if (property_exists($this, $key) && $key !== 'entry') {
                    $this->{$key} = $value;
                }
            }
        }

        if ($this->entry === 'brand') {
            $this->customerId = request()->query('customerId', DemoCatalog::CUSTOMER_ID);
            $this->customer_name = DemoCatalog::customer()['name'] ?? 'Atlas Health Group';
            if ($this->step < 2) {
                $this->step = 2;
            }
        }

        if ($this->entry === 'asset') {
            $this->customerId = DemoCatalog::CUSTOMER_ID;
            $this->brandId = request()->query('brandId', DemoCatalog::BRAND_ID);
            $this->customer_name = DemoCatalog::customer()['name'] ?? 'Atlas Health Group';
            $this->brand_name = DemoCatalog::brand()['name'] ?? 'Atlas Dental Ankara';
            $this->website_url = 'https://atlasdental.example';
            if ($this->step < 3) {
                $this->step = 3;
            }
        }

        if ($this->account_owner === '') {
            $this->account_owner = 'u-ayse';
        }

        if ($this->responsible_user_ids === []) {
            $this->responsible_user_ids = ['u-ayse'];
        }

        if ($this->website_url !== '' && ! in_array('website', $this->selected_assets, true)) {
            $this->selected_assets[] = 'website';
        }

        $this->step = max(1, min(6, $this->step));
    }

    public function updatedWebsiteUrl(): void
    {
        if ($this->website_url !== '' && ! in_array('website', $this->selected_assets, true)) {
            $this->selected_assets[] = 'website';
        }
        $this->persist();
    }

    public function toggleAsset(string $type): void
    {
        if (! in_array($type, self::ASSET_OPTIONS, true)) {
            return;
        }

        if (in_array($type, $this->selected_assets, true)) {
            $this->selected_assets = array_values(array_filter($this->selected_assets, fn (string $t): bool => $t !== $type));
            unset($this->resource_selections[$type], $this->skipped_providers[$type]);
        } else {
            $this->selected_assets[] = $type;
        }
        $this->persist();
    }

    public function selectResource(string $assetType, string $resourceId): void
    {
        $this->resource_selections[$assetType] = $resourceId;
        unset($this->skipped_providers[$assetType]);
        $this->persist();
    }

    public function skipProvider(string $assetType): void
    {
        $this->skipped_providers[$assetType] = true;
        $this->resource_selections[$assetType] = null;
        $this->persist();
    }

    public function toggleCandidate(string $id): void
    {
        if (in_array($id, $this->accepted_candidate_ids, true)) {
            $this->accepted_candidate_ids = array_values(array_filter($this->accepted_candidate_ids, fn (string $cid): bool => $cid !== $id));
        } else {
            $this->accepted_candidate_ids[] = $id;
        }
        $this->persist();
    }

    public function openConflict(string $id): void
    {
        $this->reviewConflictId = $id;
    }

    public function closeConflict(): void
    {
        $this->reviewConflictId = null;
    }

    public function resolveConflict(string $id, string $decision): void
    {
        DemoState::resolveDiscoveryConflict($id, $decision);
        $this->reviewConflictId = null;
        $this->persist();
    }

    public function next(): void
    {
        if (! $this->validateStep($this->step)) {
            return;
        }

        if ($this->step === 5) {
            $this->acceptSelectedCandidates();
        }

        if ($this->step < 6) {
            $this->step++;
        }

        if ($this->step === 6 && ! $this->committed) {
            $this->commitSetup();
        }

        $this->persist();
    }

    public function back(): void
    {
        if ($this->committed) {
            return;
        }

        $min = match ($this->entry) {
            'brand' => 2,
            'asset' => 3,
            default => 1,
        };

        if ($this->step > $min) {
            $this->step--;
        }
        $this->persist();
    }

    public function goToStep(int $step): void
    {
        if ($this->committed) {
            return;
        }

        $min = match ($this->entry) {
            'brand' => 2,
            'asset' => 3,
            default => 1,
        };

        if ($step >= $min && $step < $this->step) {
            $this->step = $step;
            $this->persist();
        }
    }

    protected function validateStep(int $step): bool
    {
        $this->duplicateBrandWarning = '';

        if ($step === 1) {
            $validator = Validator::make(
                ['customer_name' => $this->customer_name],
                ['customer_name' => ['required', 'string', 'min:2', 'max:120']],
                [],
                ['customer_name' => 'Customer name']
            );
            if ($validator->fails()) {
                $this->setErrorBag($validator->errors());

                return false;
            }
        }

        if ($step === 2) {
            $rules = [
                'brand_name' => ['required', 'string', 'min:2', 'max:120'],
                'website_url' => ['nullable', 'url', 'max:255'],
                'primary_country' => ['required'],
                'primary_language' => ['required'],
            ];
            $validator = Validator::make([
                'brand_name' => $this->brand_name,
                'website_url' => $this->website_url !== '' ? $this->website_url : null,
                'primary_country' => $this->primary_country,
                'primary_language' => $this->primary_language,
            ], $rules, [], [
                'brand_name' => 'Brand name',
                'website_url' => 'Website URL',
            ]);
            if ($validator->fails()) {
                $this->setErrorBag($validator->errors());

                return false;
            }

            $existing = collect(DemoState::all()['brands'] ?? [])
                ->first(fn (array $b): bool => mb_strtolower((string) ($b['name'] ?? '')) === mb_strtolower(trim($this->brand_name)));
            if ($existing && ($this->brandId === '' || $existing['id'] !== $this->brandId)) {
                $this->duplicateBrandWarning = 'Possible existing Brand · '.($existing['name'] ?? '').' — continuing will create a new Brand unless you use the existing Brand entry point.';
            }
        }

        if ($step === 3 && $this->selected_assets === []) {
            $this->addError('selected_assets', 'Select at least one Digital Asset, or choose Website.');

            return false;
        }

        return true;
    }

    protected function acceptSelectedCandidates(): void
    {
        foreach ($this->accepted_candidate_ids as $id) {
            $candidate = collect(BrandPublicDiscoveryFixtures::candidates())->firstWhere('id', $id);
            if ($candidate === null || ($candidate['status'] ?? '') !== 'pending') {
                continue;
            }
            // Conflicts are not batch-accepted.
            if (($candidate['kind'] ?? '') === 'competitor') {
                continue;
            }
            DemoState::setDiscoveryCandidateStatus($id, 'accepted');
        }
    }

    protected function commitSetup(): void
    {
        $state = DemoState::all();

        if ($this->entry === 'customer' && $this->customerId === '') {
            $this->customerId = 'c-wiz-'.substr(md5($this->customer_name.microtime(true)), 0, 8);
            DemoState::addCustomer([
                'id' => $this->customerId,
                'name' => trim($this->customer_name),
                'legal_name' => trim($this->customer_name),
                'type' => 'company',
                'status' => 'active',
                'industry' => 'dental',
                'hq_country' => $this->primary_country,
                'account_owner_id' => $this->account_owner,
                'primary_email' => $this->contact_email,
                'primary_phone' => $this->contact_phone,
                'brands_count' => 0,
                'digital_assets_count' => 0,
                'open_findings' => 0,
                'open_tasks' => 0,
                'overdue_tasks' => 0,
            ]);
            if ($this->contact_name !== '' || $this->contact_email !== '') {
                DemoState::addContact([
                    'id' => 'ct-wiz-'.substr(md5($this->contact_name.microtime(true)), 0, 8),
                    'customer_id' => $this->customerId,
                    'name' => $this->contact_name !== '' ? $this->contact_name : 'Primary contact',
                    'email' => $this->contact_email,
                    'phone' => $this->contact_phone,
                    'role' => 'Primary',
                    'is_primary' => true,
                ]);
            }
        }

        if (in_array($this->entry, ['customer', 'brand'], true) && $this->brandId === '') {
            $this->brandId = 'b-wiz-'.substr(md5($this->brand_name.microtime(true)), 0, 8);
            $state = DemoState::all();
            $state['brands'][] = [
                'id' => $this->brandId,
                'customer_id' => $this->customerId !== '' ? $this->customerId : DemoCatalog::CUSTOMER_ID,
                'name' => trim($this->brand_name),
                'sector' => 'dental',
                'primary_country' => $this->primary_country,
                'target_markets' => [$this->primary_country],
                'languages' => [$this->primary_language],
                'responsible_user_ids' => $this->responsible_user_ids,
                'assets_count' => 0,
                'connected_assets' => 0,
                'open_findings' => 0,
                'open_tasks' => 0,
                'context_completed' => count($this->accepted_candidate_ids),
                'context_total' => 8,
                'website_url' => $this->website_url,
            ];
            DemoState::put($state);
        }

        $brandScope = $this->brandId !== '' ? $this->brandId : DemoCatalog::BRAND_ID;

        foreach ($this->selected_assets as $type) {
            if ($type === 'instagram') {
                continue;
            }
            if (! empty($this->skipped_providers[$type])) {
                continue;
            }

            $resourceId = $this->resource_selections[$type] ?? null;
            $existingType = collect(array_merge(DemoCatalog::assets(), DemoState::all()['demo_assets'] ?? []))
                ->first(fn (array $a): bool => ($a['brand_id'] ?? '') === $brandScope && ($a['type'] ?? '') === ($type === 'gbp' ? 'google_business_profile' : $type));

            if ($existingType !== null && $resourceId) {
                $connector = match ($type) {
                    'google_ads' => 'google-ads',
                    'meta_ads' => 'meta-ads',
                    'gbp' => 'gbp',
                    default => $type,
                };
                if (in_array($connector, ConnectorWorkspaceFixtures::ids(), true)) {
                    DemoState::bindConnectorResource($connector, $resourceId, (string) $existingType['id'], (string) $existingType['name'], $brandScope);
                }

                continue;
            }

            if ($existingType !== null) {
                continue;
            }

            $assetType = $type === 'gbp' ? 'google_business_profile' : $type;
            $name = match ($type) {
                'website' => parse_url($this->website_url, PHP_URL_HOST) ?: ($this->brand_name.' Website'),
                'ga4' => $this->brand_name.' — GA4',
                'gsc' => $this->brand_name.' — Search Console',
                'google_ads' => $this->brand_name.' — Google Ads',
                'meta_ads' => $this->brand_name.' — Meta',
                'gbp' => $this->brand_name,
                default => $this->brand_name.' · '.$type,
            };

            $id = 'da-wiz-'.substr(md5($type.$brandScope.microtime(true)), 0, 8);
            DemoState::addDemoAsset([
                'id' => $id,
                'brand_id' => $brandScope,
                'name' => $name,
                'type' => $assetType,
                'type_label' => $this->assetTypeLabel($assetType),
                'status' => 'active',
                'connection' => $resourceId ? 'connected' : ($type === 'website' ? 'manual' : 'not_configured'),
                'provenance' => $resourceId ? 'Bound via Setup Wizard (Demo)' : 'Setup Wizard',
                'health' => 'healthy',
                'health_label' => 'Healthy',
                'open_findings' => 0,
                'last_update' => 'Just now',
                'route' => match ($type) {
                    'website' => 'demo.website',
                    'ga4' => 'demo.analytics',
                    'gsc' => 'demo.search-console',
                    'google_ads' => 'demo.google-ads.overview',
                    'meta_ads' => 'demo.meta.overview',
                    'gbp' => 'demo.gbp',
                    default => 'demo.assets',
                },
                'domain' => $type === 'website' ? (parse_url($this->website_url, PHP_URL_HOST) ?: null) : null,
                'primary_url' => $type === 'website' ? $this->website_url : null,
            ]);

            if ($resourceId) {
                $connector = match ($type) {
                    'google_ads' => 'google-ads',
                    'meta_ads' => 'meta-ads',
                    'gbp' => 'gbp',
                    default => $type,
                };
                if (in_array($connector, ConnectorWorkspaceFixtures::ids(), true)) {
                    DemoState::bindConnectorResource($connector, $resourceId, $id, $name, $brandScope);
                }
            }
        }

        $this->committed = true;
        DemoState::flash('Portfolio setup completed (Demo Mode). Setup incomplete ≠ Brand unhealthy.');
    }

    protected function persist(): void
    {
        DemoState::saveWizardState([
            'entry' => $this->entry,
            'step' => $this->step,
            'customerId' => $this->customerId,
            'brandId' => $this->brandId,
            'customer_name' => $this->customer_name,
            'contact_name' => $this->contact_name,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'account_owner' => $this->account_owner,
            'brand_name' => $this->brand_name,
            'website_url' => $this->website_url,
            'primary_country' => $this->primary_country,
            'primary_language' => $this->primary_language,
            'responsible_user_ids' => $this->responsible_user_ids,
            'selected_assets' => $this->selected_assets,
            'resource_selections' => $this->resource_selections,
            'skipped_providers' => $this->skipped_providers,
            'accepted_candidate_ids' => $this->accepted_candidate_ids,
            'committed' => $this->committed,
        ]);
    }

    public function render(): View
    {
        $team = DemoCatalog::teamMembers();
        $matchCards = $this->matchCards();
        $candidates = collect(BrandPublicDiscoveryFixtures::candidates())
            ->where('status', 'pending')
            ->values()
            ->all();
        $conflicts = BrandPublicDiscoveryFixtures::conflicts();
        $reviewConflict = collect($conflicts)->firstWhere('id', $this->reviewConflictId);

        $steps = [
            1 => 'Customer',
            2 => 'Brand',
            3 => 'Digital Assets',
            4 => 'Connect',
            5 => 'Review',
            6 => 'Summary',
        ];

        if ($this->entry === 'brand') {
            unset($steps[1]);
        }
        if ($this->entry === 'asset') {
            unset($steps[1], $steps[2]);
        }

        return view('livewire.demo.portfolio.portfolio-setup-wizard', [
            'steps' => $steps,
            'team' => $team,
            'assetOptions' => $this->assetOptionCards(),
            'matchCards' => $matchCards,
            'candidates' => $candidates,
            'conflicts' => $conflicts,
            'reviewConflict' => $reviewConflict,
            'countryOptions' => CountryOptions::options(),
            'languageOptions' => LanguageOptions::options(),
            'flash' => DemoState::pullFlash(),
            'summary' => $this->summary(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function assetOptionCards(): array
    {
        return [
            ['type' => 'website', 'label' => 'Website', 'logo' => 'website'],
            ['type' => 'gbp', 'label' => 'Google Business Profile', 'logo' => 'gbp'],
            ['type' => 'google_ads', 'label' => 'Google Ads', 'logo' => 'google_ads'],
            ['type' => 'meta_ads', 'label' => 'Meta Ads', 'logo' => 'meta_ads'],
            ['type' => 'ga4', 'label' => 'Google Analytics', 'logo' => 'ga4'],
            ['type' => 'gsc', 'label' => 'Search Console', 'logo' => 'gsc'],
            ['type' => 'instagram', 'label' => 'Instagram', 'logo' => 'meta_ads', 'future' => true],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function matchCards(): array
    {
        $cards = [];
        foreach ($this->selected_assets as $type) {
            if ($type === 'website' || $type === 'instagram') {
                continue;
            }
            $connectorId = match ($type) {
                'google_ads' => 'google-ads',
                'meta_ads' => 'meta-ads',
                'gbp' => 'gbp',
                default => $type,
            };
            $connector = ConnectorWorkspaceFixtures::connector($connectorId);
            if ($connector === null) {
                continue;
            }
            $resources = collect($connector['resources'])
                ->filter(fn (array $r): bool => in_array($r['state'], ['available', 'bound'], true))
                ->values()
                ->all();
            $cards[] = [
                'type' => $type,
                'connector' => $connectorId,
                'label' => $connector['name'],
                'integration' => $connector['integration_label'],
                'integration_state' => 'Connected',
                'resources' => $resources,
                'selected' => $this->resource_selections[$type] ?? null,
                'skipped' => ! empty($this->skipped_providers[$type]),
            ];
        }

        return $cards;
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(): array
    {
        $configured = collect($this->selected_assets)
            ->reject(fn (string $t): bool => ! empty($this->skipped_providers[$t]) || $t === 'instagram')
            ->values()
            ->all();

        return [
            'customer' => $this->customer_name !== '' ? $this->customer_name : '—',
            'brand' => $this->brand_name !== '' ? $this->brand_name : '—',
            'assets' => $configured,
            'bound' => collect($this->resource_selections)->filter()->keys()->values()->all(),
            'accepted' => count($this->accepted_candidate_ids),
            'conflicts_open' => collect(BrandPublicDiscoveryFixtures::conflicts())
                ->filter(fn (array $c): bool => (DemoState::all()['discovery_conflict_resolutions'][$c['id']] ?? null) === null)
                ->count(),
            'brand_id' => $this->brandId !== '' ? $this->brandId : DemoCatalog::BRAND_ID,
        ];
    }

    protected function assetTypeLabel(string $type): string
    {
        return match ($type) {
            'website' => 'Website',
            'google_business_profile', 'gbp' => 'Google Business Profile',
            'google_ads' => 'Google Ads',
            'meta_ads' => 'Meta Ads',
            'ga4' => 'Google Analytics',
            'gsc' => 'Search Console',
            default => $type,
        };
    }
}
