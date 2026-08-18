<div
    class="mox-collection-monitor"
    data-testid="collection-monitoring-panel"
    @if ($this->pollingInterval)
        wire:poll.{{ $this->pollingInterval }}="refreshActive"
    @endif
>
    <div class="mox-section-head">
        <div>
            <h2 class="mox-section-title">{{ __('operator.collection.title') }}</h2>
            <p class="mox-section-sub">{{ __('operator.collection.subtitle') }}</p>
        </div>
        <div class="mox-collection-monitor__actions">
            <x-filament::button size="sm" color="gray" wire:click="reloadStatus" wire:key="reload-status">
                {{ __('operator.collection.refresh_status') }}
            </x-filament::button>
        </div>
    </div>

    @if ($statusError)
        <div class="mox-collection-alert" role="status" aria-live="polite" data-testid="collection-status-error">
            {{ $statusError }}
        </div>
    @endif

    {{-- Active runs --}}
    <section class="mox-collection-active" aria-labelledby="collection-active-heading">
        <h3 id="collection-active-heading" class="mox-integrations-group__title">
            {{ __('operator.collection.active_heading') }}
        </h3>

        @forelse ($activeRuns as $run)
            <article
                class="mox-collection-run-card"
                data-testid="active-run-{{ $run['uuid'] }}"
                wire:key="active-{{ $run['uuid'] }}"
            >
                <div class="mox-collection-run-card__top">
                    <div>
                        <span class="mox-collection-status mox-collection-status--{{ $run['status']['tone'] }}" aria-label="{{ $run['status']['label'] }}">
                            <span aria-hidden="true">●</span> {{ $run['status']['label'] }}
                        </span>
                        <div class="mox-muted">
                            {{ implode(' · ', $run['providers'] ?? []) }}
                            @if (! empty($run['elapsed']))
                                · {{ __('operator.collection.elapsed') }}: {{ $run['elapsed'] }}
                            @endif
                        </div>
                    </div>
                    <x-filament::button size="sm" color="primary" wire:click="selectRun('{{ $run['uuid'] }}')">
                        {{ __('operator.collection.open_detail') }}
                    </x-filament::button>
                </div>

                @if (! empty($run['exceptions']))
                    <ul class="mox-collection-exceptions" data-testid="exceptions-{{ $run['uuid'] }}">
                        @foreach ($run['exceptions'] as $ex)
                            <li class="mox-collection-exceptions__item mox-collection-exceptions__item--{{ $ex['kind'] }}">
                                {{ $ex['label'] }}
                            </li>
                        @endforeach
                    </ul>
                @endif

                <ul class="mox-collection-resource-rows">
                    @foreach ($run['resources'] ?? [] as $resource)
                        <li class="mox-collection-resource-row" wire:key="res-{{ $resource['uuid'] }}">
                            <span class="mox-collection-resource-row__name">{{ $resource['provider_label'] }}</span>
                            <span class="mox-collection-status mox-collection-status--{{ $resource['status']['tone'] }}">
                                {{ $resource['status']['label'] }}
                            </span>
                            @if (($resource['plan_completion']['percentage'] ?? null) !== null)
                                <span
                                    class="mox-collection-pct"
                                    title="{{ $resource['plan_completion']['label'] }}"
                                    aria-label="{{ $resource['plan_completion']['label'] }}: {{ $resource['plan_completion']['completed'] }} / {{ $resource['plan_completion']['total'] }}"
                                >
                                    {{ rtrim(rtrim(number_format($resource['plan_completion']['percentage'], 1), '0'), '.') }}%
                                    <span class="mox-muted">{{ __('operator.collection.dataset_plan_abbr') }}</span>
                                </span>
                            @else
                                <span class="mox-muted">{{ $resource['plan_completion']['completed'] }}/{{ $resource['plan_completion']['total'] }}</span>
                            @endif
                            @if (($resource['datasets_retrying'] ?? 0) > 0)
                                <span class="mox-collection-status mox-collection-status--amber">
                                    {{ trans_choice('operator.collection.exceptions.retrying', $resource['datasets_retrying'], ['count' => $resource['datasets_retrying']]) }}
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>

                <div class="mox-collection-run-card__meta mox-muted">
                    {{ __('operator.collection.records_stored') }}:
                    {{ number_format($run['summary']['rows_written'] ?? 0) }}
                    · {{ __('operator.collection.datasets') }}:
                    {{ $run['summary']['datasets_completed'] }}/{{ $run['summary']['datasets_total'] }}
                </div>
            </article>
        @empty
            <p class="mox-muted" data-testid="collection-active-empty">{{ __('operator.collection.active_empty') }}</p>
        @endforelse
    </section>

    {{-- Detail drawer --}}
    @if ($selectedDetail)
        <section class="mox-collection-detail" data-testid="collection-run-detail" aria-labelledby="collection-detail-heading">
            <div class="mox-section-head">
                <div>
                    <h3 id="collection-detail-heading" class="mox-integrations-group__title">
                        {{ __('operator.collection.detail_heading') }}
                    </h3>
                    <span class="mox-collection-status mox-collection-status--{{ $selectedDetail['status']['tone'] }}">
                        ● {{ $selectedDetail['status']['label'] }}
                    </span>
                </div>
                <div class="mox-collection-monitor__actions">
                    @if (! ($selectedDetail['is_terminal'] ?? true))
                        <x-filament::button
                            size="sm"
                            color="danger"
                            wire:click="cancelSelected"
                            wire:confirm="{{ __('operator.collection.cancel_confirm') }}"
                        >
                            {{ __('operator.collection.cancel') }}
                        </x-filament::button>
                    @endif
                    <x-filament::button size="sm" color="gray" wire:click="toggleTechnical">
                        {{ __('operator.collection.technical') }}
                    </x-filament::button>
                    <x-filament::button size="sm" color="gray" wire:click="clearSelection">
                        {{ __('operator.collection.close') }}
                    </x-filament::button>
                </div>
            </div>

            <div class="mox-collection-detail__summary">
                <div>{{ __('operator.collection.elapsed') }}: {{ $selectedDetail['elapsed'] ?? '—' }}</div>
                <div>{{ __('operator.collection.records_stored') }}: {{ number_format($selectedDetail['summary']['rows_written'] ?? 0) }}</div>
                <div>
                    {{ __('operator.collection.received_written') }}:
                    {{ number_format($selectedDetail['summary']['rows_received'] ?? 0) }}
                    /
                    {{ number_format($selectedDetail['summary']['rows_written'] ?? 0) }}
                </div>
                @if (! empty($selectedDetail['summary']['plan_completion']))
                    <div title="{{ $selectedDetail['summary']['plan_completion']['label'] }}">
                        {{ __('operator.collection.plan_completion') }}:
                        {{ $selectedDetail['summary']['plan_completion']['completed'] }}/{{ $selectedDetail['summary']['plan_completion']['total'] }}
                        @if (($selectedDetail['summary']['plan_completion']['success_only'] ?? false))
                            (100%)
                        @elseif (($selectedDetail['summary']['plan_completion']['percentage'] ?? null) !== null)
                            ({{ rtrim(rtrim(number_format($selectedDetail['summary']['plan_completion']['percentage'], 1), '0'), '.') }}%)
                        @endif
                    </div>
                @endif
            </div>

            @if (! empty($selectedDetail['materialization']))
                <div class="mox-collection-materialization" data-testid="materialization-panel">
                    <strong>{{ __('operator.collection.materialization.heading') }}</strong>
                    <div>
                        {{ __('operator.collection.materialization.latest_refresh') }}:
                        {{ $selectedDetail['materialization']['latest_run_status']['label'] ?? '—' }}
                    </div>
                    <div>
                        {{ __('operator.collection.materialization.existing_data') }}:
                        {{ $selectedDetail['materialization']['pool']['label'] ?? '—' }}
                        @if (! empty($selectedDetail['materialization']['pool']['coverage_end_date']))
                            · {{ __('operator.collection.materialization.through') }}
                            {{ $selectedDetail['materialization']['pool']['coverage_end_date'] }}
                        @endif
                    </div>
                    @if (! empty($selectedDetail['materialization']['note']))
                        <p class="mox-muted">{{ $selectedDetail['materialization']['note'] }}</p>
                    @endif
                </div>
            @endif

            @foreach ($selectedDetail['resources'] ?? [] as $resource)
                <div class="mox-collection-resource-block" wire:key="detail-res-{{ $resource['uuid'] }}">
                    <h4>
                        {{ $resource['provider_label'] }}
                        <span class="mox-collection-status mox-collection-status--{{ $resource['status']['tone'] }}">
                            {{ $resource['status']['label'] }}
                        </span>
                        @if (($resource['plan_completion']['percentage'] ?? null) !== null)
                            <span class="mox-collection-pct" title="{{ $resource['plan_completion']['label'] }}">
                                {{ rtrim(rtrim(number_format($resource['plan_completion']['percentage'], 1), '0'), '.') }}%
                                <span class="mox-muted">{{ __('operator.collection.dataset_plan_abbr') }}</span>
                            </span>
                        @endif
                    </h4>

                    <ul class="mox-collection-dataset-list">
                        @foreach ($resource['datasets'] ?? [] as $dataset)
                            <li class="mox-collection-dataset-row" data-testid="dataset-{{ $dataset['uuid'] }}" wire:key="ds-{{ $dataset['uuid'] }}">
                                <div class="mox-collection-dataset-row__main">
                                    <strong>{{ $dataset['display_name'] }}</strong>
                                    <span class="mox-collection-status mox-collection-status--{{ $dataset['status']['tone'] }}">
                                        ● {{ $dataset['status']['label'] }}
                                    </span>
                                </div>
                                <div class="mox-muted mox-collection-dataset-row__progress">
                                    @php $p = $dataset['progress'] ?? []; @endphp
                                    @if (($p['allows_percentage'] ?? false) && ($p['percentage'] ?? null) !== null)
                                        <span
                                            role="progressbar"
                                            aria-valuenow="{{ (int) $p['percentage'] }}"
                                            aria-valuemin="0"
                                            aria-valuemax="100"
                                        >
                                            {{ $p['current'] }}/{{ $p['total'] }}
                                            ({{ rtrim(rtrim(number_format($p['percentage'], 1), '0'), '.') }}%)
                                        </span>
                                    @elseif (($p['type'] ?? '') === 'INDETERMINATE')
                                        <span role="status" aria-busy="true">
                                            {{ __('operator.collection.collecting') }}
                                            · {{ number_format($p['rows_written'] ?? 0) }}
                                            {{ __('operator.collection.records') }}
                                        </span>
                                    @elseif (($p['type'] ?? '') === 'STAGE_BASED')
                                        {{ $p['stage'] ?? __('operator.collection.collecting') }}
                                    @else
                                        {{ number_format($p['rows_written'] ?? 0) }} {{ __('operator.collection.records') }}
                                    @endif
                                    · {{ __('operator.collection.attempt') }} {{ $dataset['attempt_count'] }}
                                    @if (! empty($dataset['max_attempts']))
                                        / {{ $dataset['max_attempts'] }}
                                    @endif
                                </div>
                                @if (! empty($dataset['error']['message']) || ($dataset['status']['key'] ?? '') === 'retrying')
                                    <div class="mox-collection-dataset-error">
                                        <strong>{{ $dataset['error']['title'] ?? '' }}</strong>
                                        @if (! empty($dataset['error']['message']))
                                            — {{ $dataset['error']['message'] }}
                                        @endif
                                        @if ($dataset['error']['automatic_retry'] ?? false)
                                            <span class="mox-collection-status mox-collection-status--amber">
                                                {{ __('operator.collection.retrying_automatically') }}
                                                @if (! empty($dataset['retry_at']))
                                                    · {{ __('operator.collection.retry_at') }} {{ $dataset['retry_at'] }}
                                                @endif
                                            </span>
                                        @elseif ($dataset['error']['operator_action_required'] ?? false)
                                            <span class="mox-collection-status mox-collection-status--rose">
                                                {{ __('operator.collection.action_required') }}
                                            </span>
                                        @endif
                                        @if (($dataset['status']['key'] ?? '') === 'failed')
                                            <x-filament::button size="xs" color="gray" wire:click="retryDataset('{{ $dataset['uuid'] }}')">
                                                {{ __('operator.collection.retry_failed') }}
                                            </x-filament::button>
                                        @endif
                                    </div>
                                @endif
                                @if (($dataset['status']['key'] ?? '') === 'completed' && (int) ($dataset['progress']['rows_written'] ?? 0) === 0)
                                    <div class="mox-muted">{{ __('operator.collection.completed_zero_records') }}</div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach

            @if ($showTechnical && ! empty($selectedDetail['technical']))
                <dl class="mox-collection-technical" data-testid="collection-technical">
                    @foreach ($selectedDetail['technical'] as $k => $v)
                        <div><dt>{{ $k }}</dt><dd><code>{{ $v }}</code></dd></div>
                    @endforeach
                </dl>
            @endif
        </section>
    @endif

    {{-- History --}}
    <section class="mox-collection-history" aria-labelledby="collection-history-heading">
        <div class="mox-section-head">
            <h3 id="collection-history-heading" class="mox-integrations-group__title">
                {{ __('operator.collection.history_heading') }}
            </h3>
            <select class="mox-collection-filter" wire:model.live="historyStatus" aria-label="{{ __('operator.collection.filter_status') }}">
                <option value="">{{ __('operator.collection.filter_all') }}</option>
                <option value="completed">{{ __('operator.collection.status.completed') }}</option>
                <option value="partial">{{ __('operator.collection.status.partial') }}</option>
                <option value="failed">{{ __('operator.collection.status.failed') }}</option>
                <option value="cancelled">{{ __('operator.collection.status.cancelled') }}</option>
            </select>
        </div>

        @if ($history->isEmpty())
            <p class="mox-muted" data-testid="collection-history-empty">{{ __('operator.collection.history_empty') }}</p>
        @else
            <div class="mox-collection-history-table-wrap">
                <table class="mox-collection-history-table">
                    <thead>
                        <tr>
                            <th>{{ __('operator.collection.col.started') }}</th>
                            <th>{{ __('operator.collection.col.status') }}</th>
                            <th>{{ __('operator.collection.col.providers') }}</th>
                            <th>{{ __('operator.collection.col.duration') }}</th>
                            <th>{{ __('operator.collection.col.datasets') }}</th>
                            <th>{{ __('operator.collection.col.records') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($history as $row)
                            <tr wire:key="hist-{{ $row['uuid'] }}" data-testid="history-run-{{ $row['uuid'] }}">
                                <td>{{ $row['started_at'] ? \Illuminate\Support\Carbon::parse($row['started_at'])->toDayDateTimeString() : '—' }}</td>
                                <td>
                                    <span class="mox-collection-status mox-collection-status--{{ $row['status']['tone'] }}">
                                        {{ $row['status']['label'] }}
                                    </span>
                                </td>
                                <td>{{ implode(', ', $row['providers'] ?? []) }}</td>
                                <td>{{ $row['elapsed'] ?? '—' }}</td>
                                <td>{{ $row['summary']['datasets_completed'] }}/{{ $row['summary']['datasets_total'] }}</td>
                                <td>{{ number_format($row['summary']['rows_written'] ?? 0) }}</td>
                                <td>
                                    <x-filament::button size="xs" color="gray" wire:click="selectRun('{{ $row['uuid'] }}')">
                                        {{ __('operator.collection.open_detail') }}
                                    </x-filament::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mox-collection-pagination">
                {{ $history->links() }}
            </div>
        @endif
    </section>
</div>
