<?php

namespace App\Livewire\Operator\Today;

use App\Models\ClaudeNote;
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
 * (two numbers per row), one sentence of state, and the brand's doors on the row. On top, at most three remarks of the
 * Denetçi with "Haklısın" / "Katılmıyorum". What still needs an approval (deletions, outgoing messages, review replies)
 * stays in Onay kutusu (the former Komuta) one click away.
 */
#[Layout('operator.layouts.app')]
#[Title('Bugün')]
final class TodayPage extends Component
{
    /** Show the brands that need nothing too. */
    public bool $all = false;

    /** Haklısın: Claude does it on its next round (brand work) or it goes to the development pool (system). */
    public function agree(int $noteId): void
    {
        $this->answer($noteId, ClaudeNote::AGREED);
    }

    /** Katılmıyorum: learned; the same remark is not raised again. */
    public function disagree(int $noteId): void
    {
        $this->answer($noteId, ClaudeNote::REJECTED);
    }

    public function finishTodo(int $reminderId): void
    {
        abort_unless((bool) auth()->user(), 403);
        $reminder = Reminder::query()->where('kind', 'task')->whereNull('done_at')->find($reminderId);
        if ($reminder !== null && ! $reminder->waitsForApproval()) {
            $reminder->forceFill(['done_at' => now()])->save();
        }
    }

    private function answer(int $noteId, string $status): void
    {
        abort_unless((bool) auth()->user()?->hasRole(Roles::ADMIN), 403);
        ClaudeNote::query()->whereKey($noteId)->where('kind', ClaudeNote::AUDIT)->where('status', ClaudeNote::OPEN)->update(['status' => $status, 'updated_at' => now()]);
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
            'audits' => $today->audits(),
            'firstName' => Str::before(trim((string) (auth()->user()?->name ?? '')).' ', ' '),
            'greeting' => $now->hour < 5 ? 'İyi geceler' : ($now->hour < 12 ? 'Günaydın' : ($now->hour < 18 ? 'İyi günler' : 'İyi akşamlar')),
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
        ]);
    }
}
