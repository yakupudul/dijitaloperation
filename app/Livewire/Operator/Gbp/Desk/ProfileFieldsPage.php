<?php

namespace App\Livewire\Operator\Gbp\Desk;

use App\Models\ExternalWriteAction;
use App\Models\Suggestion;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Gbp\Desk\GbpDesk;
use App\Services\Gbp\Desk\ProfileFields;
use App\Services\Gbp\GbpAssistant;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * İşletme profilleri › Açıklama ve saatler (`/gbp/aciklama-ve-saatler`, ADR-079).
 *  - Açıklama: every profile's description with its length and the AI proposal (one or all missing / short at once);
 *    the Admin edits and sends one, or sends every ready proposal of the brands in scope.
 *  - Özel günler: the coming official holidays; which profiles entered hours for them; the Admin sets closed or
 *    custom hours per date and sends them to the chosen profiles.
 */
#[Layout('operator.layouts.app')]
#[Title('Açıklama ve saatler')]
final class ProfileFieldsPage extends Component
{
    use DeskScope;

    #[Url(as: 'bolum')]
    public string $section = 'aciklama';

    public ?int $editing = null;

    public string $editText = '';

    #[Url(as: 'tatil')]
    public string $holiday = '';

    /** @var array<string, array{mode: string, open: string, close: string}> */
    public array $hours = [];

