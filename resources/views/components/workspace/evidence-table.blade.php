@props(['columns' => [], 'rows' => [], 'title' => 'Kanıt', 'open' => false])
{{-- Kanıt: the numbers behind a card or a Durum number, collapsed by default. --}}
<details {{ $attributes->merge(['class' => 'group']) }} @if ($open) open @endif data-workspace-evidence>
    <summary class="cursor-pointer list-none text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $title }} <span class="group-open:hidden">▸</span><span class="hidden group-open:inline">▾</span></summary>
    @if ($rows === [] || $columns === [])
        <p class="mt-2 text-xs text-gray-500">Veri yok.</p>
    @else
        <div class="mt-2 overflow-x-auto">
            <table class="min-w-full text-left text-xs">
                <thead class="text-gray-400">
                    <tr>
                        @foreach ($columns as $label)
                            <th class="whitespace-nowrap px-2 py-1 font-medium">{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-gray-700 dark:divide-gray-800 dark:text-gray-300">
                    @foreach ($rows as $row)
                        <tr>
                            @foreach (array_keys($columns) as $key)
                                @php($cell = $row[$key] ?? null)
                                <td class="max-w-[16rem] truncate whitespace-nowrap px-2 py-1">{{ is_bool($cell) ? ($cell ? '✓' : '—') : (is_float($cell) ? str_replace('.', ',', (string) round($cell, 1)) : (is_int($cell) ? number_format($cell, 0, ',', '.') : ($cell ?? '—'))) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</details>
