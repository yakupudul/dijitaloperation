<?php

namespace App\Livewire\Demo\Portfolio;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\DigitalAssetStatus;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\DigitalAsset;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Services\Operator\OperatorUserDirectory;
use App\Support\Demo\DemoState;
use App\Support\Options\CountryOptions;
use App\Support\Options\LanguageOptions;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
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
            $requested = (string) request()->query('customerId', $this->customerId);
            abort_unless(ctype_digit($requested), 404);
            $customer = Customer::query()->find($requested);
            abort_if($customer === null, 404);
            $this->customerId = (string) $customer->id;
            $this->customer_name = $customer->name;
            if ($this->step < 2) {
                $this->step = 2;
            }
        }

        if ($this->entry === 'asset') {
            $requested = (string) request()->query('brandId', $this->brandId);
            abort_unless(ctype_digit($requested), 404);
            $brand = Brand::query()->with('customer')->find($requested);
            abort_if($brand === null, 404);
            $this->brandId = (string) $brand->id;
            $this->customerId = (string) $brand->customer_id;
            $this->customer_name = $brand->customer?->name ?? '';
            $this->brand_name = $brand->name;
            if ($this->step < 3) {
                $this->step = 3;
            }
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
        $this->reviewConflictId = null;
        $this->persist();
    }

    public function next(): void
    {
        if (! $this->validateStep($this->step)) {
            return;
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

            $query = Brand::query()->whereRaw('lower(name) = ?', [mb_strtolower(trim($this->brand_name))]);
            if (ctype_digit($this->customerId)) {
                $query->where('customer_id', (int) $this->customerId);
            }
            if (ctype_digit($this->brandId)) {
                $query->whereKeyNot((int) $this->brandId);
            }
            $existing = $query->first();
            if ($existing !== null) {
                $this->duplicateBrandWarning = 'Possible existing Brand · '.$existing->name.' — continuing will create a new Brand unless you use the existing Brand entry point.';
            }
        }

        if ($step === 3 && $this->selected_assets === []) {
            $this->addError('selected_assets', 'Select at least one Digital Asset, or choose Website.');

            return false;
        }

        return true;
    }

    protected function commitSetup(): void
    {
        DB::transaction(function (): void {
            $ownerIds = OperatorUserDirectory::sanitizeIds(
                array_values(array_filter([$this->account_owner, ...$this->responsible_user_ids]))
            );

            if ($this->entry === 'customer') {
                if ($this->customerId !== '' && ctype_digit($this->customerId)) {
                    $customer = Customer::query()->find($this->customerId);
                } else {
                    $customer = null;
                }

                if ($customer === null) {
                    $customer = Customer::query()->create([
                        'name' => trim($this->customer_name),
                        'legal_name' => trim($this->customer_name),
                        'type' => CustomerType::Company,
                        'status' => CustomerStatus::Active,
                        'hq_country' => $this->primary_country !== '' ? $this->primary_country : null,
                        'primary_email' => $this->contact_email !== '' ? trim($this->contact_email) : null,
                        'primary_phone' => $this->contact_phone !== '' ? trim($this->contact_phone) : null,
                    ]);
                    $this->customerId = (string) $customer->id;
                }

                $customer->responsibleUsers()->sync($ownerIds);

                if ($this->contact_name !== '' || $this->contact_email !== '') {
                    $existingContact = CustomerContact::query()
                        ->where('customer_id', $customer->id)
                        ->where('email', $this->contact_email !== '' ? trim($this->contact_email) : null)
                        ->first();
                    if ($existingContact === null) {
                        CustomerContact::query()->create([
                            'customer_id' => $customer->id,
                            'name' => $this->contact_name !== '' ? $this->contact_name : 'Primary contact',
                            'email' => $this->contact_email !== '' ? trim($this->contact_email) : null,
                            'phone' => $this->contact_phone !== '' ? trim($this->contact_phone) : null,
                            'title' => 'Primary',
                        ]);
                    }
                }
            }

            abort_unless(ctype_digit($this->customerId), 404);
            $customer = Customer::query()->findOrFail((int) $this->customerId);

            if (in_array($this->entry, ['customer', 'brand'], true)) {
                $brand = ($this->brandId !== '' && ctype_digit($this->brandId))
                    ? Brand::query()->where('customer_id', $customer->id)->find($this->brandId)
                    : null;

                if ($brand === null) {
                    $brand = Brand::query()->create([
                        'customer_id' => $customer->id,
                        'name' => trim($this->brand_name),
                        'primary_country' => $this->primary_country !== '' ? $this->primary_country : null,
                        'target_markets' => $this->primary_country !== '' ? [$this->primary_country] : [],
                        'languages' => $this->primary_language !== '' ? [$this->primary_language] : [],
                    ]);
                    $this->brandId = (string) $brand->id;
                }

                $brand->responsibleUsers()->sync(OperatorUserDirectory::sanitizeIds($this->responsible_user_ids));
            }

            abort_unless(ctype_digit($this->brandId), 404);
            $brand = Brand::query()->findOrFail((int) $this->brandId);
            abort_unless((int) $brand->customer_id === (int) $customer->id, 404);

            foreach ($this->selected_assets as $type) {
                if ($type === 'instagram') {
                    continue;
                }

                $assetType = OperatorPortfolioPresenter::canonicalAssetType($type);
                $existing = DigitalAsset::query()
                    ->where('brand_id', $brand->id)
                    ->where('type', $assetType)
                    ->first();
                if ($existing !== null) {
                    continue;
                }

                $name = match ($type) {
                    'website' => parse_url($this->website_url, PHP_URL_HOST) ?: ($this->brand_name.' Website'),
                    'ga4' => $this->brand_name.' — GA4',
                    'gsc' => $this->brand_name.' — Search Console',
                    'google_ads' => $this->brand_name.' — Google Ads',
                    'meta_ads' => $this->brand_name.' — Meta',
                    'gbp' => $this->brand_name,
                    default => $this->brand_name.' · '.$type,
                };

                DigitalAsset::query()->create([
                    'brand_id' => $brand->id,
                    'name' => $name,
                    'type' => $assetType,
                    'status' => DigitalAssetStatus::Active,
                    'module_id' => OperatorPortfolioPresenter::derivedModuleId($assetType),
                    'domain' => $type === 'website' ? (parse_url($this->website_url, PHP_URL_HOST) ?: null) : null,
                    'primary_url' => $type === 'website' && $this->website_url !== '' ? $this->website_url : null,
                ]);
            }
        });

        $this->committed = true;
        DemoState::flash('Portfolio setup saved. Digital assets are defined — not connected until integrations are configured.');
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
            'team' => OperatorUserDirectory::presentationMembers(),
            'assetOptions' => $this->assetOptionCards(),
            'matchCards' => $this->matchCards(),
            'candidates' => [],
            'conflicts' => [],
            'reviewConflict' => null,
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
            $label = $this->assetTypeLabel(OperatorPortfolioPresenter::canonicalAssetType($type));
            $cards[] = [
                'type' => $type,
                'connector' => $type,
                'label' => $label,
                'integration' => $type === 'meta_ads' ? 'Meta' : 'Google',
                'integration_state' => 'Not configured',
                'resources' => [],
                'selected' => $this->resource_selections[$type] ?? null,
                'skipped' => ! empty($this->skipped_providers[$type]),
                'blocker' => 'Configure integration first.',
            ];
        }

        return $cards;
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(): array
    {
        $defined = collect($this->selected_assets)
            ->reject(fn (string $t): bool => $t === 'instagram')
            ->values()
            ->all();

        return [
            'customer' => $this->customer_name !== '' ? $this->customer_name : '—',
            'brand' => $this->brand_name !== '' ? $this->brand_name : '—',
            'assets' => $defined,
            'bound' => [],
            'accepted' => 0,
            'conflicts_open' => 0,
            'brand_id' => $this->brandId,
            'google' => 'Not configured',
            'meta' => 'Not configured',
            'defined_count' => count($defined),
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
