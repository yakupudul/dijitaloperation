@php
    $card = 'min-w-0';
    $tone = ['ok' => 'ok', 'warn' => 'look', 'bad' => 'critical', 'muted' => 'info'];
@endphp
<div class="space-y-10 text-sm dark:text-gray-200" data-linked-assets-tab>
    <section class="{{ $card }}">
        <div class="min-w-0 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-gray-200 text-gray-500 dark:border-gray-800"><tr><th class="font-normal py-1">Tür</th><th class="font-normal">Hesap</th><th class="font-normal w-36">Durum</th><th class="font-normal w-24">Son veri</th><th class="font-normal w-10"></th></tr></thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr class="border-b border-gray-200 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03]" data-capability="{{ $row['capability'] }}">
                        <td class="py-2.5 font-medium">{{ $row['source'] }}</td>
                        <td>{{ $row['name'] }}</td>
                        <td><x-ta.pill :tone="$tone[$row['tone']] ?? 'info'">{{ $row['state'] }}</x-ta.pill></td>
                        <td>{{ $row['last'] ?? '—' }}</td>
                        <td>@if ($row['url'])<a href="{{ $row['url'] }}" wire:navigate class="link-neutral text-xs">Aç</a>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-2 text-gray-500">{{ $hasBrand ? 'Bağlı varlık yok.' : 'Site bir markaya bağlı değil.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </section>
</div>
