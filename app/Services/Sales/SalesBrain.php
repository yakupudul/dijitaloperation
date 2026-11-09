<?php

namespace App\Services\Sales;

use App\Ai\Agents\SalesLessonAgent;
use App\Jobs\Sales\LearnFromDeal;
use App\Models\SalesLead;
use App\Models\SalesLeadEvent;
use App\Models\SalesLesson;
use App\Models\User;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Satış beyni (yakup 2026-11-08: "öğrenen beyin", "kendini geliştiren öneriler"): every closed deal gets a karne (how
 * long it took, how many touches, where it came from, why it ended); Claude reads the karne with the conversation and
 * proposes lessons (a decision or a method) and the questions it needs answered (a price, a rule). They wait in
 * Satış › Ayarlar for the operator's approval; approved lessons and answered questions go into every sales text,
 * after the Sabit kararlar, which always win.
 */
final class SalesBrain
{
    /** Deals learned from by themselves per day (a bulk close cannot spend the AI cap). */
    public const int DAILY = 10;

    /** Lessons and questions taken from one deal. */
    private const int PER_DEAL = 4;

    public function __construct(private AiRouteResolver $routes, private AiProviderRuntimeConfig $runtime, private SalesReplyWriter $reply, private SalesSubscription $subscription) {}

    /**
     * The karne of a closed deal: plain facts, no AI.
     *
     * @return array{outcome: string, days: ?int, in: int, out: int, calls: int, first_reply_hours: ?int, source: string, via: string, offer: ?int, reason: ?string, peak: string, line: string}
     */
    public function karne(SalesLead $lead): array
    {
        $won = $lead->stage === 'won' || $lead->isCustomer();
        $end = $won ? ($lead->won_at ?? $lead->updated_at) : ($lead->lost_at ?? $lead->updated_at);
        $days = $lead->created_at !== null && $end !== null ? max(0, (int) round(CarbonImmutable::instance($lead->created_at)->diffInHours(CarbonImmutable::instance($end)) / 24)) : null;
        $kinds = SalesLeadEvent::query()->where('lead_id', $lead->id)->selectRaw('kind, count(*) as n')->groupBy('kind')->pluck('n', 'kind');
        $firstIn = SalesLeadEvent::query()->where('lead_id', $lead->id)->where('kind', 'message_in')->min('happened_at');
        $firstOut = $firstIn !== null ? SalesLeadEvent::query()->where('lead_id', $lead->id)->where('kind', 'message_out')->where('happened_at', '>=', $firstIn)->min('happened_at') : null;
        $firstReply = $firstIn !== null && $firstOut !== null ? (int) round(CarbonImmutable::parse($firstIn)->diffInMinutes(CarbonImmutable::parse($firstOut)) / 60) : null;
        $reason = ! $won && filled($lead->lost_reason) ? (SalesLead::LOST_REASONS[$lead->lost_reason] ?? (string) $lead->lost_reason) : null;
        $via = $lead->cameViaLabel() ?: 'Moximu';
        $offer = $lead->offer_amount ? (int) $lead->offer_amount : null;
        $in = (int) ($kinds['message_in'] ?? 0);
        $out = (int) ($kinds['message_out'] ?? 0);
        $calls = (int) ($kinds['call'] ?? 0) + (int) ($kinds['meeting'] ?? 0);
        $peak = SalesLead::STAGES[$lead->peak_stage ?? 'new'] ?? (string) $lead->peak_stage;
        $line = collect([
            $won ? 'Kazanıldı' : 'Olumsuz'.($reason ? ' ('.$reason.')' : ''),
            $days !== null ? $days.' günde' : null,
            $lead->sourceLabel().($via !== 'Moximu' ? ', '.$via : ''),
            $in + $out > 0 ? $in.' gelen / '.$out.' giden mesaj' : null,
            $calls > 0 ? $calls.' arama/görüşme' : null,
            $firstReply !== null ? 'ilk cevap '.($firstReply < 1 ? '1 saatten kısa' : $firstReply.' sa') : null,
            $offer ? 'teklif '.number_format($offer, 0, ',', '.').' ₺' : null,
            ! $won ? 'en ileri: '.$peak : null,
        ])->filter()->implode(' · ');

        return ['outcome' => $won ? 'won' : 'lost', 'days' => $days, 'in' => $in, 'out' => $out, 'calls' => $calls, 'first_reply_hours' => $firstReply,
            'source' => $lead->sourceLabel(), 'via' => $via, 'offer' => $offer, 'reason' => $reason, 'peak' => $peak, 'line' => $line];
    }

