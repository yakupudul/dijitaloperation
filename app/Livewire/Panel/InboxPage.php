<?php

namespace App\Livewire\Panel;

use App\Panel\Models\PanelConversation;
use App\Panel\Models\PanelEvent;
use App\Panel\Models\PanelImport;
use App\Panel\Models\PanelMembership;
use App\Panel\Models\PanelMessage;
use App\Panel\Models\PanelPerson;
use App\Panel\Models\PanelReminder;
use App\Panel\Models\PanelRequest;
use App\Panel\Models\PanelTicket;
use App\Panel\Services\PanelBackups;
use App\Panel\Services\PanelLeads;
use App\Panel\Services\PanelNotifier;
use App\Panel\Services\PanelSupport;
use App\Panel\Support\PanelPage;
use App\Panel\Support\PanelStages;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Gelen kutusu: every channel a request can come from, in one place. The list, what was written, and the person's
 * card with the next step (note, reminder, appointment, stage) side by side. Read only: nothing is sent from here.
 */
#[Layout('panel.layouts.app')]
#[Title('Gelen kutusu')]
final class InboxPage extends Component
{
    use PanelPage;

    /** key => [label, permission, kind]; "requests" tabs list form and call records, "chats" tabs list conversations. */
    public const array CHANNELS = [
        'whatsapp' => ['WhatsApp', 'inbox.whatsapp', 'chats'],
        'instagram' => ['Instagram', 'inbox.instagram', 'chats'],
        'facebook' => ['Facebook', 'inbox.instagram', 'chats'],
        'web_form' => ['Web formu', 'inbox.web_form', 'requests'],
        'meta_form' => ['Meta formu', 'inbox.meta_form', 'requests'],
        'call' => ['Çağrı', 'inbox.call', 'requests'],
    ];

    #[Url(as: 'kanal')]
    public string $channel = '';

    #[Url(as: 'kayit')]
    public ?int $open = null;

    #[Url(as: 'goster')]
    public string $show = 'all';

    public string $search = '';

    public string $note = '';

    public string $appointment = '';

    public int $limit = 200;

    public bool $backupOpen = false;

    protected function permission(): string
    {
        return 'menu.inbox';
    }

    /** @return array<string, string> the tabs this user may open */
    private function tabs(): array
    {
        return collect(self::CHANNELS)->filter(fn (array $channel): bool => $this->may($channel[1]))->map(fn (array $channel): string => $channel[0])->all();
    }

    private function kind(): string
    {
        return self::CHANNELS[$this->channel][2] ?? 'requests';
    }

    private function actor(): int
    {
        return (int) $this->panelUser()->id;
    }

    public function updatedChannel(): void
    {
        $this->open = null;
        $this->search = '';
        $this->limit = 200;
    }

    public function updatedOpen(): void
    {
        $this->note = '';
        $this->appointment = '';
        $this->limit = 200;
    }

    private function requests(): Builder
    {
        $query = PanelRequest::query()->where('source', $this->channel);
        if (! $this->may('leads.all')) {
            $query->whereHas('person', fn (Builder $person) => $person->where('assigned_user_id', $this->actor()));
        }

        return $query;
    }

    private function chats(): Builder
    {
        $query = PanelConversation::query()->where('channel', $this->channel);
        if (! $this->may('leads.all')) {
            $query->whereHas('person', fn (Builder $person) => $person->where('assigned_user_id', $this->actor()));
        }

        return $query;
    }

    private function record(): PanelRequest|PanelConversation|null
    {
        if ($this->open === null || ! isset($this->tabs()[$this->channel])) {
            return null;
        }

        return $this->kind() === 'chats' ? $this->chats()->with('person.assignee:id,name')->find($this->open) : $this->requests()->with('person.assignee:id,name')->find($this->open);
    }

    private function person(): PanelPerson
    {
        $person = $this->record()?->person;
        abort_if($person === null, 404);

        return $person;
    }

    private function handled(bool $done = true): void
    {
        $record = $this->record();
        if ($record instanceof PanelRequest) {
            $record->forceFill(['handled_at' => $done ? now() : null, 'handled_by' => $done ? $this->actor() : null])->save();
        } elseif ($record instanceof PanelConversation) {
            $record->forceFill(['handled_at' => $done ? now() : null])->save();
        }
    }

    public function handle(bool $done = true): void
    {
        abort_unless($this->may('leads.stage') || $this->may('notes.write'), 403);
        abort_if($this->record() === null, 404);
        $this->handled($done);
        $this->notify($done ? 'İlgilenildi olarak işaretlendi.' : 'Yeniden bekleyenlere alındı.');
    }

