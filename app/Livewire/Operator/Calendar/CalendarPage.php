<?php

namespace App\Livewire\Operator\Calendar;

use App\Models\Brand;
use App\Models\Reminder;
use App\Models\SalesLead;
use App\Services\Assistant\ReminderService;
use App\Services\Calendar\CalendarFeed;
use App\Services\Calendar\CalendarIcs;
use App\Services\Sales\SalesLeads;
use App\Services\Sales\SalesReplyWriter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Takvim (yakup 2026-11-05): the center of the day. A cell shows only the operator's own tasks and meetings and the
 * prospects to write to; the brands' work is one line ("12 iş · 6 marka"), opened per brand in the day window. Own
 * items are ticked by hand; the system's work is green when applied and red when it failed. Desktop: month; phone: the
 * next 7 days.
 */
#[Layout('operator.layouts.app')]
#[Title('Takvim')]
final class CalendarPage extends Component
{
    #[Url(as: 'ay', except: '')]
    public string $month = '';

    #[Url(as: 'gun', except: '')]
    public string $day = '';

    #[Url(as: 'dokum', except: false)]
    public bool $report = false;

    public bool $adding = false;

    /** @var array{kind: string, title: string, date: string, time: string, end: string, place: string, brand_id: string, lead_id: string, notes: string, repeat: string} */
    public array $form = ['kind' => 'task', 'title' => '', 'date' => '', 'time' => '', 'end' => '', 'place' => '', 'brand_id' => '', 'lead_id' => '', 'notes' => '', 'repeat' => 'none'];

    public ?int $noteFor = null;

    /** "Google Takvim'e ekle": the private feed address, shown once asked. */
    public ?string $feedUrl = null;

    public string $note = '';

    public function mount(): void
    {
        $lead = (int) request()->query('aday', 0);
        if ($lead > 0 && SalesLead::query()->whereKey($lead)->exists()) {
            // "Toplantı ekle" from a prospect's card opens the form with that prospect.
            $this->startAdding($this->day !== '' ? $this->day : null, 'meeting');
            $this->form['lead_id'] = (string) $lead;
        }
    }

    public function updatedMonth(): void
    {
        $this->day = '';
    }

    public function openDay(string $date): void
    {
        $this->day = self::validDate($date) ?? '';
        $this->noteFor = null;
    }

    public function closeDay(): void
    {
        $this->day = '';
        $this->noteFor = null;
    }

    public function startAdding(?string $date = null, string $kind = 'task'): void
    {
        $date = self::validDate((string) $date) ?? ($this->day !== '' ? $this->day : CarbonImmutable::now(CalendarFeed::TZ)->toDateString());
        $this->form = ['kind' => $kind === 'meeting' ? 'meeting' : 'task', 'title' => '', 'date' => $date, 'time' => $kind === 'meeting' ? '10:00' : '',
            'end' => '', 'place' => '', 'brand_id' => '', 'lead_id' => '', 'notes' => '', 'repeat' => 'none'];
        $this->resetErrorBag();
        $this->adding = true;
    }

    public function save(): void
    {
        $f = $this->form;
        $title = trim((string) $f['title']);
        $lead = (int) $f['lead_id'] > 0 ? SalesLead::query()->find((int) $f['lead_id']) : null;
        if ($title === '' && $lead !== null) {
            $title = ($f['kind'] === 'meeting' ? 'Görüşme: ' : '').$lead->title();
        }
        $date = self::validDate((string) $f['date']);
        $time = self::validTime((string) $f['time']);
        $end = self::validTime((string) $f['end']);
        if ($title === '') {
            throw ValidationException::withMessages(['form.title' => 'Ne yapılacağını yaz.']);
        }
        if ($date === null) {
            throw ValidationException::withMessages(['form.date' => 'Tarih seç.']);
        }
        if ($f['kind'] === 'meeting' && $time === null) {
            throw ValidationException::withMessages(['form.time' => 'Toplantının saatini yaz.']);
        }
        $start = CarbonImmutable::parse($date.' '.($time ?? '09:00'), CalendarFeed::TZ);
        $ends = $end !== null ? CarbonImmutable::parse($date.' '.$end, CalendarFeed::TZ) : ($f['kind'] === 'meeting' ? $start->addMinutes(30) : null);
        if ($ends !== null && $ends->lte($start)) {
            throw ValidationException::withMessages(['form.end' => 'Bitiş, başlangıçtan sonra olmalı.']);
        }
        $brandId = (int) $f['brand_id'] > 0 && Brand::query()->whereKey((int) $f['brand_id'])->exists() ? (int) $f['brand_id'] : ($lead?->brand_id ?: null);
        Reminder::query()->create([
            'user_id' => auth()->id(), 'kind' => $f['kind'] === 'meeting' ? 'meeting' : 'task', 'title' => mb_substr($title, 0, 255),
            'notes' => trim((string) $f['notes']) ?: null, 'place' => trim((string) $f['place']) !== '' ? mb_substr(trim((string) $f['place']), 0, 500) : null,
            'remind_at' => $start->utc(), 'ends_at' => $ends?->utc(), 'all_day' => $time === null,
            'repeat' => $f['kind'] === 'task' && isset(Reminder::REPEATS[$f['repeat']]) ? $f['repeat'] : 'none',
            'brand_id' => $brandId, 'customer_id' => $brandId !== null ? Brand::query()->whereKey($brandId)->value('customer_id') : null,
            'sales_lead_id' => $lead?->id,
        ]);
        if ($lead !== null && $f['kind'] === 'meeting') {
            app(SalesLeads::class)->meetingPlanned($lead);
            app(SalesLeads::class)->event($lead, 'meeting', 'Toplantı planlandı: '.$start->translatedFormat('j F l H:i'), now(), ['planned_for' => $start->toIso8601String()]);
        }
        $this->adding = false;
        $this->day = $date;
        $this->month = substr($date, 0, 7);
        $this->say($f['kind'] === 'meeting' ? 'Toplantı takvime eklendi; 15 dakika önce telefona hatırlatılır.' : 'İş takvime eklendi.');
    }

