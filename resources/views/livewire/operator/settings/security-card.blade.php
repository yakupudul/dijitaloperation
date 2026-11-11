@php
    $dot = ['ok' => 'bg-success-500', 'warn' => 'bg-warning-500', 'bad' => 'bg-error-500'];
    $answer = ['ok' => 'text-gray-700 dark:text-gray-300', 'warn' => 'text-warning-700 dark:text-warning-300', 'bad' => 'text-error-700 dark:text-error-300'];
    $open = $totals['bad'] + $totals['warn'];
@endphp
<div class="space-y-10 dark:text-gray-200" data-security-card>
    <header>
        <h1 class="page-title">Güvenlik karnesi</h1>
        <p class="page-sub">{{ $open === 0 ? 'Her satır yerinde.' : ($totals['bad'] > 0 ? $totals['bad'].' satır kırmızı'.($totals['warn'] > 0 ? ', '.$totals['warn'].' satır sarı.' : '.') : $totals['warn'].' satır sarı, kırmızı yok.') }} Panele kim girebiliyor, sunucu kaybolursa ne olur.</p>
    </header>

    <ul class="card -mt-4">
        @foreach ($rows as $row)
            <li class="card-row flex-wrap items-start gap-y-2 sm:flex-nowrap" wire:key="security-{{ $row['key'] }}" data-security-row="{{ $row['key'] }}" data-tone="{{ $row['tone'] }}">
                <span class="mt-[7px] h-2 w-2 shrink-0 rounded-full {{ $dot[$row['tone']] }}" aria-hidden="true"></span>
                <div class="min-w-0 flex-1 basis-64">
                    <p class="flex flex-wrap items-baseline gap-x-2.5 gap-y-0.5">
                        <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $row['title'] }}</span>
                        <span class="text-[13px] {{ $answer[$row['tone']] }}">{{ $row['answer'] }}</span>
                    </p>
                    <p class="mt-0.5 max-w-[75ch] text-[13px] text-gray-500 dark:text-gray-400">{{ $row['detail'] }}</p>
                    @if ($row['lines'] !== [])
                        <ul class="mt-1.5 space-y-0.5 text-[13px] text-gray-600 dark:text-gray-300">
                            @foreach ($row['lines'] as $line)<li>{{ $line }}</li>@endforeach
                        </ul>
                    @endif
                </div>
                @if ($row['url'] !== null && $row['action'] !== null)
                    <a href="{{ $row['url'] }}" wire:navigate class="btn w-full sm:w-auto">{{ $row['action'] }}</a>
                @endif
            </li>
        @endforeach
    </ul>
</div>
