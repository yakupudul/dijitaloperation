<?php

namespace App\Services\Sales;

use App\Models\SalesSearchProfile;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class SearchProfileService
{
    public function __construct(
        private readonly IntentActivityRecorder $activities = new IntentActivityRecorder,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): SalesSearchProfile
    {
        $profile = SalesSearchProfile::query()->create($this->payload($data));

        $this->activities->record(
            'search_profile.created',
            __('operator.sales_intent.activity.profile_created'),
            $profile,
            actor: $actor,
        );

        return $profile;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(SalesSearchProfile $profile, array $data): SalesSearchProfile
    {
        $profile->fill($this->payload($data));
        $profile->save();

        return $profile->fresh() ?? $profile;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if (strlen($name) < 2) {
            throw ValidationException::withMessages([
                'name' => [__('operator.sales_intent.profile_name_required')],
            ]);
        }

        $includes = $this->stringList($data['include_concepts'] ?? []);
        if ($includes === []) {
            throw ValidationException::withMessages([
                'include_concepts' => [__('operator.sales_intent.include_required')],
            ]);
        }

        $min = (int) ($data['minimum_intent_confidence'] ?? 60);

        return [
            'name' => $name,
            'service_definition_code' => isset($data['service_definition_code']) && $data['service_definition_code'] !== ''
                ? (string) $data['service_definition_code']
                : null,
            'language' => isset($data['language']) && $data['language'] !== '' ? (string) $data['language'] : null,
            'country' => isset($data['country']) && $data['country'] !== '' ? (string) $data['country'] : null,
            'location' => isset($data['location']) && $data['location'] !== '' ? (string) $data['location'] : null,
            'include_concepts' => $includes,
            'exclude_concepts' => $this->stringList($data['exclude_concepts'] ?? []),
            'minimum_intent_confidence' => max(0, min(100, $min)),
            'active' => (bool) ($data['active'] ?? true),
            'owner_user_id' => isset($data['owner_user_id']) && $data['owner_user_id'] !== ''
                ? (int) $data['owner_user_id']
                : null,
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\r\n|\n|,/', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }
            $trimmed = trim($item);
            if ($trimmed !== '') {
                $out[] = $trimmed;
            }
        }

        return array_values(array_unique($out));
    }
}
