<?php

namespace App\Services\ClientRequests;

use App\Enums\ClientRequestChannel;
use App\Enums\ClientRequestStatus;
use App\Models\Brand;
use App\Models\ClientRequest;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\DigitalAsset;
use App\Models\ServiceDefinition;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Authoritative Client Request writer. Never creates Evidence/Finding/Opportunity/
 * Recommendation/Task/Service Scope. Never infers Service/Goal/Offering from text.
 */
final class CreateClientRequest
{
    public function __construct(
        private readonly ClientRequestScopeResolver $scopeResolver,
        private readonly ClientRequestActivityRecorder $activity,
    ) {}

    /**
     * @param  array{
     *     title: string,
     *     description?: string|null,
     *     customer_id: int,
     *     brand_id: int,
     *     digital_asset_id?: int|null,
     *     service_definition_id?: int|null,
     *     customer_contact_id?: int|null,
     *     owner_user_id?: int|null,
     *     channel?: string|null,
     *     priority?: string|null,
     *     effort?: string|null,
     *     due_label?: string|null,
     *     due_date?: string|null,
     *     status?: string|null,
     * }  $input
     *
     * @throws ValidationException
     */
    public function create(array $input, ?User $actor = null, ?string $idempotencyKey = null): ClientRequest
    {
        if ($idempotencyKey !== null) {
            $existing = $this->findByIdempotencyKey($idempotencyKey);
            if ($existing instanceof ClientRequest) {
                return $existing;
            }
        }

        $data = Validator::make($input, [
            'title' => ['required', 'string', 'min:2', 'max:255'],
            'description' => ['nullable', 'string'],
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')],
            'brand_id' => ['required', 'integer', Rule::exists('brands', 'id')],
            'digital_asset_id' => ['nullable', 'integer', Rule::exists('digital_assets', 'id')],
            'service_definition_id' => ['nullable', 'integer', Rule::exists('service_definitions', 'id')],
            'customer_contact_id' => ['nullable', 'integer', Rule::exists('customer_contacts', 'id')],
            'owner_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'channel' => ['nullable', 'string', Rule::in(array_column(ClientRequestChannel::cases(), 'value'))],
            'priority' => ['nullable', 'string', Rule::in(['critical', 'high', 'medium', 'low'])],
            'effort' => ['nullable', 'string', 'max:64'],
            'due_label' => ['nullable', 'string', 'max:64'],
            'due_date' => ['nullable', 'date'],
            'status' => ['nullable', 'string', Rule::in(array_column(ClientRequestStatus::cases(), 'value'))],
        ])->validate();

        $customer = Customer::query()->findOrFail((int) $data['customer_id']);
        $brand = Brand::query()->findOrFail((int) $data['brand_id']);

        if ((int) $brand->customer_id !== (int) $customer->id) {
            throw ValidationException::withMessages([
                'brand_id' => 'Brand must belong to the Request Customer.',
            ]);
        }

        $digitalAssetId = isset($data['digital_asset_id']) ? (int) $data['digital_asset_id'] : null;
        if ($digitalAssetId !== null) {
            $asset = DigitalAsset::query()->findOrFail($digitalAssetId);
            if ((int) $asset->brand_id !== (int) $brand->id) {
                throw ValidationException::withMessages([
                    'digital_asset_id' => 'DigitalAsset must belong to the Request Brand.',
                ]);
            }
        }

        $contactId = isset($data['customer_contact_id']) ? (int) $data['customer_contact_id'] : null;
        if ($contactId !== null) {
            $contact = CustomerContact::query()->findOrFail($contactId);
            if ((int) $contact->customer_id !== (int) $customer->id) {
                throw ValidationException::withMessages([
                    'customer_contact_id' => 'Requester contact must belong to the Request Customer.',
                ]);
            }
        }

        $serviceId = isset($data['service_definition_id']) ? (int) $data['service_definition_id'] : null;
        $service = $serviceId !== null
            ? ServiceDefinition::query()->findOrFail($serviceId)
            : null;

        $title = trim((string) $data['title']);
        $description = isset($data['description'])
            ? $this->sanitizeText((string) $data['description'])
            : null;

        $attributes = [
            'customer_id' => $customer->id,
            'brand_id' => $brand->id,
            'digital_asset_id' => $digitalAssetId,
            'service_definition_id' => $service?->id,
            'customer_contact_id' => $contactId,
            'owner_user_id' => $data['owner_user_id'] ?? null,
            'created_by_user_id' => $actor?->id,
            'title' => $title,
            'description' => $description,
            'status' => $data['status'] ?? ClientRequestStatus::New->value,
            'channel' => $data['channel'] ?? null,
            'priority' => $data['priority'] ?? 'medium',
            'effort' => $data['effort'] ?? null,
            'due_label' => $data['due_label'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'idempotency_key' => $idempotencyKey,
        ];

        try {
            return DB::transaction(function () use ($attributes, $service, $actor): ClientRequest {
                $request = ClientRequest::query()->create($attributes);

                $resolution = $this->scopeResolver->resolve($request);
                $request->forceFill([
                    'intake_scope_state' => $resolution->state,
                    'intake_scope_snapshot' => $this->scopeResolver->snapshotArray($resolution, $service),
                    'intake_scope_assessed_at' => $resolution->evaluatedAt,
                ])->save();

                $this->activity->record($request->fresh(), ClientRequestActivityRecorder::CREATED, $actor, [
                    'current_scope_state' => $resolution->state->value,
                ]);

                return $request->fresh([
                    'customer',
                    'brand',
                    'digitalAsset',
                    'serviceDefinition',
                    'requester',
                    'owner',
                    'createdBy',
                ]) ?? $request;
            });
        } catch (QueryException $exception) {
            if ($idempotencyKey !== null) {
                $existing = $this->findByIdempotencyKey($idempotencyKey);
                if ($existing instanceof ClientRequest) {
                    return $existing;
                }
            }

            throw $exception;
        }
    }

    private function findByIdempotencyKey(string $key): ?ClientRequest
    {
        return ClientRequest::query()->where('idempotency_key', $key)->first();
    }

    private function sanitizeText(string $value): string
    {
        $stripped = strip_tags($value);

        return trim($stripped) === '' ? '' : $stripped;
    }
}
