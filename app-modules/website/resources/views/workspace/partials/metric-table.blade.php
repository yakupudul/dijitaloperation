@php
    $rows = $rows ?? [];
    $columns = $columns ?? [];
@endphp

@if ($rows === [])
    <div class="mox-empty mox-empty--compact">No rows available.</div>
@else
    <div class="mox-table-wrap">
        <table class="mox-table">
            <thead>
                <tr>
                    @foreach ($columns as $column)
                        <th>{{ $column['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        @foreach ($columns as $column)
                            @php
                                $key = $column['key'];
                                $raw = data_get($row, $key);
                                $format = $column['format'] ?? 'text';
                                $value = match ($format) {
                                    'number' => is_numeric($raw) ? number_format((float) $raw, abs((float) $raw - round((float) $raw)) < 0.0001 ? 0 : 2) : '—',
                                    'percent_ratio' => is_numeric($raw) ? number_format(((float) $raw) * 100, 2).'%' : '—',
                                    'position' => is_numeric($raw) ? number_format((float) $raw, 1) : '—',
                                    default => filled($raw) ? (string) $raw : '—',
                                };
                            @endphp
                            <td class="{{ ($column['class'] ?? '') }}">{{ $value }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
