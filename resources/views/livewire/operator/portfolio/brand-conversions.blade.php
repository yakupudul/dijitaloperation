<section class="space-y-4 rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div>
            <h2 class="font-semibold text-gray-800 dark:text-white/90">Dönüşümler (son 30 gün)</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Hesapların bildirdiği her dönüşüm sinyali burada; hangisinin ne anlama geldiğini ve toplama girip girmediğini sen belirlersin.
                Varsayılan: web sitesini GA4 sayar; Google Ads web/GA4 aktarımları ve Meta piksel olayları çift sayılmasın diye toplama girmez.
            </p>
        </div>
        <button type="button" wire:click="refresh" wire:loading.attr="disabled" class="rounded-lg px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Sinyalleri yenile</button>
    </div>

    @if ($message !== '')
        <p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $message }}</p>
    @endif

    @if ($rows->isEmpty())
        <p class="text-sm text-gray-500">Henüz dönüşüm sinyali yok. GA4, Google Ads, Meta veya İşletme Profili bağlanıp veri geldikten sonra dolar.</p>
    @else
        <div class="flex flex-wrap items-end gap-6">
            <div>
                <p class="text-xs text-gray-500">Toplam dönüşüm</p>
                <p class="text-2xl font-semibold text-gray-800 dark:text-white/90">{{ number_format($summary['current']['total'], 0, ',', '.') }}</p>
                <p class="text-xs text-gray-500">
                    Önceki 30 gün: {{ number_format($summary['previous']['total'], 0, ',', '.') }}
                    @if ($summary['change_pct'] !== null)
                        <span class="{{ $summary['change_pct'] >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">({{ $summary['change_pct'] >= 0 ? '+' : '' }}{{ $summary['change_pct'] }}%)</span>
                    @endif
                </p>
            </div>
            @foreach ($summary['current']['by_type'] as $type => $value)
                <div>
                    <p class="text-xs text-gray-500">{{ $types[$type] ?? $type }}</p>
                    <p class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ number_format($value, 0, ',', '.') }}</p>
                </div>
            @endforeach
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-gray-400">
                    <tr><th class="py-2 pr-3">Kaynak</th><th class="py-2 pr-3">Sinyal</th><th class="py-2 pr-3">Ne anlama geliyor</th><th class="py-2 pr-3 text-right">30 gün</th><th class="py-2">Toplama girer</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($rows as $row)
                        <tr wire:key="conversion-{{ $row->id }}" @class(['opacity-60' => ! $row->counts])>
                            <td class="py-2 pr-3 text-xs text-gray-500">{{ $sources[$row->source] ?? $row->source }}</td>
                            <td class="py-2 pr-3 text-gray-800 dark:text-gray-200">
                                {{ $row->label }}
                                @if ($row->origin === 'operator') <span class="text-xs text-gray-400">· elle ayarlandı</span> @endif
                            </td>
                            <td class="py-2 pr-3">
                                <select wire:change="setType({{ $row->id }}, $event.target.value)" class="rounded-lg border border-gray-200 bg-transparent px-2 py-1 text-xs dark:border-gray-700 dark:text-white">
                                    @foreach ($types as $value => $label)
                                        <option value="{{ $value }}" @selected($row->conversion_type === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ number_format($summary['current']['by_row'][$row->id] ?? 0, 0, ',', '.') }}</td>
                            <td class="py-2">
                                <button type="button" wire:click="toggleCounts({{ $row->id }})" role="switch" aria-checked="{{ $row->counts ? 'true' : 'false' }}"
                                    class="relative inline-flex h-5 w-9 items-center rounded-full {{ $row->counts ? 'bg-brand-500' : 'bg-gray-300 dark:bg-gray-700' }}">
                                    <span class="sr-only">Toplama girer</span>
                                    <span class="inline-block h-4 w-4 transform rounded-full bg-white transition {{ $row->counts ? 'translate-x-4' : 'translate-x-0.5' }}"></span>
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
