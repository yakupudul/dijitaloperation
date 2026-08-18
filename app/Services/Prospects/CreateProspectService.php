<?php

namespace App\Services\Prospects;

use App\Enums\ProspectIdentityStatus;
use App\Enums\ProspectSource;
use App\Enums\ProspectStatus;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CreateProspectService
{
    public function __construct(
        private readonly ProspectWebsiteValidator $websiteValidator = new ProspectWebsiteValidator,
        private readonly ProspectActivityRecorder $activities = new ProspectActivityRecorder,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): Prospect
    {
        $websiteUrl = null;
        if (isset($data['website_url']) && is_string($data['website_url']) && trim($data['website_url']) !== '') {
            try {
                $websiteUrl = $this->websiteValidator->assertSafe($data['website_url']);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'website_url' => [$exception->getMessage()],
                ]);
            }
        }

        $source = isset($data['source']) && $data['source'] instanceof ProspectSource
            ? $data['source']
            : ProspectSource::from((string) ($data['source'] ?? ProspectSource::Manual->value));

        $identityStatus = isset($data['identity_status']) && $data['identity_status'] instanceof ProspectIdentityStatus
            ? $data['identity_status']
            : ProspectIdentityStatus::from((string) ($data['identity_status'] ?? ProspectIdentityStatus::Unknown->value));

        $status = isset($data['status']) && $data['status'] instanceof ProspectStatus
            ? $data['status']
            : ProspectStatus::from((string) ($data['status'] ?? ProspectStatus::New->value));

        $prospect = Prospect::query()->create([
            'company_name' => trim((string) ($data['company_name'] ?? '')),
            'website_url' => $websiteUrl,
            'source' => $source,
            'inquiry' => isset($data['inquiry']) ? trim((string) $data['inquiry']) : null,
            'contact_name' => isset($data['contact_name']) ? trim((string) $data['contact_name']) : null,
            'contact_email' => isset($data['contact_email']) ? trim((string) $data['contact_email']) : null,
            'contact_phone' => isset($data['contact_phone']) ? trim((string) $data['contact_phone']) : null,
            'country' => isset($data['country']) ? trim((string) $data['country']) : null,
            'city' => isset($data['city']) ? trim((string) $data['city']) : null,
            'identity_status' => $identityStatus,
            'status' => $status,
            'owner_user_id' => isset($data['owner_user_id']) && $data['owner_user_id'] !== ''
                ? (int) $data['owner_user_id']
                : null,
        ]);

        $this->activities->record(
            $prospect,
            'prospect.created',
            __('operator.prospects.activity.created'),
            $prospect->company_name,
            $actor,
        );

        return $prospect;
    }
}
