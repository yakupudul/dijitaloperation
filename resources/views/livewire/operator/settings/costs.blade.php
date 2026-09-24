@php
    $usd = fn (float $value): string => '$'.number_format($value, $value > 0 && $value < 1 ? 3 : 2, ',', '.');
    $monthLabel = fn (string $key): string => \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $key.'-01')->locale('tr')->translatedFormat('M Y');
    $current = end($costs['months']);
@endphp
<div class="space-y-5">
    <div>
        <a href="{{ route('operator.settings', ['section' => 'operations']) }}" wire:navigate class="text-sm text-gray-500 hover:text-brand-600">← Ayarlar</a>
        <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">Maliyetler</h1>
        <p class="mt-1 text-sm text-gray-500">Uygulamanın kaydettiği AI ve DataForSEO harcaması (USD). Sağlayıcı faturası değildir; Google ve Meta API'leri çağrı başına ücretlendirmez.</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs text-gray-500">Bu ay toplam</p>
            <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ $usd($costs['totals'][$current] ?? 0) }}</p>
        </section>
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs text-gray-500">Bu ay AI</p>
            <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ $usd(collect($costs['rows'])->where('group', 'ai')->sum(fn ($row) => $row['values'][$current])) }}</p>
            <p class="text-xs text-gray-500">Aylık AI bütçesi: {{ $costs['ai_budget'] !== null ? $usd($costs['ai_budget']) : 'tanımlı değil' }}</p>
            @if (($costs['brand_caps'] ?? []) !== [])
                <div class="mt-3">
                    <p class="text-xs font-semibold text-gray-600 dark:text-gray-300">DataForSEO marka tavanları (bu ay)</p>
                    @foreach ($costs['brand_caps'] as $cap)
                        <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ $cap['brand'] }}: {{ $usd($cap['spent']) }} / {{ $usd($cap['cap']) }} <span @class(['font-semibold', 'text-rose-600' => $cap['share'] >= 90, 'text-amber-600' => $cap['share'] >= 70 && $cap['share'] < 90])>(%{{ $cap['share'] }})</span></p>
                    @endforeach
                </div>
            @endif
        </section>
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs text-gray-500">Bu ay DataForSEO</p>
            <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ $usd(collect($costs['rows'])->where('group', 'dataforseo')->sum(fn ($row) => $row['values'][$current])) }}</p>
        </section>
    </div>

    <section class="overflow-x-auto rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-gray-400">
                <tr>
                    <th class="py-2 pr-3">Kalem</th>
                    @foreach ($costs['months'] as $month)<th class="py-2 pr-3 text-right">{{ $monthLabel($month) }}</th>@endforeach
                    <th class="py-2 text-right">Toplam</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($costs['rows'] as $row)
                    <tr>
                        <td class="py-2 pr-3 text-gray-800 dark:text-gray-200">{{ $row['label'] }}</td>
                        @foreach ($costs['months'] as $month)<td class="py-2 pr-3 text-right tabular-nums">{{ $row['values'][$month] > 0 ? $usd($row['values'][$month]) : '—' }}</td>@endforeach
                        <td class="py-2 text-right font-medium tabular-nums">{{ $usd($row['total']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ count($costs['months']) + 2 }}" class="py-3 text-gray-500">Henüz kayıtlı harcama yok.</td></tr>
                @endforelse
                <tr class="font-semibold">
                    <td class="py-2 pr-3">Toplam</td>
                    @foreach ($costs['months'] as $month)<td class="py-2 pr-3 text-right tabular-nums">{{ $usd($costs['totals'][$month]) }}</td>@endforeach
                    <td class="py-2 text-right tabular-nums">{{ $usd(array_sum($costs['totals'])) }}</td>
                </tr>
            </tbody>
        </table>
    </section>
</div>
