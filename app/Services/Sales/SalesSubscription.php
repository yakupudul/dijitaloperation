<?php

namespace App\Services\Sales;

use App\Ai\Contracts\RegistryPrompted;
use App\Models\AiTask;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\AiTasks\AiWorkers;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;

/**
 * Satış texts when the Claude API cannot write (yakup 2026-11-06: "Günlük api sınırı dolunca abone Claude yapsın",
 * "api kota açıldığında tekrar Claude API devralsın"). The API stays first: only while its worker is held (daily or
 * monthly TL cap, an error pause), it has no key, or its call fails does the question go to the AI queue, where the subscription Claude
 * answers it in its next round. The answer dispatches the asking job again, which stores it. Nothing else changes:
 * once the API is alive again (the cap is counted per Istanbul day) the next text goes to it at once.
 */
final class SalesSubscription
{
    public function __construct(private AiWorkers $workers, private AiTaskQueue $queue) {}

    /** The Claude API is on but held back now (its TL cap, an error pause) and the subscription Claude is connected. */
    public function takesOver(): bool
    {
        return $this->workers->apiHeld(AiWorkers::CLAUDE_API) && $this->canWait();
    }

    /** The subscription Claude is connected, so a question can wait for it. */
    public function canWait(): bool
    {
        return $this->workers->connected(AiWorkers::CLAUDE_MCP);
    }

    /**
     * The subscription's answer to this job's question, used up on read: null when none came, [] when it could not
     * do it, else its structured output.
     *
     * @return array<string, mixed>|null
     */
    public function answered(object $job, string $operation): ?array
    {
        $task = AiTask::query()->where('resume_key', AiTaskQueue::resumeKey($job))->where('operation', $operation)
            ->whereIn('status', [AiTask::DONE, AiTask::FAILED])->latest('id')->first();
        if ($task === null) {
            return null;
        }
        $done = $task->status === AiTask::DONE;
        $task->forceFill(['status' => AiTask::CONSUMED, 'consumed_at' => now()])->save();

        return $done ? (array) $task->output : [];
    }

    /**
     * Puts the question on the queue for the subscription Claude (one open question per job: a question already
     * waiting is kept), and says when it will be answered.
     *
     * @param  array<string, mixed>  $payload
     */
    public function ask(object $job, Agent&RegistryPrompted&HasStructuredOutput $agent, array $payload, string $subject, ?string $why = null): string
    {
        $this->queue->begin($job, null, $subject);
        try {
            $this->queue->answer($agent, $payload, 'lead', 'LEAD_JSON');
        } finally {
            $this->queue->settle();
        }
        $round = $this->workers->nextRound(AiWorkers::CLAUDE_MCP);

        $reason = (string) ($this->workers->downReason(AiWorkers::CLAUDE_API) ?? 'Şu an yazamıyor');
        $why ??= 'Claude API: '.mb_strtolower(mb_substr($reason, 0, 1)).mb_substr($reason, 1);

        return $why.'; abonelikteki Claude '
            .($round !== null ? ($round->isToday() ? 'bugün ' : 'yarın ').$round->format('H:i').' turunda' : 'bir sonraki turunda').' yazacak.';
    }

    /** A question of this job still waiting is withdrawn (the lead changed; a new one is asked with today's data). */
    public static function withdraw(object $job): void
    {
        AiTask::query()->where('resume_key', AiTaskQueue::resumeKey($job))->whereIn('status', [AiTask::PENDING, AiTask::CLAIMED])
            ->update(['status' => AiTask::CONSUMED, 'consumed_at' => now()]);
    }
}
