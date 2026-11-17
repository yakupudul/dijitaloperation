<?php

namespace App\Livewire\Operator\Today;

use App\Models\Reminder;
use App\Services\Operator\BrandToday;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Bugün (arayüz kararı, approved by yakup 2026-11-17): the home screen. One question: "bugün hangi marka bana muhtaç?"
 * Brands in one list, the most urgent on top, urgency from what the system found and from the operator's own to-dos
 * (two numbers per row), one sentence of state, and the brand's doors on the row. The Denetçi's remarks live in Moxi's
 * chat as a tab (yakup 2026-11-17). What still needs an approval (deletions, outgoing messages, review replies)
 * stays in Onay kutusu (the former Komuta) one click away.
 */
#[Layout('operator.layouts.app')]
#[Title('Bugün')]
final class TodayPage extends Component
{
    /** Show the brands that need nothing too. */
    public bool $all = false;

    public function finishTodo(int $reminderId): void
    {
        abort_unless((bool) auth()->user(), 403);
        $reminder = Reminder::query()->where('kind', 'task')->whereNull('done_at')->find($reminderId);
        if ($reminder !== null && ! $reminder->waitsForApproval()) {
            $reminder->forceFill(['done_at' => now()])->save();
        }
    }

    public function render(BrandToday $today): View
    {
        $data = $today->rows();
        $now = now('Europe/Istanbul');

        return view('livewire.operator.today.today-page', [
            'rows' => $this->all ? $data['rows'] : array_values(array_filter($data['rows'], fn (array $r): bool => $r['score'] > 0)),
            'calm' => $data['calm'],
            'total' => count($data['rows']),
            'loose' => $data['loose'],
            'firstName' => Str::before(trim((string) (auth()->user()?->name ?? '')).' ', ' '),
            'greeting' => $now->hour < 5 ? 'İyi geceler' : ($now->hour < 12 ? 'Günaydın' : ($now->hour < 18 ? 'İyi günler' : 'İyi akşamlar')),
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
        ]);
    }
}
