<?php

namespace App\Livewire\Panel;

use App\Panel\Models\PanelEvent;
use App\Panel\Models\PanelFile;
use App\Panel\Models\PanelMembership;
use App\Panel\Models\PanelPerson;
use App\Panel\Models\PanelPreset;
use App\Panel\Models\PanelReminder;
use App\Panel\Services\PanelLeads;
use App\Panel\Services\PanelPresets;
use App\Panel\Support\PanelPage;
use App\Panel\Support\PanelStages;
use App\Panel\Support\PhoneKey;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/** One person: who they are, where they stand, and everything written about them (history, notes, reminders, files). */
#[Layout('panel.layouts.app')]
final class PersonPage extends Component
{
    use PanelPage;
    use WithFileUploads;

    public int $personId;

    #[Url(as: 'sekme')]
    public string $tab = 'history';

    public string $note = '';

    public string $noteType = 'note';

    public string $templateName = '';

    public bool $editing = false;

    /** @var array<string, mixed> */
    public array $form = [];

    public string $reason = '';

    public bool $losing = false;

    /** @var array<string, mixed> */
    public array $reminder = ['title' => '', 'due' => '', 'user' => ''];

    public $upload = null;

    protected function permission(): string
    {
        return 'menu.leads';
    }

    public function mount(int $person): void
    {
        $this->personId = $person;
        $this->person();
        $this->reminder['due'] = now('Europe/Istanbul')->addDay()->setTime(10, 0)->format('Y-m-d\TH:i');
        $this->reminder['user'] = (string) $this->panelUser()->id;
    }

    private function person(): PanelPerson
    {
        return app(PanelLeads::class)->visible()->whereKey($this->personId)->firstOrFail();
    }

    private function actor(): int
    {
        return (int) $this->panelUser()->id;
    }

    public function setStage(string $stage, PanelLeads $leads): void
    {
        abort_unless($this->may('leads.stage'), 403);
        abort_unless(array_key_exists($stage, PanelStages::labels()), 422);
        if ($stage === PanelStages::LOST) {
            $this->losing = true;
            $this->reason = '';

            return;
        }
        $this->losing = false;
        $leads->setStage($this->person(), $stage, null, $this->actor());
        $this->notify('Aşama: '.PanelStages::label($stage));
    }

    public function lose(PanelLeads $leads): void
    {
        abort_unless($this->may('leads.stage'), 403);
        $this->validate(['reason' => ['required', Rule::in(array_keys(PanelStages::lostReasons()))]], ['reason.required' => 'Bir neden seçin.'], ['reason' => 'neden']);
        $leads->setStage($this->person(), PanelStages::LOST, $this->reason, $this->actor());
        $this->losing = false;
        $this->notify('Aşama: Olumsuz');
    }

    public function addNote(PanelLeads $leads): void
    {
        abort_unless($this->may('notes.write'), 403);
        $this->validate(['note' => ['required', 'string', 'max:5000'], 'noteType' => ['required', Rule::in(['note', 'call'])]], [], ['note' => 'not']);
        $leads->note($this->person(), $this->note, $this->actor(), $this->noteType === 'call' ? PanelEvent::CALL : PanelEvent::NOTE);
        $this->note = '';
        $this->notify($this->noteType === 'call' ? 'Arama kaydedildi.' : 'Not kaydedildi.');
    }

    /** Writes a saved template into the note box; the user finishes the sentence and saves. */
    public function useTemplate(string $id): void
    {
        abort_unless($this->may('notes.write'), 403);
        $template = ctype_digit($id) ? PanelPreset::query()->where('kind', PanelPreset::NOTE)->find((int) $id) : null;
        if ($template !== null) {
            $this->note = trim(trim($this->note)."\n".(string) ($template->body ?: $template->label));
        }
    }

    /** Keeps the note in the box as a template of the brand, under the given name or its first words. */
    public function saveTemplate(PanelPresets $presets): void
    {
        abort_unless($this->may('notes.write'), 403);
        $this->validate(['note' => ['required', 'string', 'max:5000'], 'templateName' => ['nullable', 'string', 'max:80']], ['note.required' => 'Önce notu yazın, sonra şablon olarak kaydedin.'], ['note' => 'not', 'templateName' => 'şablon adı']);
        $name = trim($this->templateName) !== '' ? trim($this->templateName) : mb_substr((string) strtok(trim($this->note), "\n"), 0, 60);
        $presets->add(PanelPreset::NOTE, $name, $this->note, $this->actor());
        $this->templateName = '';
        $this->notify('“'.$name.'” şablonlara eklendi.');
    }

