<?php

namespace App\Livewire\Operator\Gbp\Desk;

use App\Models\ExternalWriteAction;
use App\Models\Suggestion;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Gbp\Desk\GbpDesk;
use App\Services\Gbp\Desk\ProfileFields;
use App\Services\Gbp\Desk\ProfileInfo;
use App\Services\Gbp\GbpAssistant;
use App\Services\Gbp\GbpCategoryCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * İşletme profilleri › Açıklama ve saatler (`/gbp/aciklama-ve-saatler`, ADR-079).
 *  - Açıklama: every profile's description with its length and the AI proposal (one or all missing / short at once);
 *    the Admin edits and sends one, or sends every ready proposal of the brands in scope.
 *  - Özel günler: the coming official holidays; which profiles entered hours for them; the Admin sets closed or
 *    custom hours per date and sends them to the chosen profiles.
 *  - Bilgiler (ADR-080): weekly hours, phone, primary category, appointment link, yes / no attributes and a video;
 *    the nightly prepared values (hours of the brand's other branches, own site, own appointment page) wait here and
 *    on the Onarım masası; the Admin edits one profile and sends only what changed.
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

    public ?int $infoEditing = null;

    /** @var array{hours: array<string, array{open: bool, from: string, to: string}>, phone: string, appointment: string, video: string, category: array{id: string, name: string}, attributes: array<string, string>} */
    public array $info = [];

    /** @var array<string, mixed> values the editor opened with (only changed fields are sent) */
    public array $infoOriginal = [];

    public string $categoryTerm = '';

    /** @var list<array{id: string, name: string}> */
    public array $categoryResults = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_active, 403);
        $this->section = in_array($this->section, self::SECTIONS, true) ? $this->section : 'aciklama';
    }

    private const array SECTIONS = ['aciklama', 'saatler', 'bilgiler'];

    public function setSection(string $section): void
    {
        $this->section = in_array($section, self::SECTIONS, true) ? $section : 'aciklama';
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

    public function startInfo(int $assetId, GbpDesk $desk): void
    {
        abort_unless($this->canWrite(), 403);
        $location = $this->location($assetId);
        $snapshot = $desk->snapshots([(int) $location->id])[$location->id] ?? [];
        $hours = [];
        foreach (array_keys(self::DAYS) as $day) {
            $hours[$day] = ['open' => false, 'from' => '09:00', 'to' => '18:00'];
        }
        foreach (ProfileInfo::rows((array) ($snapshot['regular_hours'] ?? [])) as $row) {
            if (isset($hours[$row['day']]) && ! $hours[$row['day']]['open']) {
                $hours[$row['day']] = ['open' => true, 'from' => $row['open'], 'to' => $row['close'] === '00:00' ? '24:00' : $row['close']];
            }
        }
        $attributes = [];
        foreach (ProfileInfo::attributes((int) $location->id) as $attribute) {
            $attributes[str_replace('attributes/', '', $attribute['name'])] = $attribute['value'] === null ? '' : ($attribute['value'] ? 'yes' : 'no');
        }
        $this->info = ['hours' => $hours, 'phone' => (string) ($snapshot['phone'] ?? ''), 'appointment' => '', 'video' => '',
            'category' => ['id' => '', 'name' => (string) ($snapshot['primary_category'] ?? '')], 'attributes' => $attributes];
        $this->infoOriginal = $this->info;
        $this->infoEditing = $assetId;
        $this->categoryTerm = '';
        $this->categoryResults = [];
    }

    public function searchCategory(GbpCategoryCatalog $catalog): void
    {
        if ($this->infoEditing === null || mb_strlen(trim($this->categoryTerm)) < 2) {
            return;
        }
        try {
            [$integration] = $catalog->location($this->infoEditing);
            $this->categoryResults = array_map(fn (array $c): array => ['id' => $c['id'], 'name' => $c['name']], $catalog->search($integration, $this->categoryTerm));
            if ($this->categoryResults === []) {
                $this->say('Google bu adla kategori bulamadı.', 'error');
            }
        } catch (Throwable $error) {
            $this->say($error->getMessage(), 'error');
        }
    }

    public function pickCategory(string $id, string $name): void
    {
        $this->info['category'] = ['id' => $id, 'name' => $name];
        $this->categoryResults = [];
    }

    /** Sends the fields that differ from what the editor opened with (one write) and the video (its own write). */
    public function sendInfo(ExternalWriteService $writes): void
    {
        abort_unless($this->canWrite(), 403);
        if ($this->infoEditing === null) {
            return;
        }
        $location = $this->location($this->infoEditing);
        $fields = [];
        if ($this->info['hours'] !== $this->infoOriginal['hours']) {
            $fields['regular_hours'] = [];
            foreach ($this->info['hours'] as $day => $row) {
                if ((bool) $row['open']) {
                    $fields['regular_hours'][] = ['day' => $day, 'open' => (string) $row['from'], 'close' => (string) $row['to']];
                }
            }
        }
        if (trim($this->info['phone']) !== trim((string) $this->infoOriginal['phone']) && trim($this->info['phone']) !== '') {
            $fields['phone'] = $this->info['phone'];
        }
        if ($this->info['category']['id'] !== '') {
            $fields['primary_category'] = $this->info['category'];
        }
        if (trim($this->info['appointment']) !== '') {
            $fields['appointment_url'] = $this->info['appointment'];
        }
        foreach ($this->info['attributes'] as $key => $value) {
            if ($value !== '' && $value !== ($this->infoOriginal['attributes'][$key] ?? '')) {
                $fields['attributes'][] = ['name' => 'attributes/'.$key, 'value' => $value === 'yes'];
            }
        }
        $video = trim($this->info['video']);
        if ($fields === [] && $video === '') {
            $this->say('Değişen bir bilgi yok.', 'error');

            return;
        }
        try {
            $sent = [];
            if ($fields !== []) {
                $labels = array_map(fn (string $f): string => ProfileInfo::FIELD_LABELS[$f] ?? $f, array_keys($fields));
                $writes->requestProfileFields(auth()->user(), $location, $fields, implode(', ', $labels));
                $sent = $labels;
            }
            if ($video !== '') {
                $writes->requestVideo(auth()->user(), $location, $video);
                $sent[] = 'Video';
            }
            $this->infoEditing = null;
            $this->say(implode(', ', $sent).' Google’a gönderiliyor.');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function sendPrepared(int $suggestionId, ProfileInfo $info): void
    {
        abort_unless($this->canWrite(), 403);
        $suggestion = $info->open($this->scopedLocations()->pluck('id')->map(fn ($id): int => (int) $id)->all())->firstWhere('id', $suggestionId);
        if ($suggestion === null) {
            return;
        }
        try {
            $info->send(auth()->user(), $suggestion);
            $this->say('Hazır bilgi Google’a gönderiliyor.');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function dismissPrepared(int $suggestionId, ProfileInfo $info): void
    {
        abort_unless($this->canWrite(), 403);
        Suggestion::query()->whereKey($suggestionId)->where('action_type', ProfileInfo::TYPE)->update(['status' => Suggestion::DISMISSED, 'resolved_by' => auth()->id(), 'resolved_at' => now()]);
        $this->say('Öneri kaldırıldı.');
    }

    public const array DAYS = ['MONDAY' => 'Pazartesi', 'TUESDAY' => 'Salı', 'WEDNESDAY' => 'Çarşamba', 'THURSDAY' => 'Perşembe', 'FRIDAY' => 'Cuma', 'SATURDAY' => 'Cumartesi', 'SUNDAY' => 'Pazar'];

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

        $infoWrites = $this->section === 'bilgiler' ? ExternalWriteAction::query()->whereIn('digital_asset_id', $locations->pluck('id'))
            ->whereIn('action', [ExternalWriteAction::ACTION_PROFILE_FIELDS, ExternalWriteAction::ACTION_MEDIA_UPLOAD])
            ->where(fn ($q) => $q->whereRaw('cast(request_payload as text) like ?', ['%regular_hours%'])->orWhereRaw('cast(request_payload as text) like ?', ['%phone%'])
                ->orWhereRaw('cast(request_payload as text) like ?', ['%primary_category%'])->orWhereRaw('cast(request_payload as text) like ?', ['%attributes%'])
                ->orWhereRaw('cast(request_payload as text) like ?', ['%appointment_url%'])->orWhereRaw('cast(request_payload as text) like ?', ['%VIDEO%']))
            ->latest('id')->limit(500)->get()->unique('digital_asset_id')->keyBy('digital_asset_id') : collect();
        $prepared = $this->section === 'bilgiler' ? app(ProfileInfo::class)->open($locations->pluck('id')->map(fn ($id): int => (int) $id)->all())->groupBy('target_id') : collect();

        return view('livewire.operator.gbp.desk.profile-fields', [
            'snapshots' => $snapshots,
            'infoWrites' => $infoWrites,
            'prepared' => $prepared,
            'attributeList' => $this->infoEditing !== null ? ProfileInfo::attributes($this->infoEditing) : [],
            'days' => self::DAYS,
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
