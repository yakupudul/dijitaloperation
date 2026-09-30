<?php

namespace App\Livewire\Operator;

use App\Models\AiLiveOperation;
use App\Models\User;
use App\Services\Ai\AiLiveOperations;
use App\Services\AiJobs\AiJobTracker;
use App\Services\Prompts\PromptRegistry;
use App\Support\Ai\AiOperationLabels;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * AI işleri: one place for running, queued and past AI work (agent calls and queued AI jobs) with filters (status,
 * operation, user, date) and a detail panel (purpose, prompt version, the input sent, the output or error, provider /
 * model, tokens, cost, timing, job, the page that uses the result). Everyone signed in sees it; only Admin stops
 * ("Durdur": removes a queued job, or asks a running one to stop between AI calls) and deletes history.
 */
#[Layout('operator.layouts.app')]
#[Title('AI işleri')]
final class AiJobsPage extends Component
{
    use WithPagination;

    public const string TIMEZONE = 'Europe/Istanbul';

    #[Url(as: 'durum')]
    public string $status = '';

    #[Url(as: 'islem')]
    public string $operation = '';

    #[Url(as: 'kullanici')]
    public string $user = '';

    #[Url(as: 'baslangic')]
    public string $from = '';

    #[Url(as: 'bitis')]
    public string $to = '';

    /** Detail panel: the row id. */
    #[Url(as: 'is')]
    public ?int $detail = null;

    /** @var list<int|string> rows ticked for "Seçilenleri sil" */
    public array $selected = [];

    public string $olderThanDays = '30';

    public function updating(string $property): void
    {
        if (in_array($property, ['status', 'operation', 'user', 'from', 'to'], true)) {
            $this->resetPage();
            $this->selected = [];
        }
    }

    public function show(int $id): void
    {
        $this->detail = $id;
    }

    public function closeDetail(): void
    {
        $this->detail = null;
    }

    public function resetFilters(): void
    {
        $this->status = $this->operation = $this->user = $this->from = $this->to = '';
        $this->selected = [];
        $this->resetPage();
    }

    /** "Durdur" (Admin): removes a queued job, or asks a running one to stop between its AI calls. */
    public function stop(int $id, AiJobTracker $jobs): void
    {
        $this->authorizeAdmin();
        $row = AiLiveOperation::query()->find($id);
        $message = $row !== null ? $jobs->cancel($row, auth()->user()) : null;
        session()->flash('ai-jobs-status', $message ?? 'Bu iş artık durdurulamaz (bitmiş ya da bir sayfa isteğinin parçası).');
    }

    /** "Seçilenleri sil" (Admin): finished rows only; a job's calls go with it. */
    public function deleteSelected(): void
    {
        $this->authorizeAdmin();
        $ids = array_values(array_filter(array_map('intval', $this->selected), fn (int $id): bool => $id > 0));
        if ($ids === []) {
            session()->flash('ai-jobs-status', 'Silinecek kayıt seçilmedi.');

            return;
        }
        $deleted = $this->deleteRows(AiLiveOperation::query()->whereIn('id', $ids));
        $this->selected = [];
        session()->flash('ai-jobs-status', $deleted.' kayıt silindi.'.($deleted < count($ids) ? ' Çalışan / sıradaki işler silinmez; önce durdurun.' : ''));
    }

    /** "N günden eski kayıtları sil" (Admin): finished rows started before the cutoff. */
    public function deleteOlder(): void
    {
        $this->authorizeAdmin();
        $this->validate(['olderThanDays' => ['required', 'integer', 'min:1', 'max:3650']], [], ['olderThanDays' => 'Gün']);
        $deleted = $this->deleteRows(AiLiveOperation::query()->where('started_at', '<', now()->subDays((int) $this->olderThanDays)));
        session()->flash('ai-jobs-status', $deleted.' kayıt silindi ('.(int) $this->olderThanDays.' günden eski).');
    }

