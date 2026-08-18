<?php

namespace App\Services\Sales;

use App\Enums\IntentSignalStatus;
use App\Enums\ProspectIdentityStatus;
use App\Enums\ProspectSource;
use App\Enums\ProspectStatus;
use App\Models\Prospect;
use App\Models\SalesIntentSignal;
use App\Models\User;
use App\Services\Prospects\CreateProspectService;
use App\Services\Prospects\ProspectActivityRecorder;
use Illuminate\Validation\ValidationException;

final class CreateProspectFromIntentSignalService
{
    public function __construct(
        private readonly CreateProspectService $createProspect = new CreateProspectService,
        private readonly IntentActivityRecorder $intentActivities = new IntentActivityRecorder,
        private readonly ProspectActivityRecorder $prospectActivities = new ProspectActivityRecorder,
    ) {}

    public function create(SalesIntentSignal $signal, User $actor): Prospect
    {
        if ($signal->prospect_id !== null) {
            return Prospect::query()->findOrFail($signal->prospect_id);
        }

        if ($signal->status === IntentSignalStatus::Dismissed) {
            throw ValidationException::withMessages([
                'signal' => [__('operator.sales_intent.cannot_convert_dismissed')],
            ]);
        }

        $company = $signal->detected_company_name;
        if (! is_string($company) || trim($company) === '') {
            $company = __('operator.sales_intent.anonymous_prospect_name');
        }

        $website = null;
        if (is_string($signal->detected_domain) && $signal->detected_domain !== '') {
            $website = str_contains($signal->detected_domain, '://')
                ? $signal->detected_domain
                : 'https://'.$signal->detected_domain;
        }

        $inquiry = trim($signal->observed_snippet);
        if ($signal->source_url) {
            $inquiry .= "\n\n".$signal->source_url;
        }

        $prospect = $this->createProspect->create([
            'company_name' => $company,
            'website_url' => $website,
            'source' => ProspectSource::IntentRadar->value,
            'inquiry' => $inquiry,
            'identity_status' => ($signal->identity_status ?? ProspectIdentityStatus::Unknown)->value,
            'status' => ProspectStatus::New->value,
            'owner_user_id' => $signal->searchProfile?->owner_user_id,
        ], $actor);

        $signal->prospect_id = $prospect->id;
        $signal->status = IntentSignalStatus::ConvertedToProspect;
        $signal->save();

        $this->intentActivities->record(
            'intent_signal.converted_to_prospect',
            __('operator.sales_intent.activity.converted_to_prospect'),
            $signal->searchProfile,
            $signal->run,
            $signal,
            $prospect,
            $actor,
        );

        $this->prospectActivities->record(
            $prospect,
            'prospect.created_from_intent_signal',
            __('operator.prospects.activity.created_from_intent'),
            $signal->source_url,
            $actor,
            ['intent_signal_id' => $signal->id],
        );

        return $prospect;
    }
}
