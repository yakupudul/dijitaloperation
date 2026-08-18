<?php

namespace App\Livewire\Demo\Sales;

use App\Enums\ProspectIdentityStatus;
use App\Enums\ProspectSource;
use App\Enums\ProspectStatus;
use App\Services\Operator\OperatorUserDirectory;
use App\Services\Prospects\CreateProspectService;
use App\Services\Prospects\ProspectReadService;
use App\Support\Demo\DemoState;
use App\Support\Options\CountryOptions;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('New Prospect')]
class ProspectCreate extends Component
{
    public string $company_name = '';

    public string $website_url = '';

    public string $source = 'whatsapp';

    public string $inquiry = '';

    public string $contact_name = '';

    public string $contact_email = '';

    public string $contact_phone = '';

    public string $country = '';

    public string $city = '';

    public string $owner_user_id = '';

    public bool $saving = false;

    public function save(): mixed
    {
        if ($this->saving) {
            return null;
        }

        $this->saving = true;

        try {
            $validated = $this->validate($this->rules(), [], $this->validationAttributes());

            $prospect = app(CreateProspectService::class)->create([
                ...$validated,
                'identity_status' => ProspectIdentityStatus::Unknown->value,
                'status' => ProspectStatus::New->value,
            ], auth()->user());

            DemoState::flash(__('operator.prospects.saved', ['name' => $prospect->company_name]));

            return $this->redirect(route('operator.prospect', ['prospectId' => $prospect->id]), navigate: true);
        } finally {
            $this->saving = false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'website_url' => ['nullable', 'string', 'max:2048'],
            'source' => ['required', Rule::enum(ProspectSource::class)],
            'inquiry' => ['nullable', 'string', 'max:5000'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:64'],
            'country' => ['nullable', 'string', 'max:8'],
            'city' => ['nullable', 'string', 'max:128'],
            'owner_user_id' => ['nullable', Rule::in(OperatorUserDirectory::eligibleIds())],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function validationAttributes(): array
    {
        return [
            'company_name' => __('operator.prospects.fields.company_name'),
            'website_url' => __('operator.prospects.fields.website'),
            'source' => __('operator.prospects.fields.source'),
            'inquiry' => __('operator.prospects.fields.inquiry'),
            'contact_name' => __('operator.prospects.fields.contact_name'),
            'contact_email' => __('operator.prospects.fields.contact_email'),
            'contact_phone' => __('operator.prospects.fields.contact_phone'),
            'country' => __('operator.prospects.fields.country'),
            'city' => __('operator.prospects.fields.city'),
            'owner_user_id' => __('operator.prospects.fields.owner'),
        ];
    }

    public function render(): View
    {
        return view('livewire.demo.sales.prospect-form', [
            'pageTitle' => __('operator.prospects.new_prospect'),
            'pageSubtitle' => __('operator.prospects.create_subtitle'),
            'backUrl' => route('operator.prospects'),
            'backLabel' => __('operator.nav.prospects'),
            'sourceOptions' => ProspectReadService::sourceOptions(),
            'countryOptions' => CountryOptions::options(),
            'ownerOptions' => OperatorUserDirectory::options(),
        ]);
    }
}
