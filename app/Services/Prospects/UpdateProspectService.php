<?php

namespace App\Services\Prospects;

use App\Enums\ProspectIdentityStatus;
use App\Enums\ProspectSource;
use App\Enums\ProspectStatus;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class UpdateProspectService
{
    public function __construct(
        private readonly ProspectWebsiteValidator $websiteValidator = new ProspectWebsiteValidator,
        private readonly ProspectActivityRecorder $activities = new ProspectActivityRecorder,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Prospect $prospect, array $data, ?User $actor = null): Prospect
    {
        $previousStatus = $prospect->status;

        if (array_key_exists('website_url', $data)) {
            $raw = $data['website_url'];
            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                $prospect->website_url = null;
            } else {
                try {
                    $prospect->website_url = $this->websiteValidator->assertSafe((string) $raw);
                } catch (InvalidArgumentException $exception) {
                    throw ValidationException::withMessages([
                        'website_url' => [$exception->getMessage()],
                    ]);
                }
            }
        }

        foreach ([
            'company_name' => 'company_name',
            'inquiry' => 'inquiry',
            'contact_name' => 'contact_name',
            'contact_email' => 'contact_email',
            'contact_phone' => 'contact_phone',
            'country' => 'country',
            'city' => 'city',
        ] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $value = $data[$key];
                $prospect->{$column} = is_string($value) ? trim($value) : $value;
            }
        }

        if (array_key_exists('source', $data)) {
            $prospect->source = $data['source'] instanceof ProspectSource
                ? $data['source']
                : ProspectSource::from((string) $data['source']);
        }

        if (array_key_exists('identity_status', $data)) {
            $prospect->identity_status = $data['identity_status'] instanceof ProspectIdentityStatus
                ? $data['identity_status']
                : ProspectIdentityStatus::from((string) $data['identity_status']);
        }

        if (array_key_exists('status', $data)) {
            $prospect->status = $data['status'] instanceof ProspectStatus
                ? $data['status']
                : ProspectStatus::from((string) $data['status']);
        }

        if (array_key_exists('owner_user_id', $data)) {
            $owner = $data['owner_user_id'];
            $prospect->owner_user_id = $owner === null || $owner === '' ? null : (int) $owner;
        }

        $prospect->save();

        $this->activities->record(
            $prospect,
            'prospect.updated',
            __('operator.prospects.activity.updated'),
            null,
            $actor,
        );

        if ($previousStatus !== $prospect->status) {
            $this->activities->record(
                $prospect,
                'prospect.status_changed',
                __('operator.prospects.activity.status_changed'),
                __('operator.prospects.activity.status_changed_detail', [
                    'from' => __('operator.prospects.statuses.'.$previousStatus->value),
                    'to' => __('operator.prospects.statuses.'.$prospect->status->value),
                ]),
                $actor,
                ['from' => $previousStatus->value, 'to' => $prospect->status->value],
            );
        }

        return $prospect->fresh(['owner', 'latestResearchRun', 'latestSalesIntelligence']);
    }
}
