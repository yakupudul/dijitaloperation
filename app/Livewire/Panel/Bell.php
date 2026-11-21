<?php

namespace App\Livewire\Panel;

use App\Panel\Models\PanelNotification;
use App\Panel\Models\PanelReminder;
use App\Panel\Support\PanelContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/** The bell: what happened for this user in this brand since they last looked, and the reminders that are due. */
final class Bell extends Component
{
    public string $side = 'left';

    /** The newest notification this browser tab has already announced. */
    public ?int $announced = null;

    public function boot(): void
    {
        abort_if(app(PanelContext::class)->membership() === null, 403);
    }

    private function userId(): int
    {
        return (int) Auth::guard('client')->id();
    }

    public function open(int $id): mixed
    {
        $row = PanelNotification::query()->where('panel_user_id', $this->userId())->findOrFail($id);
        $row->forceFill(['read_at' => $row->read_at ?? now()])->save();

        return $row->url ? $this->redirect($row->url, navigate: true) : null;
    }

    public function readAll(): void
    {
        PanelNotification::query()->where('panel_user_id', $this->userId())->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function render(): View
    {
        $mine = PanelNotification::query()->where('panel_user_id', $this->userId());
        $due = PanelReminder::query()->with('person:id,name')->where('panel_user_id', $this->userId())->whereNull('done_at')->where('due_at', '<=', now())->orderBy('due_at')->limit(10)->get();

        $rows = (clone $mine)->orderByDesc('id')->limit(15)->get();
        $newest = (int) ($rows->first()?->id ?? 0);
        if ($this->announced !== null && $newest > $this->announced) {
            $fresh = $rows->first();
            if ($fresh->read_at === null && $this->side === 'left') {
                $this->dispatch('operator-notice', message: (string) $fresh->title, tone: 'ok');
            }
        }
        $this->announced = $newest;

        return view('livewire.panel.bell', [
            'rows' => $rows,
            'unread' => (clone $mine)->whereNull('read_at')->count() + $due->count(),
            'due' => $due,
        ]);
    }
}