    public function render(AiLiveOperations $live, PromptRegistry $registry): View
    {
        $live->sweepStale();
        $rows = $this->filtered()->orderByRaw('case when status in (?, ?) then 0 else 1 end', [AiLiveOperation::RUNNING, AiLiveOperation::QUEUED])
            ->orderByDesc('started_at')->orderByDesc('id')->paginate(25);
        $detail = $this->detail !== null ? $this->detailData($registry) : null;
        $userIds = collect($rows->items())->pluck('user_id')->merge([$detail['row']->user_id ?? null])->filter()->unique()->values()->all();
        $open = collect($rows->items())->contains(fn (AiLiveOperation $row): bool => $row->isOpen()) || ($detail !== null && $detail['row']->isOpen());

        return view('livewire.operator.ai-jobs', [
            'rows' => $rows,
            'detailData' => $detail,
            'users' => $userIds === [] ? [] : User::query()->whereIn('id', $userIds)->pluck('name', 'id')->all(),
            'userOptions' => User::query()->orderBy('name')->pluck('name', 'id')->all(),
            'operations' => $this->operationOptions(),
            'statuses' => AiLiveOperation::STATUS_LABELS,
            'isAdmin' => self::isAdmin(),
            'polling' => $open,
            'retentionDays' => (int) (config('moxdop-retention.telemetry.ai_live_operations')[1] ?? 30),
        ]);
    }

    /** @return Builder<AiLiveOperation> */
    private function filtered(): Builder
    {
        $query = AiLiveOperation::query()->whereNull('parent_id');
        if (isset(AiLiveOperation::STATUS_LABELS[$this->status])) {
            $query->where('status', $this->status);
        }
        if ($this->operation !== '') {
            $query->where('operation', $this->operation);
        }
        if (ctype_digit($this->user)) {
            $query->where('user_id', (int) $this->user);
        }
        if (($from = self::day($this->from)) !== null) {
            $query->where('started_at', '>=', $from->startOfDay()->utc());
        }
        if (($to = self::day($this->to)) !== null) {
            $query->where('started_at', '<=', $to->endOfDay()->utc());
        }

        return $query;
    }

    /**
     * @return array{row: AiLiveOperation, parent: ?AiLiveOperation, calls: EloquentCollection<int, AiLiveOperation>, purpose: ?string, version: ?int, link: ?string, stoppable: bool, users: array<int, string>}|null
     */
    private function detailData(PromptRegistry $registry): ?array
    {
        $row = AiLiveOperation::query()->with('promptVersion:id,operation,version')->find($this->detail);
        if ($row === null) {
            return null;
        }
        $parent = $row->parent_id !== null ? AiLiveOperation::query()->find($row->parent_id) : null;
        $calls = $row->isJob()
            ? AiLiveOperation::query()->where('parent_id', $row->id)->orderBy('started_at')->orderBy('id')->limit(200)->get()
            : new EloquentCollection;
        $purpose = null;
        try {
            if ($row->operation !== null && $registry->has($row->operation)) {
                $purpose = trim((string) ($registry->definition($row->operation)['purpose'] ?? '')) ?: null;
            }
        } catch (Throwable) {
            // Purpose is informational.
        }
        $userIds = collect([$row->user_id, $row->cancel_requested_by, $parent?->user_id])->filter()->unique()->values()->all();

        return [
            'row' => $row,
            'parent' => $parent,
            'calls' => $calls,
            'purpose' => $purpose,
            'version' => $row->promptVersion?->version,
            'link' => $row->link ?? $parent?->link,
            'stoppable' => AiJobTracker::stoppable($row),
            'users' => $userIds === [] ? [] : User::query()->whereIn('id', $userIds)->pluck('name', 'id')->all(),
        ];
    }

    /** @return array<string, string> operation => label (operations present in the history) */
    private function operationOptions(): array
    {
        $options = [];
        foreach (AiLiveOperation::query()->whereNull('parent_id')->whereNotNull('operation')->distinct()->orderBy('operation')->pluck('operation') as $operation) {
            $options[(string) $operation] = AiOperationLabels::for((string) $operation);
        }
        asort($options);

        return $options;
    }

    /** @param  Builder<AiLiveOperation>  $query */
    private function deleteRows(Builder $query): int
    {
        $ids = $query->whereNotIn('status', [AiLiveOperation::RUNNING, AiLiveOperation::QUEUED])->pluck('id')->all();
        if ($ids === []) {
            return 0;
        }
        $deleted = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            AiLiveOperation::query()->whereIn('parent_id', $chunk)->whereNotIn('status', [AiLiveOperation::RUNNING, AiLiveOperation::QUEUED])->delete();
            $deleted += AiLiveOperation::query()->whereIn('id', $chunk)->delete();
        }
        if ($this->detail !== null && in_array($this->detail, $ids, true)) {
            $this->detail = null;
        }

        return $deleted;
    }

    private static function day(string $value): ?Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        try {
            return Carbon::createFromFormat('Y-m-d', $value, self::TIMEZONE) ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function isAdmin(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasRole(Roles::ADMIN);
    }

    private function authorizeAdmin(): void
    {
        abort_unless(self::isAdmin(), 403);
    }
}