    /** An old chat becomes a lead in one click; the chat stays on the person's record. */
    public function convert(PanelLeads $leads): void
    {
        abort_unless($this->may('leads.create'), 403);
        $chat = $this->record();
        abort_unless($chat instanceof PanelConversation, 404);
        if ($chat->person_id === null) {
            $person = $leads->record((int) $chat->brand_id, ['name' => $chat->contact_name, 'phone' => $chat->contact_phone, 'meta' => ['from' => 'inbox']], $this->channel, $this->actor());
            $chat->forceFill(['person_id' => $person->id, 'handled_at' => now()])->save();
        }
        $this->notify('Aday olarak eklendi.');
    }

    public function setStage(string $stage, PanelLeads $leads): void
    {
        abort_unless($this->may('leads.stage'), 403);
        abort_unless(array_key_exists($stage, PanelStages::labels()) && $stage !== PanelStages::LOST, 422);
        $leads->setStage($this->person(), $stage, null, $this->actor());
        $this->handled();
        $this->notify('Aşama: '.PanelStages::label($stage));
    }

    public function addNote(PanelLeads $leads): void
    {
        abort_unless($this->may('notes.write'), 403);
        $this->validate(['note' => ['required', 'string', 'max:5000']], [], ['note' => 'not']);
        $leads->note($this->person(), $this->note, $this->actor());
        $this->handled();
        $this->note = '';
        $this->notify('Not kaydedildi.');
    }

    public function remind(int $days, PanelLeads $leads): void
    {
        abort_unless($this->may('reminders.write'), 403);
        abort_unless(in_array($days, [1, 3, 7], true), 422);
        $person = $this->person();
        $due = now('Europe/Istanbul')->addDays($days)->setTime(10, 0);
        PanelReminder::query()->create(['person_id' => $person->id, 'panel_user_id' => $this->actor(), 'created_by' => $this->actor(), 'title' => $person->name.' ile ilgilen', 'due_at' => $due->copy()->utc()]);
        $leads->event($person, PanelEvent::REMINDER, $person->name.' ile ilgilen', ['due' => $due->format('Y-m-d\TH:i')], $this->actor());
        $this->notify('Hatırlatma kuruldu: '.$due->translatedFormat('j F, H:i'));
    }

    public function setAppointment(PanelLeads $leads): void
    {
        abort_unless($this->may('leads.edit') && $this->may('leads.stage'), 403);
        $this->validate(['appointment' => ['required', 'date']], [], ['appointment' => 'randevu']);
        $person = $this->person();
        $person->forceFill(['appointment_at' => CarbonImmutable::parse($this->appointment, 'Europe/Istanbul')->utc()])->save();
        $leads->event($person, PanelEvent::EDITED, null, ['fields' => ['appointment_at']], $this->actor());
        if (in_array($person->stage, [PanelStages::NEW, PanelStages::REACHED], true)) {
            $leads->setStage($person, PanelStages::APPOINTMENT, null, $this->actor());
        }
        $this->handled();
        $this->appointment = '';
        $this->notify('Randevu kaydedildi.');
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
        if ($id !== null && $id !== $this->actor()) {
            app(PanelNotifier::class)->user((int) $person->brand_id, $id, 'assigned', 'Size atandı: '.$person->name, route('panel.person', $person->id));
        }
        $this->notify($id ? 'İlgilenen kişi değişti.' : 'İlgilenen kişi kaldırıldı.');
    }

    /** A channel that is not connected yet: one click asks the agency to connect it. */
    public function requestConnection(PanelSupport $support): void
    {
        abort_unless($this->may('support.write') && isset($this->tabs()[$this->channel]), 403);
        $subject = self::CHANNELS[$this->channel][0].' bağlantısı';
        if (PanelTicket::query()->where('subject', $subject)->where('status', '!=', PanelTicket::SOLVED)->exists()) {
            $this->notify('Bu bağlantıyı zaten istediniz; Destek ekranında bekliyor.');

            return;
        }
        $support->open((int) $this->panel()->brandId(), $this->actor(), $subject, 'connection', self::CHANNELS[$this->channel][0].' kanalının panele bağlanmasını istiyoruz.');
        $this->notify('İsteğiniz Moximu\'ya ulaştı. Bağlanınca zil haber verir.');
    }

    /** @return array{id: int, chunk: int} */
    public function beginBackup(string $name, int $size, PanelBackups $backups): array
    {
        abort_unless($this->panel()->isManager(), 403);
        $import = $backups->begin((int) $this->panel()->brandId(), $this->actor(), $name, $size);

        return ['id' => $import->id, 'chunk' => PanelBackups::CHUNK_BYTES];
    }

