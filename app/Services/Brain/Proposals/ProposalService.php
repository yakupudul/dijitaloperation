<?php

namespace App\Services\Brain\Proposals;

use App\Jobs\PrepareBrainProposalsJob;
use App\Models\BrainProposal;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * The Brain review queue ("AI ile hazırla → toplu incele → onayla"). Preparation runs in the background and only
 * writes proposals; nothing changes until an operator approves. Approving applies each proposal in its own
 * transaction, so one failure does not undo the others. A rejected proposal is not offered again (same fingerprint).
 */
final class ProposalService
{
    /** @var array<string, ProposalKind> */
    private array $kinds = [];

    public function register(ProposalKind $kind): void
    {
        $this->kinds[$kind->kind()] = $kind;
    }

    public function kind(string $kind): ProposalKind
    {
        return $this->kinds[$kind] ?? throw new InvalidArgumentException("Unknown brain proposal kind [{$kind}]");
    }

    /** @return array<string, ProposalKind> */
    public function kinds(): array
    {
        return $this->kinds;
    }

    /**
     * Queue one proposal. Skipped when the same change is already pending, applied or was rejected; an older pending
     * proposal of the same kind for the same subject is superseded (stale).
     *
     * @param  array<string, mixed>|null  $current
     * @param  array<string, mixed>  $proposed
     */
    public function propose(string $kind, string $subjectType, int $subjectId, ?int $brandId, string $title, ?array $current, array $proposed, ?string $reason, ?float $confidence, string $source): ?BrainProposal
    {
        $fingerprint = hash('sha256', $kind.'|'.$subjectType.'|'.$subjectId.'|'.json_encode($proposed, JSON_UNESCAPED_UNICODE));
        $exists = BrainProposal::query()->where('fingerprint', $fingerprint)
            ->whereIn('status', [BrainProposal::STATUS_PENDING, BrainProposal::STATUS_APPLIED, BrainProposal::STATUS_REJECTED])->exists();
        if ($exists) {
            return null;
        }

        return DB::transaction(function () use ($kind, $subjectType, $subjectId, $brandId, $title, $current, $proposed, $reason, $confidence, $source, $fingerprint): BrainProposal {
            BrainProposal::query()->where('kind', $kind)->where('subject_type', $subjectType)->where('subject_id', $subjectId)
                ->where('status', BrainProposal::STATUS_PENDING)->update(['status' => BrainProposal::STATUS_STALE, 'updated_at' => now()]);

            return BrainProposal::query()->create([
                'kind' => $kind, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'brand_id' => $brandId,
                'title' => mb_substr($title, 0, 500), 'current' => $current, 'proposed' => $proposed,
                'reason' => $reason !== null ? mb_substr($reason, 0, 2000) : null,
                'confidence' => $confidence !== null ? round(max(0.0, min(1.0, $confidence)), 4) : null,
                'source' => $source, 'status' => BrainProposal::STATUS_PENDING, 'fingerprint' => $fingerprint,
            ]);
        });
    }

    /** Remember that a subject was examined and nothing fits, so preparation does not ask again. */
    public function nothing(string $kind, string $subjectType, int $subjectId, string $title, ?string $reason, string $source): void
    {
        BrainProposal::query()->create([
            'kind' => $kind, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'title' => mb_substr($title, 0, 500),
            'proposed' => [], 'reason' => $reason !== null ? mb_substr($reason, 0, 2000) : null, 'source' => $source,
            'status' => BrainProposal::STATUS_NOTHING, 'fingerprint' => hash('sha256', $kind.'|'.$subjectType.'|'.$subjectId.'|nothing'),
        ]);
    }

    /** Start preparing one kind in the background. */
    public function queue(string $kind, User $actor, array $options = []): void
    {
        abort_unless($actor->is_active, 403);
        $this->kind($kind);
        Cache::put($this->stateKey($kind), 'running', now()->addMinutes(30));
        PrepareBrainProposalsJob::dispatch($kind, $options);
    }

    public function prepare(string $kind, array $options = []): void
    {
        try {
            $count = $this->kind($kind)->prepare($options);
            Cache::put($this->stateKey($kind), 'done: '.$count, now()->addHours(6));
        } catch (Throwable $exception) {
            report($exception);
            Cache::put($this->stateKey($kind), 'failed: '.mb_substr($exception->getMessage(), 0, 200), now()->addHours(6));
        }
    }

    public function state(string $kind): ?string
    {
        $state = Cache::get($this->stateKey($kind));

        return is_string($state) ? $state : null;
    }

    /**
     * @param  list<int>  $ids
     * @return array{applied: int, failed: int}
     */
    public function approve(array $ids, User $actor): array
    {
        abort_unless($actor->is_active, 403);
        $applied = 0;
        $failed = 0;
        foreach ($this->pending($ids) as $proposal) {
            try {
                DB::transaction(function () use ($proposal, $actor): void {
                    $this->kind($proposal->kind)->apply($proposal, $actor);
                    $proposal->forceFill(['status' => BrainProposal::STATUS_APPLIED, 'decided_by' => $actor->id, 'decided_at' => now(), 'applied_at' => now(), 'error' => null])->save();
                });
                $applied++;
            } catch (Throwable $exception) {
                $message = $exception instanceof ValidationException
                    ? collect($exception->errors())->flatten()->first()
                    : $exception->getMessage();
                $proposal->forceFill(['status' => BrainProposal::STATUS_FAILED, 'decided_by' => $actor->id, 'decided_at' => now(), 'error' => mb_substr((string) $message, 0, 500)])->save();
                $failed++;
            }
        }

        return ['applied' => $applied, 'failed' => $failed];
    }

    /** @param  list<int>  $ids */
    public function reject(array $ids, User $actor): int
    {
        abort_unless($actor->is_active, 403);

        return BrainProposal::query()->whereIn('id', $ids)->where('status', BrainProposal::STATUS_PENDING)
            ->update(['status' => BrainProposal::STATUS_REJECTED, 'decided_by' => $actor->id, 'decided_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, BrainProposal>
     */
    private function pending(array $ids): Collection
    {
        return BrainProposal::query()->whereIn('id', array_map('intval', $ids))->where('status', BrainProposal::STATUS_PENDING)->orderBy('id')->get();
    }

    private function stateKey(string $kind): string
    {
        return 'brain-proposals:'.$kind;
    }
}
