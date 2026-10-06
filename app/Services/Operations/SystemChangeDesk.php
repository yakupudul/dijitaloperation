<?php

namespace App\Services\Operations;

use App\Jobs\Operations\RunScreenChecksJob;
use App\Models\SystemChange;
use App\Models\User;
use App\Services\Queries\QueryNotifier;
use App\Support\Roles;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Geliştirme havuzu steps, shared by the page (operator) and the MCP tools (Claude):
 * propose (Claude, deduplicated by fingerprint) · approve / reject / write a request (operator) · start / ready with
 * commit + deploy commands (Claude) · deployed (operator, or seen live by its release SHA) · verified / failed (Claude).
 */
final class SystemChangeDesk
{
    public const string BRANCH = 'claude/project-thread-e5yimf';

    /**
     * @param  array{kind: string, title: string, detail: string, evidence?: ?list<string>, priority?: ?int, fingerprint?: ?string}  $input
     * @return array{change: SystemChange, created: bool}
     */
    public function propose(array $input): array
    {
        $fingerprint = hash('sha256', (string) ($input['fingerprint'] ?? '') !== '' ? (string) $input['fingerprint'] : $input['kind'].'|'.mb_strtolower(trim($input['title'])));
        $existing = SystemChange::query()->where('fingerprint', $fingerprint)->first();
        if ($existing !== null) {
            if ($existing->status === SystemChange::PROPOSED) {
                $existing->forceFill(['detail' => trim($input['detail']), 'evidence' => $input['evidence'] ?? $existing->evidence])->save();
            }

            return ['change' => $existing, 'created' => false];
        }

        return ['change' => SystemChange::query()->create([
            'kind' => $input['kind'], 'title' => trim($input['title']), 'detail' => trim($input['detail']),
            'evidence' => array_values($input['evidence'] ?? []) ?: null, 'priority' => (int) ($input['priority'] ?? 2),
            'fingerprint' => $fingerprint, 'source' => 'claude', 'status' => SystemChange::PROPOSED,
        ]), 'created' => true];
    }

    /** The operator writes a request themselves: it is approved at once. */
    public function request(string $kind, string $title, string $detail, User $actor): SystemChange
    {
        return SystemChange::query()->create([
            'kind' => $kind, 'title' => trim($title), 'detail' => trim($detail), 'priority' => 2, 'source' => 'operator',
            'status' => SystemChange::APPROVED, 'decided_by' => $actor->id, 'decided_at' => now(),
        ]);
    }

    public function approve(SystemChange $change, User $actor, ?string $note = null): void
    {
        $this->expect($change, [SystemChange::PROPOSED, SystemChange::FAILED]);
        $change->forceFill(['status' => SystemChange::APPROVED, 'decided_by' => $actor->id, 'decided_at' => now(),
            'operator_note' => $this->clean($note) ?? $change->operator_note])->save();
    }

    public function reject(SystemChange $change, User $actor, ?string $note = null): void
    {
        $this->expect($change, [SystemChange::PROPOSED, SystemChange::APPROVED, SystemChange::FAILED]);
        $change->forceFill(['status' => SystemChange::REJECTED, 'decided_by' => $actor->id, 'decided_at' => now(),
            'operator_note' => $this->clean($note) ?? $change->operator_note])->save();
    }

    public function start(SystemChange $change, ?string $note = null): void
    {
        $this->expect($change, [SystemChange::APPROVED]);
        $change->forceFill(['status' => SystemChange::IN_PROGRESS, 'work_note' => $this->clean($note) ?? $change->work_note])->save();
    }

    /** Back to the operator (cannot be done as asked / needs a decision): proposed again with Claude's reason. */
    public function giveBack(SystemChange $change, string $note): void
    {
        $this->expect($change, [SystemChange::APPROVED, SystemChange::IN_PROGRESS]);
        $change->forceFill(['status' => SystemChange::PROPOSED, 'work_note' => $this->clean($note)])->save();
    }

