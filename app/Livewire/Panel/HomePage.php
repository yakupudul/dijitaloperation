<?php

namespace App\Livewire\Panel;

use App\Panel\Models\PanelReminder;
use App\Panel\Services\PanelLeads;
use App\Panel\Services\PanelWatch;
use App\Panel\Support\PanelPage;
use App\Panel\Support\PanelStages;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Ana sayfa: where the brand stands, who waits for a call, and today's reminders on one screen. */
#[Layout('panel.layouts.app')]
#[Title('Ana sayfa')]
final class HomePage extends Component
{
    use PanelPage;

    protected function permission(): string
    {
        return 'menu.home';
    }

    public function done(int $reminderId): void
    {
        abort_unless($this->may('reminders.write'), 403);
        $reminder = $this->reminders()->whereKey($reminderId)->firstOrFail();
        $reminder->forceFill(['done_at' => now()])->save();
        $this->notify('Hatırlatma kapandı.');
    }

    private function reminders()
    {
        $people = app(PanelLeads::class)->visible()->select('id');
        $query = PanelReminder::query()->whereNull('done_at')->whereIn('person_id', $people);
        if (! $this->panel()->isManager()) {
            $query->where(fn ($q) => $q->whereNull('panel_user_id')->orWhere('panel_user_id', $this->panelUser()->id));
        }

        return $query;
    }

    public function render(PanelLeads $leads): View
    {
        $monthStart = now('Europe/Istanbul')->startOfMonth()->utc();
        $todayEnd = now('Europe/Istanbul')->endOfDay()->utc();

        return view('livewire.panel.home', [
            'numbers' => [
                ['Bu ay gelen aday', $leads->visible()->where('created_at', '>=', $monthStart)->count(), null],
                ['Aranmayı bekleyen', $leads->visible()->where('stage', PanelStages::NEW)->count(), PanelStages::NEW],
                ['Randevu verilen', $leads->visible()->where('stage', PanelStages::APPOINTMENT)->count(), PanelStages::APPOINTMENT],
                ['Bu ay müşteri olan', $leads->visible()->where('stage', PanelStages::CUSTOMER)->where('updated_at', '>=', $monthStart)->count(), PanelStages::CUSTOMER],
            ],
            'reminders' => $this->reminders()->with(['person:id,name', 'user:id,name'])->where('due_at', '<=', $todayEnd)->orderBy('due_at')->limit(30)->get(),
            'stale' => PanelWatch::stale($leads->visible())->count(),
            'later' => $this->reminders()->where('due_at', '>', $todayEnd)->count(),
            'waiting' => $leads->visible()->where('stage', PanelStages::NEW)->orderByDesc('created_at')->limit(8)->get(),
            'appointments' => $leads->visible()->whereNotNull('appointment_at')->where('appointment_at', '>=', now()->subHours(2))->whereIn('stage', PanelStages::open())->orderBy('appointment_at')->limit(8)->get(),
            'showPhone' => $this->may('leads.phone'),
            'canClose' => $this->may('reminders.write'),
            'canOpenLeads' => $this->may('menu.leads'),
        ]);
    }
}
