<?php

namespace App\Panel\Services;

use App\Panel\Models\PanelEvent;
use App\Panel\Models\PanelPerson;
use App\Panel\Models\PanelRequest;
use App\Panel\Support\PanelContext;
use App\Panel\Support\PanelSources;
use App\Panel\Support\PanelStages;
use App\Panel\Support\PhoneKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Everything that writes a person's record: a new request, a stage change, a note, a history line. */
final class PanelLeads
{
    public function __construct(private readonly PanelContext $context) {}

    /** The people the signed-in user may see: all of the brand's, or only those assigned to them. */
    public function visible(): Builder
    {
        $query = PanelPerson::query();
        if (! $this->context->can('leads.all')) {
            $query->where('assigned_user_id', (int) Auth::guard('client')->id());
        }

        return $query;
    }

    /**
     * A request arrived (site form, a call written by hand, a walk-in). The phone number decides whether this is a
     * known person of the brand; a known person gets a new request on the same record.
     *
     * @param  array{name?: ?string, phone?: ?string, email?: ?string, interest?: ?string, message?: ?string, city?: ?string, district?: ?string, gender?: ?string, birth_year?: ?int, meta?: array<string, mixed>, assigned_user_id?: ?int}  $data
     */
    public function record(int $brandId, array $data, string $source, ?int $actorId = null): PanelPerson
    {
        if ($this->context->brandId() !== null && $this->context->brandId() !== $brandId) {
            throw new InvalidArgumentException('A request cannot be written to another brand.');
        }
        $source = PanelSources::normalize($source);
        $phone = trim((string) ($data['phone'] ?? ''));
        $key = PhoneKey::of($phone);
        $name = trim((string) ($data['name'] ?? '')) ?: ($phone !== '' ? $phone : 'İsimsiz');

        $person = $this->context->system(fn (): PanelPerson => DB::transaction(function () use ($brandId, $data, $source, $phone, $key, $name, $actorId): PanelPerson {
            $person = $key !== null ? PanelPerson::query()->where('brand_id', $brandId)->where('phone_key', $key)->lockForUpdate()->first() : null;
            $isNew = $person === null;
            if ($isNew) {
                $person = new PanelPerson;
                $person->brand_id = $brandId;
                $person->fill(['name' => $name, 'phone' => $phone ?: null, 'stage' => PanelStages::NEW, 'source' => $source]);
            } elseif (in_array($person->stage, [PanelStages::LOST, PanelStages::NOT_LEAD], true)) {
                // Someone who was closed and asks again is a new chance.
                $this->event($person, PanelEvent::STAGE, null, ['from' => $person->stage, 'to' => PanelStages::NEW, 'why' => 'yeni talep'], $actorId);
                $person->stage = PanelStages::NEW;
                $person->lost_reason = null;
            }
            foreach (['email', 'city', 'district', 'gender', 'birth_year', 'interest', 'assigned_user_id'] as $field) {
                $value = $data[$field] ?? null;
                if ($value !== null && $value !== '' && ($isNew || empty($person->{$field}))) {
                    $person->{$field} = $value;
                }
            }
            if (! $isNew && trim((string) ($data['name'] ?? '')) !== '' && ($person->name === $person->phone || $person->name === 'İsimsiz')) {
                $person->name = $name;
            }
            $person->last_activity_at = now();
            $person->save();

            $request = new PanelRequest;
            $request->brand_id = $brandId;
            $request->fill([
                'person_id' => $person->id, 'source' => $source, 'interest' => $data['interest'] ?? null,
                'message' => ($data['message'] ?? null) ?: null, 'meta' => ($data['meta'] ?? null) ?: null, 'received_at' => now(),
            ])->save();

            $this->event($person, $source === 'call' ? PanelEvent::CALL : PanelEvent::REQUEST, ($data['message'] ?? null) ?: null, ['source' => $source, 'interest' => $data['interest'] ?? null, 'first' => $isNew], $actorId);

            return $person;
        }));
        // Nobody typed this one in (site form, a connected channel): the brand's users hear the bell.
        if ($actorId === null) {
            app(PanelNotifier::class)->brand($brandId, 'lead', 'Yeni talep: '.$person->name.', '.PanelSources::label($source), route('panel.person', $person->id), 'menu.leads');
        }

        return $person;
    }

    public function setStage(PanelPerson $person, string $stage, ?string $reason = null, ?int $actorId = null): void
    {
        if (! array_key_exists($stage, PanelStages::labels())) {
            throw new InvalidArgumentException('Unknown stage.');
        }
        if ($stage === PanelStages::LOST && ! array_key_exists((string) $reason, PanelStages::lostReasons())) {
            throw new InvalidArgumentException('A lost person needs a reason.');
        }
        if ($person->stage === $stage && $person->lost_reason === ($stage === PanelStages::LOST ? $reason : null)) {
            return;
        }
        $from = $person->stage;
        $person->stage = $stage;
        $person->lost_reason = $stage === PanelStages::LOST ? $reason : null;
        $person->last_activity_at = now();
        $person->save();
        $this->event($person, PanelEvent::STAGE, null, ['from' => $from, 'to' => $stage, 'reason' => $person->lost_reason], $actorId);
    }

    /** @param  array<string, mixed>  $meta */
    public function event(PanelPerson $person, string $type, ?string $body = null, array $meta = [], ?int $actorId = null): PanelEvent
    {
        $event = new PanelEvent;
        $event->brand_id = $person->brand_id;
        $event->fill(['person_id' => $person->id, 'type' => $type, 'body' => $body, 'meta' => $meta ?: null, 'panel_user_id' => $actorId, 'occurred_at' => now()]);
        $event->save();

        return $event;
    }

    public function note(PanelPerson $person, string $body, ?int $actorId, string $type = PanelEvent::NOTE): PanelEvent
    {
        $person->forceFill(['last_activity_at' => now()])->save();

        return $this->event($person, $type, trim($body), [], $actorId);
    }
}
