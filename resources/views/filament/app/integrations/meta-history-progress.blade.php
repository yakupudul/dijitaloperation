@php
    /** @var \App\Models\Run|null $run */
    $meta = $run?->metadata ?? [];
    $status = $run?->status ?? 'queued';
    $active = in_array($status, ['queued', 'running'], true);

    $accountsDone = data_get($meta, 'accounts_done');
    $accountsTotal = data_get($meta, 'accounts_total');

    $rows = [];
    if (is_numeric($accountsTotal)) {
        $rows[] = [
            'label' => 'Ad Accounts imported',
            'done' => is_numeric($accountsDone) ? $accountsDone : 0,
            'total' => $accountsTotal,
        ];
    }

    $elapsed = null;
    if ($run?->started_at !== null) {
        $elapsed = $run->started_at->diffForHumans(
            $run->finished_at ?? now(),
            ['parts' => 2, 'short' => true, 'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE],
        );
    }
@endphp

@if ($run !== null)
    <div @if ($active) wire:poll.10s @endif>
        <x-moxdop.operation-progress
            :title="data_get($meta, 'human_title', 'Meta history import')"
            :phase="data_get($meta, 'phase_label')"
            :rows="$rows"
            :elapsed="$elapsed"
            :status="$status"
        />
    </div>
@endif