    /** @var list<int> */
    public array $selected = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_active, 403);
        $this->section = in_array($this->section, ['aciklama', 'saatler'], true) ? $this->section : 'aciklama';
    }

    public function setSection(string $section): void
    {
        $this->section = in_array($section, ['aciklama', 'saatler'], true) ? $section : 'aciklama';
    }

    public function prepare(int $assetId, ProfileFields $fields): void
    {
        abort_unless($this->canWrite(), 403);
        try {
            $fields->prepareDescriptions(collect([$this->location($assetId)]));
            $this->say('Açıklama yazılıyor; hazır olunca bu satırda görünür.');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function prepareWeak(ProfileFields $fields, GbpDesk $desk): void
    {
        abort_unless($this->canWrite(), 403);
        $locations = $this->scopedLocations();
        $rows = $fields->descriptions($locations, $desk->snapshots($locations->pluck('id')->map(fn ($id): int => (int) $id)->all()));
        $weak = $locations->filter(fn ($l): bool => in_array($rows[$l->id]['state'] ?? '', ['missing', 'short'], true));
        $queued = 0;
        $errors = [];
        foreach ($weak as $location) {
            try {
                $queued += $fields->prepareDescriptions(collect([$location]));
            } catch (ValidationException $exception) {
                $errors[] = GbpDesk::shortName((string) $location->name).': '.collect($exception->errors())->flatten()->first();
            }
        }
        $this->say(($queued > 0 ? $queued.' açıklama yazılıyor.' : 'Yazılacak eksik ya da kısa açıklama yok.').($errors !== [] ? ' '.implode(' ', array_slice($errors, 0, 3)) : ''), $errors !== [] && $queued === 0 ? 'error' : 'info');
    }

    public function startEdit(int $assetId, ProfileFields $fields, GbpDesk $desk): void
    {
        $location = $this->location($assetId);
        $row = $fields->descriptions(collect([$location]), $desk->snapshots([(int) $location->id]))[$location->id];
        $this->editing = $assetId;
        $this->editText = (string) ($row['proposed'] ?: $row['current']);
    }

    public function sendDescription(ProfileFields $fields, GbpDesk $desk): void
    {
        if ($this->editing === null) {
            return;
        }
        $location = $this->location($this->editing);
        $suggestion = $fields->descriptions(collect([$location]), $desk->snapshots([(int) $location->id]))[$location->id]['suggestion'];
        try {
            $fields->sendDescription(auth()->user(), $location, $this->editText, $suggestion);
            $this->editing = null;
            $this->say('Açıklama Google’a gönderiliyor.');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    /** Sends every ready proposal (unchanged) of the profiles in scope. */
    public function sendProposals(ProfileFields $fields, GbpDesk $desk): void
    {
        abort_unless($this->canWrite(), 403);
        $locations = $this->scopedLocations();
        $rows = $fields->descriptions($locations, $desk->snapshots($locations->pluck('id')->map(fn ($id): int => (int) $id)->all()));
        $sent = 0;
        $errors = [];
        foreach ($locations as $location) {
            $row = $rows[$location->id];
            if ($row['state'] !== 'proposal' || in_array($row['last_write']?->status, ['queued', 'running'], true)) {
                continue;
            }
            try {
                $fields->sendDescription(auth()->user(), $location, (string) $row['proposed'], $row['suggestion']);
                $sent++;
            } catch (ValidationException $exception) {
                $errors[] = GbpDesk::shortName((string) $location->name).': '.collect($exception->errors())->flatten()->first();
            }
        }
        $this->say($sent.' açıklama Google’a gönderiliyor.'.($errors !== [] ? ' Gönderilemeyen: '.implode(' ', array_slice($errors, 0, 3)) : ''), $sent === 0 && $errors !== [] ? 'error' : 'info');
    }

    public function dismiss(int $suggestionId): void
    {
        abort_unless($this->canWrite(), 403);
        Suggestion::query()->whereKey($suggestionId)->where('action_type', 'gbp_description')->update(['status' => Suggestion::DISMISSED, 'resolved_by' => auth()->id(), 'resolved_at' => now()]);
        $this->say('Öneri kaldırıldı.');
    }

    public function undo(int $actionId, ExternalWriteService $writes): void
    {
        $action = ExternalWriteAction::query()->where('action', ExternalWriteAction::ACTION_PROFILE_FIELDS)->findOrFail($actionId);
        try {
            $writes->requestUndo(auth()->user(), $action);
            $this->say('Önceki değer geri yükleniyor.');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function sendHours(ProfileFields $fields): void
    {
        abort_unless($this->canWrite(), 403);
        $holiday = collect($fields->holidays())->firstWhere('key', $this->holiday);
        if ($holiday === null) {
            $this->say('Tatil seçin.', 'error');

            return;
        }
        $rows = [];
        foreach ($holiday['dates'] as $date) {
            $choice = $this->hours[$date] ?? ['mode' => 'closed'];
            if (($choice['mode'] ?? 'closed') === 'skip') {
                continue;
            }
            $rows[] = ($choice['mode'] ?? 'closed') === 'closed' ? ['date' => $date, 'closed' => true] : ['date' => $date, 'open' => (string) ($choice['open'] ?? ''), 'close' => (string) ($choice['close'] ?? '')];
        }
        $locations = $this->scopedLocations()->whereIn('id', array_map('intval', $this->selected));
        if ($locations->isEmpty() || $rows === []) {
            $this->say('İşletme ve en az bir gün seçin.', 'error');

            return;
        }
        try {
            $result = $fields->sendHours(auth()->user(), $locations, $rows, $holiday['name']);
            $this->selected = [];
            $this->say($result['sent'].' işletmeye '.$holiday['name'].' saatleri gönderiliyor.'.($result['failed'] !== [] ? ' Gönderilemeyen: '.implode(' ', array_slice($result['failed'], 0, 3)) : ''), $result['sent'] === 0 ? 'error' : 'info');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function render(ProfileFields $fields, GbpDesk $desk): View
    {
        $locations = $this->scopedLocations();
        $snapshots = $desk->snapshots($locations->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $holidays = $fields->holidays();
        if ($this->holiday === '' || ! collect($holidays)->contains('key', $this->holiday)) {
            $this->holiday = (string) ($holidays[0]['key'] ?? '');
        }
        $holiday = collect($holidays)->firstWhere('key', $this->holiday);
        foreach ((array) ($holiday['dates'] ?? []) as $date) {
            $this->hours[$date] ??= ['mode' => 'closed', 'open' => '09:00', 'close' => '13:00'];
        }
        $hourStates = $holiday !== null ? $fields->holidayState($snapshots, $holiday['dates']) : [];
        $descriptions = $this->section === 'aciklama' ? $fields->descriptions($locations, $snapshots) : [];
        $hourWrites = $this->section === 'saatler' ? ExternalWriteAction::query()->whereIn('digital_asset_id', $locations->pluck('id'))->where('action', ExternalWriteAction::ACTION_PROFILE_FIELDS)
            ->whereRaw('cast(request_payload as text) like ?', ['%special_hours%'])->latest('id')->limit(500)->get()->unique('digital_asset_id')->keyBy('digital_asset_id') : collect();

        return view('livewire.operator.gbp.desk.profile-fields', [
            'groups' => $locations->groupBy(fn ($l): string => (string) $l->brand?->name),
            'descriptions' => $descriptions,
            'holidays' => $holidays,
            'current' => $holiday,
            'hourStates' => $hourStates,
            'hourWrites' => $hourWrites,
            'weak' => count(array_filter($descriptions, fn (array $d): bool => in_array($d['state'], ['missing', 'short'], true))),
            'proposals' => count(array_filter($descriptions, fn (array $d): bool => $d['state'] === 'proposal')),
            'missingHours' => count(array_filter($hourStates, fn (array $h): bool => $h['missing'] !== [])),
            'canWrite' => $this->canWrite(),
            'brandOptions' => $this->brandOptions(),
            'max' => GbpAssistant::DESCRIPTION_MAX,
        ]);
    }
}