    public function extractBackup(string $key, PanelBackups $backups): bool
    {
        abort_unless($this->panel()->isManager(), 403);
        $import = PanelImport::query()->where('kind', PanelImport::BACKUP)->where('status', 'uploaded')->latest('id')->first();
        try {
            if ($import === null) {
                throw ValidationException::withMessages(['key' => 'Önce yedek dosyasını seçin.']);
            }
            $backups->queue($import, $key);
        } catch (ValidationException $exception) {
            $this->notify(collect($exception->errors())->flatten()->first(), 'error');

            return false;
        }
        $this->notify('Yedek sıraya alındı. Sohbetler birkaç dakika içinde burada görünür, bitince zil haber verir.');

        return true;
    }

    public function undoBackup(int $id, PanelBackups $backups): void
    {
        abort_unless($this->panel()->isManager(), 403);
        $import = PanelImport::query()->where('kind', PanelImport::BACKUP)->find($id);
        abort_if($import === null, 404);
        $backups->undo($import);
        $this->open = null;
        $this->notify('Bu yedeğin eklediği mesajlar geri alındı. Adaylar yerinde duruyor.');
    }

    public function render(): View
    {
        $tabs = $this->tabs();
        if (! isset($tabs[$this->channel])) {
            $this->channel = (string) (array_key_first($tabs) ?? '');
            $this->open = null;
        }
        if (! in_array($this->show, ['all', 'new'], true)) {
            $this->show = 'all';
        }
        $kind = $this->kind();
        $term = trim($this->search);
        $showPhone = $this->may('leads.phone');
        $rows = collect();
        $total = 0;
        if ($this->channel !== '') {
            if ($kind === 'chats') {
                $total = $this->chats()->count();
                $rows = $this->chats()->with('person:id,name,stage')
                    ->when($this->show === 'new', fn (Builder $q) => $q->whereNull('handled_at'))
                    ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('contact_name', 'like', '%'.$term.'%')
                        ->when($showPhone, fn (Builder $p) => $p->orWhere('contact_phone', 'like', '%'.preg_replace('/\s+/', '', $term).'%'))
                        ->orWhereHas('person', fn (Builder $p) => $p->where('name', 'like', '%'.$term.'%'))))
                    ->orderByDesc('last_message_at')->limit(150)->get();
            } else {
                $total = $this->requests()->count();
                $rows = $this->requests()->with('person:id,name,stage,phone')
                    ->when($this->show === 'new', fn (Builder $q) => $q->whereNull('handled_at'))
                    ->when($term !== '', fn (Builder $q) => $q->whereHas('person', fn (Builder $p) => $p->where('name', 'like', '%'.$term.'%')))
                    ->orderByDesc('received_at')->limit(150)->get();
            }
        }
        $record = $this->record();
        $person = $record?->person;
        $messages = collect();
        $older = false;
        if ($record instanceof PanelConversation) {
            $messages = PanelMessage::query()->where('conversation_id', $record->id)->orderByDesc('sent_at')->orderByDesc('id')->limit($this->limit + 1)->get();
            $older = $messages->count() > $this->limit;
            $messages = $messages->take($this->limit)->reverse()->values();
        }
        $manager = $this->panel()->isManager();

        return view('livewire.panel.inbox', [
            'tabs' => $tabs,
            'kind' => $kind,
            'rows' => $rows,
            'total' => $total,
            'waiting' => $this->channel === '' ? 0 : ($kind === 'chats' ? $this->chats()->whereNull('handled_at')->count() : $this->requests()->whereNull('handled_at')->count()),
            'record' => $record,
            'person' => $person,
            'messages' => $messages,
            'older' => $older,
            'history' => $record instanceof PanelRequest && $person ? PanelRequest::query()->where('person_id', $person->id)->whereKeyNot($record->id)->orderByDesc('received_at')->limit(10)->get() : collect(),
            'lastNotes' => $person ? PanelEvent::query()->with('user:id,name')->where('person_id', $person->id)->whereIn('type', [PanelEvent::NOTE, PanelEvent::CALL])->orderByDesc('occurred_at')->limit(3)->get() : collect(),
            'team' => $person && $this->may('leads.assign') ? PanelMembership::query()->with('user:id,name')->where('brand_id', $this->panel()->brandId())->where('is_active', true)->get()->pluck('user.name', 'panel_user_id') : collect(),
            'showPhone' => $showPhone,
            'manager' => $manager,
            'imports' => $manager && $this->channel === 'whatsapp' ? PanelImport::query()->where('kind', PanelImport::BACKUP)->latest('id')->limit(5)->get() : collect(),
            'can' => [
                'stage' => $this->may('leads.stage'), 'note' => $this->may('notes.write'), 'reminder' => $this->may('reminders.write'), 'assign' => $this->may('leads.assign'),
                'appointment' => $this->may('leads.edit') && $this->may('leads.stage'), 'create' => $this->may('leads.create'), 'support' => $this->may('support.write'),
                'handle' => $this->may('leads.stage') || $this->may('notes.write'),
            ],
        ]);
    }
}
