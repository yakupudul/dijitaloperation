@php
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $tone = ['ok' => 'bg-emerald-50 text-emerald-700', 'warn' => 'bg-amber-50 text-amber-700', 'bad' => 'bg-rose-50 text-rose-700', 'muted' => 'bg-gray-100 text-gray-600'];
@endphp
<div class="space-y-4 text-sm dark:text-gray-200" data-linked-assets-tab>
    <section class="{{ $card }}">
        <table class="w-full text-left text-xs">
            <thead class="text-gray-500"><tr><th class="py-1">Tür</th><th>Hesap</th><th class="w-36">Durum</th><th class="w-24">Son veri</th><th class="w-10"></th></tr></thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr class="border-t border-gray-100 dark:border-gray-800" data-capability="{{ $row['capability'] }}">
                        <td class="py-1.5 font-medium">{{ $row['source'] }}</td>
                        <td>{{ $row['name'] }}</td>
                        <td><span class="rounded-full px-2 py-0.5 {{ $tone[$row['tone']] ?? $tone['muted'] }}">{{ $row['state'] }}</span></td>
                        <td>{{ $row['last'] ?? '—' }}</td>
                        <td>@if ($row['url'])<a href="{{ $row['url'] }}" wire:navigate class="text-brand-600 hover:underline">Aç</a>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-2 text-gray-500">{{ $hasBrand ? 'Bağlı varlık yok.' : 'Site bir markaya bağlı değil.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