    public function ready(SystemChange $change, string $commit, string $deployCommands, ?string $note = null, ?string $branch = null): void
    {
        $this->expect($change, [SystemChange::APPROVED, SystemChange::IN_PROGRESS]);
        if (preg_match('/^[0-9a-f]{7,40}$/', $commit) !== 1) {
            throw ValidationException::withMessages(['commit' => 'Commit SHA geçersiz.']);
        }
        if (mb_strlen(trim($deployCommands)) < 10) {
            throw ValidationException::withMessages(['deploy_commands' => 'Deploy komutları boş.']);
        }
        $change->forceFill(['status' => SystemChange::READY, 'commit_sha' => $commit, 'branch' => $branch ?: self::BRANCH,
            'deploy_commands' => trim($deployCommands), 'work_note' => $this->clean($note) ?? $change->work_note, 'ready_at' => now()])->save();
        $this->notifyAdmins('Deploy hazır: '.$change->title, $commit);
    }

    /**
     * The operator deployed the commit: every ready change of that commit or an older one (they ship together) is
     * deployed; the screens are checked again so Claude verifies against the live system.
     *
     * @return int changes marked
     */
    public function deployed(string $commit): int
    {
        $marked = SystemChange::query()->where('status', SystemChange::READY)->where('commit_sha', $commit)
            ->update(['status' => SystemChange::DEPLOYED, 'deployed_at' => now()]);
        if ($marked > 0) {
            RunScreenChecksJob::dispatch();
        }

        return $marked;
    }

    /**
     * Changes already in the live release: ready ones whose commit is live (deployed without marking), and approved /
     * in-progress / ready ones whose id a live commit names ("pool #N"; the coding round could not move them).
     */
    public function markLiveReleases(): int
    {
        $release = ReleaseInfo::current();
        $sha = $release['sha'];
        if ($sha === null) {
            return 0;
        }
        $marked = 0;
        foreach (SystemChange::query()->where('status', SystemChange::READY)->pluck('commit_sha')->filter()->unique() as $commit) {
            if (str_starts_with($sha, (string) $commit) || str_starts_with((string) $commit, $sha)) {
                $marked += $this->deployed((string) $commit);
            }
        }
        $pool = $release['pool'] ?? [];
        if ($pool !== []) {
            $named = 0;
            foreach (SystemChange::query()->whereIn('id', $pool)->whereIn('status', [SystemChange::APPROVED, SystemChange::IN_PROGRESS, SystemChange::READY])->get() as $change) {
                $change->forceFill(['status' => SystemChange::DEPLOYED, 'deployed_at' => now(), 'commit_sha' => $change->commit_sha ?? substr($sha, 0, 40),
                    'work_note' => $change->work_note ?? 'Sizin deploy\'unuzla canlıya çıktı (sürüm '.substr($sha, 0, 8).'); kodu Claude dala yazmıştı.'])->save();
                $named++;
            }
            if ($named > 0) {
                RunScreenChecksJob::dispatch();
            }
            $marked += $named;
        }

        return $marked;
    }

    public function verify(SystemChange $change, bool $ok, string $note): void
    {
        $this->expect($change, [SystemChange::DEPLOYED, SystemChange::READY]);
        $change->forceFill(['status' => $ok ? SystemChange::VERIFIED : SystemChange::FAILED, 'verified_at' => now(),
            'verify_note' => $this->clean($note), 'deployed_at' => $change->deployed_at ?? now()])->save();
        if (! $ok) {
            $this->notifyAdmins('Deploy sonrası kontrolde sorun: '.$change->title, null);
        }
    }

    /** @return Collection<string, int> status => count */
    public function counts(): Collection
    {
        return SystemChange::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n): int => (int) $n);
    }

    /** @param  list<string>  $allowed */
    private function expect(SystemChange $change, array $allowed): void
    {
        if (! in_array($change->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => 'Bu adım şu durumda yapılamaz: '.(SystemChange::STATUS_LABELS[$change->status] ?? $change->status).'.']);
        }
    }

    private function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : mb_substr($text, 0, 4000);
    }

    private function notifyAdmins(string $title, ?string $commit): void
    {
        try {
            $notifier = app(QueryNotifier::class);
            foreach (User::query()->where('is_active', true)->role(Roles::ADMIN)->pluck('id') as $id) {
                $notifier->send((int) $id, $title, route('operator.settings.improvements', $commit !== null ? ['durum' => 'deploy'] : [], false));
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
