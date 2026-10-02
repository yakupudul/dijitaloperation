<div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
    <table class="w-full text-left text-sm">
        <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-gray-950"><tr><th class="px-3 py-2">Sorgu</th><th class="px-3 py-2">Hesap</th><th class="px-3 py-2 text-right">Gösterim / tık</th><th class="px-3 py-2 text-right">Ads maliyet</th></tr></thead>
        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse($rows as $row)
                <tr wire:key="v-{{ $row->id }}"><td class="px-3 py-2">{{ $row->raw_text }}</td>
                    <td class="px-3 py-2 text-xs">{{ $sources[$row->source] ?? $row->source }} · {{ $row->resource?->display_name ?? $row->resource?->external_id ?? '—' }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ number_format($row->impressions, 0, ',', '.') }} / {{ number_format($row->clicks, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ $row->cost > 0 ? number_format($row->cost, 2, ',', '.') : '—' }}</td></tr>
            @empty
                <tr><td colspan="4" class="px-3 py-6 text-center text-gray-500">{{ $empty }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@if($rows->hasPages()){{ $rows->links() }}@endif
