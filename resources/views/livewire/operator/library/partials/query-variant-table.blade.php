<div class="overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead class="border-b border-gray-200 text-xs text-gray-500 dark:border-gray-800"><tr><th class="py-2 pr-3 font-normal">Sorgu</th><th class="px-3 py-2 font-normal">Hesap</th><th class="px-3 py-2 text-right font-normal">Gösterim / tık</th><th class="py-2 pl-3 text-right font-normal">Ads maliyet</th></tr></thead>
        <tbody class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
            @forelse($rows as $row)
                <tr wire:key="v-{{ $row->id }}"><td class="py-2.5 pr-3">{{ $row->raw_text }}</td>
                    <td class="px-3 py-2.5 text-xs text-gray-500">{{ $sources[$row->source] ?? $row->source }}, {{ $row->resource?->display_name ?? $row->resource?->external_id ?? '—' }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ number_format($row->impressions, 0, ',', '.') }} / {{ number_format($row->clicks, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ $row->cost > 0 ? number_format($row->cost, 2, ',', '.') : '—' }}</td></tr>
            @empty
                <tr><td colspan="4" class="px-3 py-6 text-center text-gray-500">{{ $empty }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@if($rows->hasPages()){{ $rows->links() }}@endif
