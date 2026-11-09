<?php

namespace App\Livewire\Operator;

use App\Jobs\Moxi\MoxiTurnJob;
use App\Models\MoxiMessage;
use App\Models\SystemChange;
use App\Services\Moxi\MoxiActions;
use App\Services\Operations\SystemChangeDesk;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Throwable;

/**
 * Moxi, the corner assistant (yakup 2026-11-09): a small samurai at the bottom right of every panel screen (admins only).
 * The operator writes; the answer is made off the request (MoxiTurnJob) and the chat polls until it lands. Cards wait
 * for Yap / Vazgeç, Claude's answers to handed-over jobs arrive here, and Moxi's improvement proposals wait for Onayla /
 * Sonra. The mascot's moves, sounds and speech run in the browser (Alpine) on the events this component sends.
 */
final class Moxi extends Component
{
    /** Lines shown in the chat. */
    private const int SHOW = 40;

    public string $text = '';

    /** The newest Moxi line the browser already had (new ones are spoken and animated). */
    public int $seen = 0;

    public function mount(): void
    {
        $this->seen = (int) MoxiMessage::query()->where('user_id', Auth::id())->whereIn('role', [MoxiMessage::MOXI, MoxiMessage::REQUEST])->max('id');
    }

    public function send(?string $page = null): void
    {
        abort_unless(self::allowed(), 403);
        $text = trim($this->text);
        if ($text === '' || $this->busy()) {
            return;
        }
        $this->text = '';
        $message = MoxiMessage::query()->create([
            'user_id' => Auth::id(), 'role' => MoxiMessage::USER, 'status' => MoxiMessage::PENDING,
            'body' => mb_substr($text, 0, 4000), 'page' => $page !== null ? mb_substr($page, 0, 255) : null,
        ]);
        MoxiTurnJob::dispatch($message->id);
        $this->dispatch('moxi-think');
    }

    /** Polled: tells the browser about new lines (answers, Claude's answers) so Moxi says them. */
    public function refresh(): void
    {
        $new = MoxiMessage::query()->where('user_id', Auth::id())->where('id', '>', $this->seen)
            ->where(fn ($q) => $q->where('role', MoxiMessage::MOXI)->orWhere(fn ($r) => $r->where('role', MoxiMessage::REQUEST)->where('status', MoxiMessage::DONE)))
            ->orderBy('id')->get();
        foreach ($new as $row) {
            $this->seen = max($this->seen, $row->id);
        }
        $last = $new->last();
        if ($last !== null) {
            $said = $last->role === MoxiMessage::REQUEST ? 'Claude cevap verdi: '.$last->body : (string) $last->body;
            $this->dispatch('moxi-said', text: self::plain($said), mood: data_get($last->meta, 'error') ? 'confused' : 'talk');
        }
    }

    public function confirm(int $id, MoxiActions $actions): void
    {
        abort_unless(self::allowed(), 403);
        $card = $this->own($id, MoxiMessage::CARD);
        if ($card === null || $card->status !== MoxiMessage::PENDING) {
            return;
        }
        try {
            $result = $actions->run($card, Auth::user());
            $card->forceFill(['status' => MoxiMessage::DONE, 'meta' => array_merge((array) $card->meta, ['result' => $result['text'], 'url' => $result['url']])])->save();
            $this->dispatch('moxi-happy', text: $result['text']);
        } catch (Throwable $error) {
            if (! $error instanceof \InvalidArgumentException && ! $error instanceof ValidationException) {
                report($error);
            }
            $card->forceFill(['status' => MoxiMessage::FAILED, 'meta' => array_merge((array) $card->meta, ['result' => MoxiActions::reason($error)])])->save();
            $this->dispatch('moxi-said', text: MoxiActions::reason($error), mood: 'confused');
        }
    }

    public function cancel(int $id): void
    {
        $card = $this->own($id, MoxiMessage::CARD);
        if ($card !== null && $card->status === MoxiMessage::PENDING) {
            $card->forceFill(['status' => MoxiMessage::CANCELLED])->save();
        }
    }

    /** A Moxi proposal: Onayla approves it in the Geliştirme havuzu (Claude then codes it), Sonra leaves it there. */
    public function decide(int $id, bool $approve, SystemChangeDesk $desk): void
    {
        abort_unless(self::allowed(), 403);
        $row = $this->own($id, MoxiMessage::AUDIT);
        if ($row === null || $row->status !== MoxiMessage::PENDING) {
            return;
        }
        $change = SystemChange::query()->find((int) data_get($row->meta, 'change_id'));
        if ($approve && $change !== null && $change->status === SystemChange::PROPOSED) {
            $desk->approve($change, Auth::user(), 'Moxi kutusundan onaylandı.');
            $this->dispatch('moxi-happy', text: 'Öneri onaylandı, Claude sıradaki geliştirme turunda kodlar.');
        }
        $row->forceFill(['status' => $approve ? MoxiMessage::DONE : MoxiMessage::CANCELLED])->save();
    }

    public function clear(): void
    {
        // Only the view: the lines stay as Moxi's memory.
        MoxiMessage::query()->where('user_id', Auth::id())->where('hidden', false)->update(['hidden' => true]);
    }

    private function own(int $id, string $role): ?MoxiMessage
    {
        return MoxiMessage::query()->where('user_id', Auth::id())->where('role', $role)->find($id);
    }

    private function busy(): bool
    {
        return MoxiMessage::query()->where('user_id', Auth::id())->where('role', MoxiMessage::USER)->where('status', MoxiMessage::PENDING)
            ->where('created_at', '>', now()->subMinutes(5))->exists();
    }

    public static function allowed(): bool
    {
        return (bool) Auth::user()?->hasRole(Roles::ADMIN);
    }

    /** Markdown links and bold as safe HTML. */
    public static function html(string $text): string
    {
        $html = e($text);
        $html = preg_replace_callback('/\[([^\]]{1,200})\]\(((?:https?:\/\/|\/)[^\s)]{1,500})\)/u', fn (array $m): string => '<a href="'.$m[2].'" class="moxi-link">'.$m[1].'</a>', $html) ?? $html;
        $html = preg_replace('/\*\*([^*]{1,300})\*\*/u', '<strong>$1</strong>', $html) ?? $html;

        return nl2br($html, false);
    }

    /** What the browser reads aloud: no link targets, no markup. */
    public static function plain(string $text): string
    {
        $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/u', '$1', $text) ?? $text;

        return Str::limit(trim(str_replace(['**', '#', '`'], '', $text)), 600, '');
    }

    public function render(): View
    {
        $rows = self::allowed()
            ? MoxiMessage::query()->where('user_id', Auth::id())->where('hidden', false)
                ->orderByDesc('id')->limit(self::SHOW)->get()->reverse()->values()
            : collect();

        return view('livewire.operator.moxi', [
            'rows' => $rows,
            'busy' => self::allowed() && $this->busy(),
            'alert' => self::allowed() && MoxiMessage::query()->where('user_id', Auth::id())->where('role', MoxiMessage::AUDIT)->where('status', MoxiMessage::PENDING)->exists(),
            'waitingJobs' => self::allowed() && MoxiMessage::query()->where('user_id', Auth::id())->where('role', MoxiMessage::REQUEST)->where('status', MoxiMessage::PENDING)->exists(),
        ]);
    }
}
