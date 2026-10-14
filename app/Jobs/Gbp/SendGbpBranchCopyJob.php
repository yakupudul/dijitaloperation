<?php

namespace App\Jobs\Gbp;

use App\Models\ClaudeNote;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Gbp\Desk\GbpDesk;
use App\Services\Gbp\GbpProfileCopier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * moxdop:gbp:copy-profile --send for one target branch while Claude writes the descriptions over MCP: the job waits for
 * Claude (it runs again once Claude answered), then records the plan and sends it as the Admin who ran the command
 * (ADR-077, unchanged). What the branch lacks is fixed when the command runs, so a re-run sends exactly that. A send
 * that fails is kept as a Claude note on the brand (a queued run has no screen; `--report` lists the sends).
 */
final class SendGbpBranchCopyJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    /** @param  array{categories: list<array<string, mixed>>, services: list<array<string, mixed>>, skipped: list<array{line: string, reason: string}>, primary: array{id: string, name: string}}  $missing */
    public function __construct(public int $userId, public int $sourceId, public int $targetId, public array $missing) {}

    public function handle(GbpProfileCopier $copier, AiTaskQueue $tasks): void
    {
        $source = DigitalAsset::query()->find($this->sourceId);
        $target = DigitalAsset::query()->with('brand')->find($this->targetId);
        $user = User::query()->where('is_active', true)->find($this->userId);
        if ($source === null || $target === null || $user === null) {
            return;
        }
        $tasks->begin(new self($this->userId, $this->sourceId, $this->targetId, $this->missing), $target->brand_id !== null ? (int) $target->brand_id : null,
            'İşletme Profili şube kopyası · '.GbpDesk::shortName((string) $source->name).' → '.GbpDesk::shortName((string) $target->name));
        try {
            $copier->send($user, $source, $target, $this->missing);
        } catch (Throwable $exception) {
            ClaudeNote::query()->create(['brand_id' => $target->brand_id, 'kind' => 'observation', 'status' => ClaudeNote::OPEN,
                'text' => GbpDesk::shortName((string) $source->name).' kopyası gönderilemedi ('.now()->format('Y-m-d H:i')."):\n"
                    .GbpDesk::shortName((string) $target->name).' (#'.$target->id.'): '.mb_substr($exception->getMessage(), 0, 300)]);
        } finally {
            $tasks->settle();
        }
    }
}
