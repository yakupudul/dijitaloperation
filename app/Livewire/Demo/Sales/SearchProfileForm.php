<?php

namespace App\Livewire\Demo\Sales;

use App\Models\SalesSearchProfile;
use App\Services\Operator\OperatorUserDirectory;
use App\Services\Sales\SearchProfileService;
use App\Support\Demo\DemoState;
use App\Support\Options\AgencyServiceOptions;
use App\Support\Options\CountryOptions;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Search Profile')]
class SearchProfileForm extends Component
{
    public ?string $profileId = null;

    public string $name = '';

    public string $service_definition_code = 'website_design';

    public string $language = 'tr';

    public string $country = 'TR';

    public string $location = '';

    public string $include_concepts = '';

    public string $exclude_concepts = '';

    public int $minimum_intent_confidence = 60;

    public bool $active = true;

    public string $owner_user_id = '';

    public function mount(?string $profileId = null): void
    {
        if ($profileId === null) {
            $this->owner_user_id = (string) (auth()->id() ?? '');

            return;
        }

        abort_unless(ctype_digit($profileId), 404);
        $profile = SalesSearchProfile::query()->find($profileId);
        abort_if($profile === null, 404);
        $this->profileId = $profileId;
        $this->name = $profile->name;
        $this->service_definition_code = (string) ($profile->service_definition_code ?? '');
        $this->language = (string) ($profile->language ?? '');
        $this->country = (string) ($profile->country ?? '');
        $this->location = (string) ($profile->location ?? '');
        $this->include_concepts = implode("\n", is_array($profile->include_concepts) ? $profile->include_concepts : []);
        $this->exclude_concepts = implode("\n", is_array($profile->exclude_concepts) ? $profile->exclude_concepts : []);
        $this->minimum_intent_confidence = (int) $profile->minimum_intent_confidence;
        $this->active = (bool) $profile->active;
        $this->owner_user_id = $profile->owner_user_id ? (string) $profile->owner_user_id : '';
    }

    public function save(): mixed
    {
        $payload = [
            'name' => $this->name,
            'service_definition_code' => $this->service_definition_code,
            'language' => $this->language,
            'country' => $this->country,
            'location' => $this->location,
            'include_concepts' => $this->include_concepts,
            'exclude_concepts' => $this->exclude_concepts,
            'minimum_intent_confidence' => $this->minimum_intent_confidence,
            'active' => $this->active,
            'owner_user_id' => $this->owner_user_id,
        ];

        if ($this->profileId) {
            app(SearchProfileService::class)->update(SalesSearchProfile::query()->findOrFail($this->profileId), $payload);
            DemoState::flash(__('operator.sales_intent.profile_saved'));

            return $this->redirect(route('operator.search-profiles'), navigate: true);
        }

        $profile = app(SearchProfileService::class)->create($payload, auth()->user());
        DemoState::flash(__('operator.sales_intent.profile_saved'));

        return $this->redirect(route('operator.search-profile', ['profileId' => $profile->id]), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.demo.sales.search-profile-form', [
            'serviceOptions' => AgencyServiceOptions::options(),
            'countryOptions' => CountryOptions::options(),
            'ownerOptions' => OperatorUserDirectory::options(),
        ]);
    }
}
