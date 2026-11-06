<?php

namespace App\Services\Social;

use App\Ai\Agents\SocialReplyAgent;
use App\Jobs\Social\DraftSocialReply;
use App\Models\SocialThread;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\Sales\SalesSubscription;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * The reply draft of a social inbox thread (ADR-083, phase 2), written like the Satış texts: the Claude API at once,
 * ChatGPT when it fails, and the subscription Claude in its next round while the API is held. Nothing is sent here.
 */
final class SocialReplyWriter
{
    public const array STATUS = [
        'queued' => 'Yazılıyor…', 'running' => 'Yazılıyor…', 'waiting' => 'Abonelikteki Claude yazacak', 'ready' => 'Hazır', 'failed' => 'Yazılamadı',
    ];

    public function __construct(private AiRouteResolver $routes, private AiProviderRuntimeConfig $runtime, private SalesSubscription $subscription) {}

    public function write(int $threadId, int $timeout = 120, ?DraftSocialReply $job = null): bool
    {
        $thread = SocialThread::query()->with('account')->find($threadId);
        if ($thread === null || $thread->status !== SocialThread::OPEN) {
            return false;
        }
        if ($job !== null && ($answer = $this->subscription->answered(new DraftSocialReply($threadId), SocialReplyAgent::ROUTE)) !== null) {
            if ($answer === []) {
                $this->fail($thread, 'Abonelikteki Claude yazamadı; "Yeniden yaz" ile tekrar dene.');

                return false;
            }

            return $this->store($thread, $answer);
        }
        $payload = $this->payload($thread);
        if ($this->subscription->takesOver()) {
            return $this->wait($thread, $payload);
        }
        $thread->forceFill(['draft_status' => 'running'])->save();
        try {
            $route = $this->routes->resolve(SocialReplyAgent::ROUTE);
            if ($route->isEmpty()) {
                if ($this->subscription->canWait()) {
                    return $this->wait($thread, $payload, 'Claude API bağlı değil');
                }
                $this->fail($thread, 'Claude API bağlantısı yok ya da günlük AI tavanı doldu (Ayarlar › AI).');

                return false;
            }
            $this->runtime->prepare(array_keys($route->providerModels));
            $answer = (new SocialReplyAgent)->prompt("THREAD_JSON\n".json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                provider: $route->providerModels, timeout: $timeout)->toArray();

            return $this->store($thread, (array) $answer);
        } catch (Throwable $exception) {
            Log::warning('Social reply draft failed.', ['thread_id' => $thread->id, 'error' => $exception->getMessage()]);
            if ($this->subscription->canWait()) {
                return $this->wait($thread, $payload, 'Claude API hata verdi');
            }
            $this->fail($thread, 'Yapay zekâ isteği başarısız oldu; "Yeniden yaz" ile tekrar dene.');

            return false;
        }
    }

    /** @param  array<string, mixed>  $answer */
    public function store(SocialThread $thread, array $answer): bool
    {
        $valid = Validator::make($answer, [
            'action' => ['required', 'in:reply,ignore'], 'reply' => ['present', 'nullable', 'string', 'max:2000'],
            'lead' => ['nullable', 'boolean'], 'note' => ['nullable', 'string', 'max:1000'],
        ]);
        if ($valid->fails() || ($answer['action'] === 'reply' && trim((string) $answer['reply']) === '')) {
            $this->fail($thread, 'Yapay zekâ geçersiz bir cevap döndürdü; "Yeniden yaz" ile tekrar dene.');

            return false;
        }
        $note = trim((string) ($answer['note'] ?? '')) ?: null;
        if ($answer['action'] === 'ignore') {
            $thread->forceFill(['draft' => null, 'draft_status' => 'ready', 'draft_note' => 'Cevap gerekmiyor: '.($note ?? 'spam ya da alakasız.'),
                'draft_lead' => false, 'draft_at' => now(), 'status' => SocialThread::IGNORED])->save();

            return true;
        }
        $thread->forceFill(['draft' => trim((string) $answer['reply']), 'draft_status' => 'ready', 'draft_note' => $note,
            'draft_lead' => (bool) ($answer['lead'] ?? false), 'draft_at' => now()])->save();
        if ($thread->draft_lead) {
            try {
                app(SocialInbox::class)->toLead($thread);
            } catch (Throwable $e) {
                Log::warning('Social lead card failed.', ['thread_id' => $thread->id, 'error' => $e->getMessage()]);
            }
        }

        return true;
    }

    /** @param  array<string, mixed>  $payload */
    private function wait(SocialThread $thread, array $payload, ?string $why = null): bool
    {
        $note = $this->subscription->ask(new DraftSocialReply((int) $thread->id), new SocialReplyAgent, $payload,
            'Sosyal medya cevabı: '.($thread->author_name ?: $thread->kindLabel()), $why);
        $thread->forceFill(['draft_status' => 'waiting', 'draft_note' => mb_substr($note, 0, 990)])->save();

        return false;
    }

    private function fail(SocialThread $thread, string $reason): void
    {
        $thread->forceFill(['draft_status' => 'failed', 'draft_note' => $reason])->save();
    }

    /** @return array<string, mixed> */
    public function payload(SocialThread $thread): array
    {
        $messages = $thread->messages()->orderByDesc('sent_at')->limit(40)->get()->reverse()->values()
            ->map(fn ($m): array => ['dir' => $m->direction, 'at' => $m->sent_at->timezone('Europe/Istanbul')->format('d.m.Y H:i'),
                'text' => mb_substr((string) $m->body, 0, 2000)])->all();

        return [
            'current_time' => now('Europe/Istanbul')->locale('tr')->isoFormat('YYYY-MM-DD HH:mm, dddd'),
            'kind' => $thread->kind,
            'platform' => $thread->account?->platform,
            'author' => $thread->author_name,
            'post_caption' => $thread->post_caption,
            'conversation' => $messages,
        ];
    }
}