    public function addInterest(string $label, PanelPresets $presets): void
    {
        abort_unless($this->may('leads.edit'), 403);
        if (trim($label) === '') {
            return;
        }
        $this->form['interest'] = $presets->add(PanelPreset::INTEREST, $label, null, $this->actor())->label;
        $this->notify('“'.$this->form['interest'].'” listeye eklendi.');
    }

    /** "Yarın", "3 gün sonra": sets the reminder's time without the date picker. */
    public function remindIn(int $days): void
    {
        abort_unless(in_array($days, [1, 3, 7, 30], true), 422);
        $this->reminder['due'] = now('Europe/Istanbul')->addDays($days)->setTime(10, 0)->format('Y-m-d\TH:i');
    }

    public function edit(): void
    {
        abort_unless($this->may('leads.edit'), 403);
        $person = $this->person();
        $this->form = [
            'name' => $person->name, 'phone' => (string) $person->phone, 'email' => (string) $person->email, 'interest' => (string) $person->interest,
            'city' => (string) $person->city, 'district' => (string) $person->district, 'gender' => (string) $person->gender,
            'birth_year' => (string) $person->birth_year, 'appointment' => (string) $person->appointment_at?->timezone('Europe/Istanbul')->format('Y-m-d\TH:i'),
        ];
        $this->editing = true;
    }

