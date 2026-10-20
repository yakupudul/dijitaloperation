<div class="card overflow-x-auto">
    <table class="data-table">
        <thead><tr><th>Sorgu</th><th>Hesap</th><th class="text-right">Gösterim / tık</th><th class="text-right">Ads maliyet</th></tr></thead>
        <tbody>
            @forelse($rows as $row)
                <tr wire:key="v-{{ $row->id }}"><td class="font-medium text-gray-900 dark:text-white">{{ $row->raw_text }}</td>
                    <td class="text-[13px] text-gray-500">{{ $sources[$row->source] ?? $row->source }}, {{ $row->resource?->display_name ?? $row->resource?->external_id ?? '—' }}</td>
                    <td class="whitespace-nowrap text-right tabular-nums">{{ number_format($row->impressions, 0, ',', '.') }} / {{ number_format($row->clicks, 0, ',', '.') }}</td>
                    <td class="whitespace-nowrap text-right tabular-nums">{{ $row->cost > 0 ? number_format($row->cost, 2, ',', '.') : '—' }}</td></tr>
            @empty
                <tr><td colspan="4" class="py-6! text-center text-gray-500">{{ $empty }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@if($rows->hasPages())<div class="mt-3">{{ $rows->links() }}</div>@endif
