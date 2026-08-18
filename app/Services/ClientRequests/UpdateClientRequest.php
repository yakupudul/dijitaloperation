<?php

namespace App\Services\ClientRequests;

use App\Enums\ClientRequestChannel;
use App\Enums\ClientRequestStatus;
use App\Exceptions\ClientRequestInvalidTransitionException;
use App\Models\ClientRequest;
use App\Models\CustomerContact;
use App\Models\DigitalAsset;
use App\Models\ServiceDefinition;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class UpdateClientRequest
{
    public function __construct(
        private readonly ClientRequestScopeResolver $scopeResolver,
        private readonly ClientRequestActivityRecorder $activity,
    ) {}

    /**
     * @param  array{
     *     title?: string|null,
     *     description?: string|null,
     *     digital_asset_id?: int|null,
     *     service_definition_id?: int|null,
     *     customer_contact_id?: int|null,
     *     owner_user_id?: int|null,
     *     channel?: string|null,
     *     priority?: string|null,
     *     effort?: string|null,
     *     due_label?: string|null,
     *     due_date?: string|null,
     * }  $input
     */
    public function update(ClientRequest $request, array $input, ?User $actor = null): ClientRequest
    {
        $request = ClientRequest::query()->findOrFail($request->id);

        $data = Validator::make($input, [
            'title' => ['sometimes', 'string', 'min:2', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'digital_asset_id' => ['sometimes', 'nullable', 'integer', Rule::exists('digital_assets', 'id')],
            'service_definition_id' => ['sometimes', 'nullable', 'integer', Rule::exists('service_definitions', 'id')],
            'customer_contact_id' => ['sometimes', 'nullable', 'integer', Rule::exists('customer_contacts', 'id')],
            'owner_user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
            'channel' => ['sometimes', 'nullable', 'string', Rule::in(array_column(ClientRequestChannel::cases(), 'value'))],
            'priority' => ['sometimes', 'nullable', 'string', Rule::in(['critical', 'high', 'medium', 'low'])],
            'effort' => ['sometimes', 'nullable', 'string', 'max:64'],
            'due_label' => ['sometimes', 'nullable', 'string', 'max:64'],
            'due_date' => ['sometimes', 'nullable', 'date'],
        ])->validate();

        if (array_key_exists('digital_asset_id', $data) && $data['digital_asset_id'] !== null) {
            $asset = DigitalAsset::query()->findOrFail((int) $data['digital_asset_id']);
            if ((int) $asset->brand_id !== (int) $request->brand_id) {
                throw ValidationException::withMessages([
                    'digital_asset_id' => 'DigitalAsset must belong to the Request Brand.',
                ]);
            }
        }

        if (array_key_exists('customer_contact_id', $data) && $data['customer_contact_id'] !== null) {
            $contact = CustomerContact::query()->findOrFail((int) $data['customer_contact_id']);
            if ((int) $contact->customer_id !== (int) $request->customer_id) {
                throw ValidationException::withMessages([
                    'customer_contact_id' => 'Requester contact must belong to the Request Customer.',
                ]);
            }
        }

        $serviceChanged = array_key_exists('service_definition_id', $data)
            && (int) ($data['service_definition_id'] ?? 0) !== (int) ($request->service_definition_id ?? 0);

        $ownerChanged = array_key_exists('owner_user_id', $data)
            && (int) ($data['owner_user_id'] ?? 0) !== (int) ($request->owner_user_id ?? 0);

        $meaningfulContentChange = false;
        foreach (['title', 'description', 'channel', 'priority', 'effort', 'due_label', 'due_date', 'digital_asset_id', 'customer_contact_id'] as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $incoming = $data[$field];
            $current = $request->{$field};
            if ($field === 'description' && is_string($incoming)) {
                $incoming = strip_tags($incoming);
            }
            if ((string) $incoming !== (string) ($current ?? '')) {
                $meaningfulContentChange = true;
            }
        }

        return DB::transaction(function () use ($request, $data, $serviceChanged, $ownerChanged, $meaningfulContentChange, $actor): ClientRequest {
            if (isset($data['title'])) {
                $request->title = trim((string) $data['title']);
            }
            if (array_key_exists('description', $data)) {
                $request->description = $data['description'] === null
                    ? null
                    : strip_tags((string) $data['description']);
            }
            foreach (['digital_asset_id', 'service_definition_id', 'customer_contact_id', 'owner_user_id', 'channel', 'priority', 'effort', 'due_label', 'due_date'] as $field) {
                if (array_key_exists($field, $data)) {
                    $request->{$field} = $data[$field];
                }
            }

            $request->save();

            if ($serviceChanged) {
                $service = $request->service_definition_id !== null
                    ? ServiceDefinition::query()->find($request->service_definition_id)
                    : null;
                $resolution = $this->scopeResolver->resolve($request->fresh());
                // Intake snapshot is set once at create; reclassification after intake records Activity
                // and does not rewrite historical intake fields.
                $this->activity->record($request, ClientRequestActivityRecorder::SCOPE_CLASSIFIED, $actor, [
                    'current_scope_state' => $resolution->state->value,
                    'service_definition_id' => $service?->id,
                    'service_definition_code' => $service?->code,
                ]);
            }

            if ($ownerChanged) {
                $this->activity->record($request, ClientRequestActivityRecorder::OWNER_CHANGED, $actor);
            }

            if ($meaningfulContentChange) {
                $this->activity->record($request, ClientRequestActivityRecorder::UPDATED, $actor);
            }

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
    }

    public function changeStatus(ClientRequest $request, ClientRequestStatus $to, ?User $actor = null): ClientRequest
    {
        $request = ClientRequest::query()->findOrFail($request->id);
        $from = $request->status;

        if ($from === $to) {
            return $request;
        }

        if (! $from->canTransitionTo($to)) {
            throw new ClientRequestInvalidTransitionException($request, $from, $to);
        }

        return DB::transaction(function () use ($request, $from, $to, $actor): ClientRequest {
            $request->status = $to;
            if ($to->isTerminal()) {
                $request->closed_at = now();
            }
            $request->save();

            $this->activity->record($request, ClientRequestActivityRecorder::STATUS_CHANGED, $actor, [
                'from_status' => $from->value,
                'to_status' => $to->value,
            ]);

            return $request->fresh() ?? $request;
        });
    }

    /**
     * Explicit human-confirmed Service classification. Never infers from title/body.
     */
    public function classifyService(
        ClientRequest $request,
        ?ServiceDefinition $service,
        ?User $actor = null,
    ): ClientRequest {
        return $this->update($request, [
            'service_definition_id' => $service?->id,
        ], $actor);
    }
}
