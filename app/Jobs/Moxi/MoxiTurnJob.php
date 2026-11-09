<?php

namespace App\Jobs\Moxi;

use App\Models\MoxiMessage;
use App\Services\Moxi\MoxiAgent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One Moxi answer, off the web request (a turn with tool rounds can take half a minute); the corner chat polls until the
 * answer line appears. On the "writes" lane, so long background work never makes the operator wait.
 */
final class MoxiTurnJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public int $messageId)
    {
        $this->onQueue('writes');
    }

    public function handle(MoxiAgent $agent): void
    {
        $message = MoxiMessage::query()->find($this->messageId);
        if ($message === null || $message->role !== MoxiMessage::USER || $message->status !== MoxiMessage::PENDING) {
            return;
        }
        $agent->turn($message);
    }

    public function failed(?\Throwable $error): void
    {
        $message = MoxiMessage::query()->find($this->messageId);
        if ($message !== null && $message->status === MoxiMessage::PENDING) {
            $message->forceFill(['status' => MoxiMessage::FAILED])->save();
            MoxiMessage::query()->create(['user_id' => $message->user_id, 'role' => MoxiMessage::MOXI, 'page' => $message->page,
                'body' => 'Bu sefer cevap yetiştiremedim. Yeniden yazar mısın?', 'meta' => ['error' => true]]);
        }
    }
}