    public function save(PanelLeads $leads): void
    {
        abort_unless($this->may('leads.edit'), 403);
        $person = $this->person();
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:190'],
            'form.phone' => ['nullable', 'string', 'max:40'],
            'form.email' => ['nullable', 'email', 'max:190'],
            'form.interest' => ['nullable', 'string', 'max:190'],
            'form.city' => ['nullable', 'string', 'max:100'],
            'form.district' => ['nullable', 'string', 'max:100'],
            'form.gender' => ['nullable', Rule::in(['female', 'male'])],
            'form.birth_year' => ['nullable', 'integer', 'min:1900', 'max:'.now()->year],
            'form.appointment' => ['nullable', 'date'],
        ], [], ['form.name' => 'ad', 'form.phone' => 'telefon', 'form.email' => 'e-posta', 'form.birth_year' => 'doğum yılı', 'form.appointment' => 'randevu'])['form'];
        // Without the right to see numbers the masked phone is not on the form, so it is never overwritten.
        if (! $this->may('leads.phone')) {
            unset($data['phone'], $data['email']);
        } else {
            $key = PhoneKey::of($data['phone'] ?? null);
            if ($key !== null && PanelPerson::query()->where('phone_key', $key)->whereKeyNot($person->id)->exists()) {
                $this->addError('form.phone', 'Bu numara başka bir kayıtta var.');

                return;
            }
        }
        $appointment = ($data['appointment'] ?? '') !== '' ? CarbonImmutable::parse($data['appointment'], 'Europe/Istanbul')->utc() : null;
        unset($data['appointment']);
        $person->fill(array_map(fn ($value) => $value === '' ? null : $value, $data));
        $person->appointment_at = $appointment;
        $changed = array_keys($person->getDirty());
        $person->save();
        if ($changed !== []) {
            $leads->event($person, PanelEvent::EDITED, null, ['fields' => $changed], $this->actor());
        }
        $this->editing = false;
        $this->notify('Bilgiler kaydedildi.');
    }

    public function assign(string $userId, PanelLeads $leads): void
    {
        abort_unless($this->may('leads.assign'), 403);
        $person = $this->person();
        $id = ctype_digit($userId) ? (int) $userId : null;
        if ($id !== null) {
            abort_unless(PanelMembership::query()->where('brand_id', $this->panel()->brandId())->where('panel_user_id', $id)->where('is_active', true)->exists(), 422);
        }
        $person->forceFill(['assigned_user_id' => $id])->save();
        $leads->event($person, PanelEvent::ASSIGNED, null, ['to' => $id], $this->actor());
        $this->notify($id ? 'İlgilenen kişi değişti.' : 'İlgilenen kişi kaldırıldı.');
    }

    public function addReminder(PanelLeads $leads): void
    {
        abort_unless($this->may('reminders.write'), 403);
        $data = $this->validate([
            'reminder.title' => ['required', 'string', 'max:190'],
            'reminder.due' => ['required', 'date'],
            'reminder.user' => ['nullable', 'integer'],
        ], [], ['reminder.title' => 'hatırlatma', 'reminder.due' => 'tarih'])['reminder'];
        $person = $this->person();
        $userId = (int) ($data['user'] ?: $this->actor());
        abort_unless(PanelMembership::query()->where('brand_id', $this->panel()->brandId())->where('panel_user_id', $userId)->exists(), 422);
        PanelReminder::query()->create([
            'person_id' => $person->id, 'panel_user_id' => $userId, 'created_by' => $this->actor(), 'title' => trim($data['title']),
            'due_at' => CarbonImmutable::parse($data['due'], 'Europe/Istanbul')->utc(),
        ]);
        $leads->event($person, PanelEvent::REMINDER, trim($data['title']), ['due' => $data['due']], $this->actor());
        $this->reminder['title'] = '';
        $this->notify('Hatırlatma kuruldu.');
    }

    public function closeReminder(int $id): void
    {
        abort_unless($this->may('reminders.write'), 403);
        PanelReminder::query()->where('person_id', $this->person()->id)->whereKey($id)->firstOrFail()->forceFill(['done_at' => now()])->save();
        $this->notify('Hatırlatma kapandı.');
    }

    public function updatedUpload(PanelLeads $leads): void
    {
        abort_unless($this->may('files.upload'), 403);
        $this->validate(['upload' => ['required', 'file', 'max:'.(int) config('panel.max_file_kb'), 'mimes:pdf,jpg,jpeg,png,webp,heic,doc,docx,xls,xlsx,txt,csv']], ['upload.mimes' => 'Bu dosya türü yüklenemiyor.', 'upload.max' => 'Dosya 20 MB\'tan büyük olamaz.'], ['upload' => 'dosya']);
        $person = $this->person();
        $name = mb_substr((string) $this->upload->getClientOriginalName(), 0, 190);
        $path = $this->upload->store('panel/'.$person->brand_id.'/'.$person->id, (string) config('panel.files_disk'));
        PanelFile::query()->create(['person_id' => $person->id, 'panel_user_id' => $this->actor(), 'name' => $name, 'path' => $path, 'mime' => $this->upload->getMimeType(), 'size' => (int) $this->upload->getSize()]);
        $leads->event($person, PanelEvent::FILE, $name, [], $this->actor());
        $this->upload = null;
        $this->notify('Dosya yüklendi.');
    }

    public function render(PanelPresets $presets): View
    {
        $person = $this->person()->load('assignee:id,name');
        $tabs = array_filter([
            'history' => 'Geçmiş', 'notes' => 'Notlar', 'reminders' => 'Hatırlatmalar',
            'files' => $this->may('files.view') ? 'Dosyalar' : null,
        ]);
        if (! isset($tabs[$this->tab])) {
            $this->tab = 'history';
        }
        $events = PanelEvent::query()->with('user:id,name')->where('person_id', $person->id)
            ->when($this->tab === 'notes', fn ($q) => $q->whereIn('type', [PanelEvent::NOTE, PanelEvent::CALL]))
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(200)->get();

        return view('livewire.panel.person', [
            'person' => $person,
            'tabs' => $tabs,
            'events' => $events,
            'reminders' => $this->tab === 'reminders' ? PanelReminder::query()->with('user:id,name')->where('person_id', $person->id)->orderByRaw('done_at is not null')->orderBy('due_at')->get() : collect(),
            'files' => $this->tab === 'files' && $this->may('files.view') ? PanelFile::query()->with('user:id,name')->where('person_id', $person->id)->latest()->get() : collect(),
            'requestCount' => $person->requests()->count(),
            'team' => PanelMembership::query()->with('user:id,name')->where('brand_id', $this->panel()->brandId())->where('is_active', true)->get()->pluck('user.name', 'panel_user_id'),
            'showPhone' => $this->may('leads.phone'),
            'interests' => $this->editing ? $presets->interests() : collect(),
            'templates' => $this->may('notes.write') ? $presets->of(PanelPreset::NOTE) : collect(),
            'messages' => $this->may('leads.phone') ? $presets->of(PanelPreset::MESSAGE) : collect(),
            'can' => [
                'stage' => $this->may('leads.stage'), 'edit' => $this->may('leads.edit'), 'assign' => $this->may('leads.assign'),
                'note' => $this->may('notes.write'), 'reminder' => $this->may('reminders.write'), 'upload' => $this->may('files.upload'),
            ],
        ])->title($person->name);
    }
}
