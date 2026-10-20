@php
    $card = 'min-w-0';
    $input = 'h-8 rounded-lg border border-gray-200 bg-white px-2.5 text-[13px] text-gray-700 shadow-card focus:border-gray-300 focus:ring-2 focus:ring-gray-200 dark:border-gray-700 dark:bg-gray-900 dark:text-white';
    $tone = ['ok' => 'ok', 'warn' => 'look', 'critical' => 'critical', 'unknown' => 'info'];
    $label = ['ok' => 'iyi', 'warn' => 'uyarı', 'critical' => 'kritik', 'unknown' => '—'];
@endphp
<div class="min-w-0 text-sm dark:text-gray-200" data-health-tab>
    {{-- Is the site up and are SSL, domain, hosting and WordPress in date? --}}
    <div class="section-head justify-between">
        <h2 class="section-title">Site Sağlığı</h2>
        <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
            <button type="button" wire:click="checkNow" class="btn flex-1 sm:flex-none">SSL / alan adı kontrol et</button>
            @if ($wordpress && $isAdmin)
                <form method="POST" action="{{ route('operator.integrations.wordpress-login', ['site' => $site->id]) }}" target="_blank" class="flex-1 sm:flex-none" data-wp-login>
                    @csrf
                    <button type="submit" class="btn w-full">WordPress'e giriş</button>
                </form>
            @endif
        </div>
    </div>

    @if ($message !== '')
        <p role="status" class="mb-3 rounded-lg border border-gray-200 bg-white px-4 py-2.5 text-sm text-gray-800 shadow-card dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-200">{{ $message }}</p>
    @endif

    <div class="card">
        <ul>
            @foreach ($rows as $row)
                <li class="card-row flex-wrap sm:flex-nowrap" data-row="{{ $row['key'] }}" wire:key="h-{{ $row['key'] }}">
                    <div class="min-w-0 flex-[1_1_16rem]">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $row['label'] }}</p>
                        @if (filled($row['line']))<p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $row['line'] }}</p>@endif
                    </div>
                    <span class="flex shrink-0 items-center gap-4">
                        @if ($row['date'])<span class="text-xs tabular-nums text-gray-500">{{ $row['date'] }}</span>@endif
                        <span class="sm:w-16 sm:text-right">@if ($row['state'] === 'unknown')<span class="text-xs text-gray-400" data-state="unknown">bilinmiyor</span>@else<x-ta.pill :tone="$tone[$row['state']]" data-state="{{ $row['state'] }}">{{ $label[$row['state']] }}</x-ta.pill>@endif</span>
                    </span>
                </li>
            @endforeach
        </ul>
        <form wire:submit="saveHosting" class="flex flex-wrap items-center gap-2 border-t border-gray-200 bg-gray-50/70 px-4 py-3 dark:border-gray-800 dark:bg-white/[0.02]">
            <label for="hosting-date" class="text-[13px] text-gray-600 dark:text-gray-300">Hosting bitiş tarihi</label>
            <input id="hosting-date" type="date" wire:model="hostingDate" class="{{ $input }}">
            <button type="submit" class="btn">Kaydet</button>
            @error('hostingDate')<span class="text-xs text-error-600">{{ $message }}</span>@enderror
        </form>
    </div>
</div>