    /** Ticks an own task (a repeating one moves to its next date) or a meeting; a second click opens it again. */
    public function toggle(int $id, ReminderService $reminders): void
    {
        $row = $this->mine($id);
        if ($row->done_at !== null) {
            $row->forceFill(['done_at' => null])->save();

            return;
        }
        $reminders->complete($row);
    }

    public function writeNote(int $id): void
    {
        $row = $this->mine($id);
        $this->noteFor = $row->id;
        $this->note = (string) $row->outcome;
    }

    /** The meeting's note: kept on the meeting, closes it, and goes to the prospect's card when it was with one. */
    public function saveNote(SalesLeads $leads): void
    {
        $row = $this->noteFor !== null ? $this->mine($this->noteFor) : null;
        $text = trim($this->note);
        if ($row === null || $text === '') {
            throw ValidationException::withMessages(['note' => 'Ne konuşuldu, ne karar verildi: kısa yaz.']);
        }
        $row->forceFill(['outcome' => $text, 'done_at' => $row->done_at ?? now()])->save();
        if ($row->sales_lead_id !== null && ($lead = SalesLead::query()->find($row->sales_lead_id)) !== null) {
            $leads->event($lead, 'meeting', $text, $row->ends_at ?? $row->remind_at, ['reminder_id' => $row->id], 'meeting-note:'.$row->id.':'.md5($text));
            if ($lead->isOpen()) {
                // After the meeting: the next message is written from the note at once, for tomorrow 10:00 at the latest.
                $lead->advanceTo('meeting');
                [$lead->next_step, $lead->next_step_at] = ['Görüşme sonrası mesaj', CarbonImmutable::now(CalendarFeed::TZ)->addDay()->setTime(10, 0)->utc()];
                $lead->save();
                SalesReplyWriter::queue($lead);
            }
        }
        $this->noteFor = null;
        $this->note = '';
        $this->say($row->sales_lead_id !== null ? 'Not kaydedildi ve adayın kartına yazıldı; görüşme sonrası mesajı Claude yazıyor.' : 'Not kaydedildi.');
    }

    public function remove(int $id): void
    {
        $this->mine($id)->delete();
        $this->say('Silindi.');
    }

    /** Shows the read-only feed address (created on first ask) to paste into Google Takvim. */
    public function showFeed(): void
    {
        $this->feedUrl = $this->feedUrl === null ? route('calendar.feed', CalendarIcs::token(auth()->user())) : null;
    }

    /** A new address; the old one stops working at once (for a leaked or shared link). */
    public function renewFeed(): void
    {
        $this->feedUrl = route('calendar.feed', CalendarIcs::token(auth()->user(), true));
        $this->say('Takvim adresi yenilendi. Eski adres artık çalışmıyor; Google Takvim\'e yeni adresi ekle.');
    }

    public function render(CalendarFeed $feed): View
    {
        $now = CarbonImmutable::now(CalendarFeed::TZ);
        $month = self::validMonth($this->month) ?? $now->startOfMonth();
        $gridFrom = $month->startOfMonth()->startOfWeek(CarbonImmutable::MONDAY);
        $gridTo = $month->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY);
        $userId = (int) auth()->id();
        $failed = false;
        try {
            $days = $feed->range($gridFrom, $gridTo, $userId);
            $agendaFrom = $now->startOfDay();
            $agenda = $feed->range($agendaFrom, $agendaFrom->addDays(6)->endOfDay(), $userId);
            $open = $this->day !== '' ? $feed->day(CarbonImmutable::parse($this->day, CalendarFeed::TZ), $userId) : null;
            $report = $this->report ? $feed->month($month, $userId) : null;
        } catch (Throwable $error) {
            report($error);
            $failed = true;
            $days = $agenda = [];
            $open = $report = null;
        }

        return view('livewire.operator.calendar.calendar-page', [
            'monthStart' => $month->startOfMonth(), 'days' => $days, 'agenda' => $agenda, 'open' => $open, 'monthReport' => $report,
            'today' => $now->toDateString(), 'failed' => $failed,
            'prev' => $month->subMonthNoOverflow()->format('Y-m'), 'next' => $month->addMonthNoOverflow()->format('Y-m'),
            'brands' => $this->adding ? Brand::query()->orderBy('name')->get(['id', 'name']) : collect(),
            'leads' => $this->adding ? SalesLead::query()->open()->orderByDesc('updated_at')->limit(200)->get() : collect(),
            'komuta' => $open['komuta'] ?? null,
            'komutaToday' => ! $failed && ! $this->report && $gridFrom->toDateString() <= $now->toDateString() && $gridTo->toDateString() >= $now->toDateString() ? $feed->komuta() : null,
        ]);
    }

    private function mine(int $id): Reminder
    {
        return Reminder::query()->where('user_id', auth()->id())->findOrFail($id);
    }

    private static function validDate(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)) ? $value : null;
    }

    private static function validTime(string $value): ?string
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : null;
    }

    private static function validMonth(string $value): ?CarbonImmutable
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1 ? CarbonImmutable::parse($value.'-01', CalendarFeed::TZ) : null;
    }

    private function say(string $message): void
    {
        $this->dispatch('operator-notice', message: $message, tone: 'success');
    }
}
