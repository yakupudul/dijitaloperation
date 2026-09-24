<?php

namespace App\Livewire\Operator\Assistant;

use App\Models\Customer;
use App\Models\Prospect;
use App\Models\Reminder;
use App\Services\Assistant\ReminderService;
use App\Services\Assistant\TodayReader;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Bugün (top of the home screen): sites down, reminders, renewals, WhatsApp waiting, follow-ups and who to
 * contact; quick reminder entry and the personal calendar feed link.
 */
final class TodayPanel extends Component
{
    public string $title = '';

    public string $when = '';

    public string $repeat = 'none';

    public string $customerId = '';

    public string $message = '';

    public function mount(): void
    {
        $this->when = now()->addHour()->startOfHour()->timezone(auth()->user()?->timezone ?: 'Europe/Istanbul')->format('Y-m-d\TH:i');
    }

    public function addReminder(): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:255'], 'when' => ['required', 'date'],
            'repeat' => ['required', 'in:'.implode(',', array_keys(Reminder::REPEATS))], 'customerId' => ['nullable', 'exists:customers,id'],
        ]);
        $timezone = auth()->user()?->timezone ?: 'Europe/Istanbul';
        Reminder::query()->create([
            'user_id' => auth()->id(), 'title' => trim($this->title), 'repeat' => $this->repeat,
            'customer_id' => $this->customerId !== '' ? (int) $this->customerId : null,
            'remind_at' => CarbonImmutable::parse($this->when, $timezone)->utc(),
        ]);
        $this->reset('title', 'customerId');
        $this->repeat = 'none';
        $this->message = 'Hatırlatıcı eklendi.';
    }

    public function done(int $id, ReminderService $reminders): void
    {
        $reminders->complete(Reminder::query()->where('user_id', auth()->id())->findOrFail($id));
    }

    public function followedUp(int $id): void
    {
        Prospect::query()->findOrFail($id)->forceFill(['next_follow_up_on' => null])->save();
    }

    public function calendarLink(): void
    {
        auth()->user()?->forceFill(['calendar_feed_token' => Str::random(48)])->save();
        $this->message = 'Takvim bağlantısı oluşturuldu. Google Takvim → Diğer takvimler → URL ile ekle.';
    }

    public function render(TodayReader $today): View
    {
        return view('livewire.operator.assistant.today-panel', [
            'today' => $today->read(auth()->user()),
            'customers' => Customer::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id'),
            'timezone' => auth()->user()?->timezone ?: 'Europe/Istanbul',
        ]);
    }
}
