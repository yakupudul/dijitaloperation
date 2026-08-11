@php
    $rows = $data['activity'] ?? [];
@endphp

<div class="mox-website-workspace">
    <div class="mox-section-head">
        <div>
            <h3 class="mox-section-title">Activity</h3>
            <p class="mox-section-sub">Collections and Meta Ads AI Guidance provenance (Core Run remains canonical).</p>
        </div>
    </div>

    <section class="mox-panel">
        @if ($rows === [])
            <div class="mox-empty">No activity yet.</div>
        @else
            <div class="mox-table-wrap">
                <table class="mox-table">
                    <thead>
                        <tr>
                            <th>Activity</th>
                            <th>Source / provenance</th>
                            <th>Status</th>
                            <th>Started</th>
                            <th>Duration</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td>
                                    <a href="{{ \App\Filament\App\Resources\Runs\RunResource::getUrl('view', ['record' => $row['id']]) }}">
                                        {{ $row['title'] }}
                                    </a>
                                </td>
                                <td>{{ $row['source'] }}</td>
                                <td>
                                    <span class="mox-status mox-status--{{ $row['status'] }}">{{ ucfirst($row['status']) }}</span>
                                </td>
                                <td>{{ $row['started_at']?->toDateTimeString() ?? '—' }}</td>
                                <td>{{ $row['duration'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