    /**
     * The latest closed deals with their karne, for Rapor.
     *
     * @return Collection<int, array{lead: SalesLead, karne: array<string, mixed>}>
     */
    public function karneler(int $limit = 12): Collection
    {
        return SalesLead::query()->relevant()->whereIn('stage', ['won', 'lost'])
            ->where(fn ($q) => $q->where('stage', 'won')->orWhere('lost_reason', '!=', 'irrelevant')->orWhereNull('lost_reason'))
            ->orderByRaw('coalesce(won_at, lost_at, updated_at) desc')->limit($limit)->get()
            ->map(fn (SalesLead $lead): array => ['lead' => $lead, 'karne' => $this->karne($lead)]);
    }

    /** Hourly: deals closed in the last 30 days that were never read are queued, a few at a time. */
    public static function sweep(): int
    {
        $queued = 0;
        SalesLead::query()->relevant()->whereIn('stage', ['won', 'lost'])->whereNull('lesson_status')
            ->where(fn ($q) => $q->where('won_at', '>=', now()->subDays(30))->orWhere('lost_at', '>=', now()->subDays(30)))
            ->where(fn ($q) => $q->whereNull('lost_reason')->orWhere('lost_reason', '!=', 'irrelevant'))
            ->orderBy('id')->limit(3)->get()
            ->each(function (SalesLead $lead) use (&$queued): void {
                $queued += self::queueAfterClose($lead) ? 1 : 0;
            });

        return $queued;
    }

    /** A deal just closed (won or lost): Claude learns from it in the background, within the day's share. */
    public static function queueAfterClose(SalesLead $lead): bool
    {
        if (! in_array($lead->stage, ['won', 'lost'], true) || $lead->isIrrelevant() || in_array($lead->lesson_status, ['queued', 'running', 'waiting', 'ready'], true)) {
            return false;
        }
        $today = SalesLead::query()->where('lesson_at', '>=', now('Europe/Istanbul')->startOfDay()->utc())->count();
        if ($today >= self::DAILY) {
            return false;
        }
        $lead->forceFill(['lesson_status' => 'queued', 'lesson_note' => null])->save();
        SalesSubscription::withdraw(new LearnFromDeal((int) $lead->id));
        LearnFromDeal::dispatch((int) $lead->id);

        return true;
    }

    public function learn(int $leadId, int $timeout = 120, ?LearnFromDeal $job = null): bool
    {
        $lead = SalesLead::query()->find($leadId);
        if ($lead === null || ! in_array($lead->stage, ['won', 'lost'], true)) {
            return false;
        }
        $answer = $job !== null ? $this->subscription->answered(new LearnFromDeal($leadId), SalesLessonAgent::ROUTE) : null;
        if ($answer === []) {
            return $this->fail($lead, 'Abonelikteki Claude bu anlaşmadan ders çıkaramadı.');
        }
        if ($answer === null) {
            $payload = $this->payload($lead);
            if ($this->subscription->takesOver()) {
                return $this->wait($lead, $payload);
            }
            $lead->forceFill(['lesson_status' => 'running'])->save();
            try {
                $route = $this->routes->resolve(SalesLessonAgent::ROUTE);
                if ($route->isEmpty()) {
                    return $this->subscription->canWait() ? $this->wait($lead, $payload, 'Claude API bağlı değil')
                        : $this->fail($lead, 'Claude API bağlantısı yok ya da günlük AI tavanı doldu.');
                }
                $this->runtime->prepare(array_keys($route->providerModels));
                $answer = (array) (new SalesLessonAgent)->prompt("LEAD_JSON\n".json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    provider: $route->providerModels, timeout: $timeout)->toArray();
            } catch (Throwable $exception) {
                Log::warning('Sales lesson failed.', ['lead_id' => $lead->id, 'error' => $exception->getMessage()]);

                return $this->subscription->canWait() ? $this->wait($lead, $payload, 'Claude API hata verdi')
                    : $this->fail($lead, 'Yapay zekâ isteği başarısız oldu.');
            }
        }
        $this->store($lead, $answer);

        return true;
    }

