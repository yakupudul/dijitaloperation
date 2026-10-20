@php
    $card = 'min-w-0';
    $tone = ['ok' => 'ok', 'warn' => 'look', 'bad' => 'critical', 'muted' => 'info'];
@endphp
<div class="min-w-0 text-sm dark:text-gray-200" data-linked-assets-tab>
    {{-- The brand's other accounts that feed or use this site. --}}
    <section class="{{ $card }}">
        <div class="section-head">
            <h2 class="section-title">Bağlı varlıklar</h2>
            <span class="section-count">{{ count($rows) }}</span>
        </div>
        <div class="card overflow-x-auto">
            <table class="data-table">
                <thead><tr><th>Tür</th><th>Hesap</th><th>Durum</th><th>Son veri</th><th class="w-10"></th></tr></thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr data-capability="{{ $row['capability'] }}">
                            <td class="whitespace-nowrap font-medium text-gray-900 dark:text-white">{{ $row['source'] }}</td>
                            <td class="min-w-[10rem]">{{ $row['name'] }}</td>
                            <td class="whitespace-nowrap"><x-ta.pill :tone="$tone[$row['tone']] ?? 'info'">{{ $row['state'] }}</x-ta.pill></td>
                            <td class="whitespace-nowrap text-[13px] tabular-nums text-gray-500">{{ $row['last'] ?? '—' }}</td>
                            <td class="text-right">@if ($row['url'])<a href="{{ $row['url'] }}" wire:navigate class="btn-quiet h-7! px-2! text-xs!">Aç</a>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-[13px] text-gray-500">{{ $hasBrand ? 'Bağlı varlık yok.' : 'Site bir markaya bağlı değil.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