    /**
     * Claude's answer: the verdict on the deal, the lessons and the questions (each one once: a lesson already known,
     * approved or rejected, is not proposed again).
     *
     * @param  array<string, mixed>  $answer
     */
    public function store(SalesLead $lead, array $answer): void
    {
        $known = SalesLesson::query()->pluck('text')->map(fn (string $t): string => self::key($t))->flip();
        $rows = [];
        foreach ((array) ($answer['lessons'] ?? []) as $lesson) {
            $text = trim((string) (is_array($lesson) ? ($lesson['text'] ?? '') : $lesson));
            $kind = is_array($lesson) && ($lesson['kind'] ?? '') === 'karar' ? 'karar' : 'yontem';
            if (mb_strlen($text) >= 12) {
                $rows[] = ['kind' => $kind, 'text' => mb_substr($text, 0, 1000)];
            }
        }
        foreach ((array) ($answer['questions'] ?? []) as $question) {
            $text = trim((string) $question);
            if (mb_strlen($text) >= 8) {
                $rows[] = ['kind' => 'soru', 'text' => mb_substr($text, 0, 1000)];
            }
        }
        $added = 0;
        foreach ($rows as $row) {
            if ($added >= self::PER_DEAL || isset($known[self::key($row['text'])])) {
                continue;
            }
            SalesLesson::query()->create([...$row, 'source' => 'claude', 'lead_id' => $lead->id, 'status' => 'proposed']);
            $known[self::key($row['text'])] = true;
            $added++;
        }
        $verdict = trim((string) ($answer['verdict'] ?? ''));
        $lead->forceFill(['lesson_status' => 'ready', 'lesson_note' => null, 'lesson_verdict' => $verdict !== '' ? mb_substr($verdict, 0, 2000) : $lead->lesson_verdict, 'lesson_at' => now()])->save();
    }

    /**
     * What the drafts read (after the Sabit kararlar): approved decisions and methods, answered questions.
     *
     * @return list<string>
     */
    public static function forPrompt(): array
    {
        return SalesLesson::query()->approved()->orderBy('kind')->orderBy('id')->limit(60)->get()
            ->map(fn (SalesLesson $l): string => $l->kind === 'soru' ? 'Soru: '.$l->text.' Cevap: '.trim((string) $l->answer) : $l->kindLabel().': '.$l->text)
            ->values()->all();
    }

    public function add(string $kind, string $text, ?User $user): SalesLesson
    {
        $text = trim($text);
        if (! isset(SalesLesson::KINDS[$kind]) || mb_strlen($text) < 5) {
            throw ValidationException::withMessages(['brain' => 'Ne öğrenildiğini yaz (en az birkaç kelime).']);
        }

        return SalesLesson::query()->create(['kind' => $kind, 'text' => mb_substr($text, 0, 1000), 'source' => 'operator',
            'status' => $kind === 'soru' ? 'proposed' : 'approved', 'decided_by' => $user?->id, 'decided_at' => $kind === 'soru' ? null : now()]);
    }

    public function approve(SalesLesson $lesson, ?string $text, ?User $user): void
    {
        $text = $text !== null ? trim($text) : $lesson->text;
        if (mb_strlen($text) < 5) {
            throw ValidationException::withMessages(['brain' => 'Metin boş kalamaz.']);
        }
        $lesson->forceFill(['text' => mb_substr($text, 0, 1000), 'status' => 'approved', 'decided_by' => $user?->id, 'decided_at' => now()])->save();
    }

    public function answer(SalesLesson $lesson, string $answer, ?User $user): void
    {
        $answer = trim($answer);
        if ($answer === '') {
            throw ValidationException::withMessages(['brain' => 'Cevabı yaz.']);
        }
        $lesson->forceFill(['answer' => mb_substr($answer, 0, 2000), 'status' => 'approved', 'decided_by' => $user?->id, 'decided_at' => now()])->save();
    }

    public function reject(SalesLesson $lesson, ?User $user): void
    {
        $lesson->forceFill(['status' => 'rejected', 'decided_by' => $user?->id, 'decided_at' => now()])->save();
    }

    /** @return array<string, mixed> */
    private function payload(SalesLead $lead): array
    {
        $base = $this->reply->payload($lead);

        return [
            'karne' => $this->karne($lead),
            'lead' => $base['lead'] ?? [],
            'conversation' => $base['conversation'] ?? [],
            'operator_notes' => $base['operator_notes'] ?? [],
            'standing_rules' => $base['standing_rules'] ?? '',
            'known_lessons' => SalesLesson::query()->whereIn('status', ['approved', 'rejected', 'proposed'])->latest('id')->limit(80)->pluck('text')->all(),
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function wait(SalesLead $lead, array $payload, ?string $why = null): bool
    {
        $note = $this->subscription->ask(new LearnFromDeal((int) $lead->id), new SalesLessonAgent, $payload, 'Anlaşmadan ders: '.$lead->title(), $why);
        $lead->forceFill(['lesson_status' => 'waiting', 'lesson_note' => mb_substr($note, 0, 500)])->save();

        return false;
    }

    private function fail(SalesLead $lead, string $reason): bool
    {
        $lead->forceFill(['lesson_status' => 'failed', 'lesson_note' => $reason])->save();

        return false;
    }

    private static function key(string $text): string
    {
        return mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $text) ?? '');
    }
}
