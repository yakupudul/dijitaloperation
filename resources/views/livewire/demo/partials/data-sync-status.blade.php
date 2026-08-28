@php
    $sync = is_array($syncStatus ?? null) ? $syncStatus : [];
    $syncAction = (string) ($action ?? 'refreshData');
    $syncButtonLabel = (string) ($buttonLabel ?? (app()->getLocale() === 'tr' ? 'Şimdi Güncelle' : 'Update Now'));
    $syncTitle = (string) ($title ?? (app()->getLocale() === 'tr' ? 'Veri Güncelliği' : 'Data Freshness'));
    $showProviders = (bool) ($showProviders ?? false);
    $compact = (bool) ($compact ?? false);
    $isTr = app()->getLocale() === 'tr';
    $state = (string) ($sync['state'] ?? 'unconfigured');
    $active = (bool) ($sync['active'] ?? false);
    $progress = isset($sync['progress_pct']) && is_numeric($sync['progress_pct']) ? (int) $sync['progress_pct'] : null;
    $dataThrough = $sync['data_through'] ?? null;
    $lastSuccess = $sync['last_success_at'] ?? null;

    $stateLabel = match ($state) {
        'current' => $isTr ? 'Veriler güncel' : 'Data is current',
        'due' => $isTr ? 'Güncelleme öneriliyor' : 'Update recommended',
        'queued' => $isTr ? 'Güncelleme sırada' : 'Update queued',
        'running' => $isTr ? 'Güncelleniyor' : 'Updating',
        'retrying' => $isTr ? 'Yeniden deneniyor' : 'Retrying',
        'partial' => $isTr ? 'Kısmen güncellendi' : 'Partially updated',
        'failed' => $isTr ? 'Güncelleme tamamlanamadı' : 'Update failed',
        'action_required' => $isTr ? 'Bağlantı kontrolü gerekli' : 'Connection check required',
        default => $isTr ? 'Veri kaynağı bağlı değil' : 'Data source not connected',
    };
    $dotClass = match ($state) {
        'current' => 'bg-emerald-500',
        'queued', 'running', 'retrying' => 'bg-blue-500',
        'due', 'partial' => 'bg-amber-500',
        'failed', 'action_required' => 'bg-rose-500',
        default => 'bg-gray-400',
    };
    $dateLabel = static function ($value) use ($isTr): ?string {
        if (!is_string($value) || $value === '') return null;
        try {
            $date = \Carbon\CarbonImmutable::parse($value);
            return $isTr ? $date->locale('tr')->translatedFormat('j M Y') : $date->format('M j, Y');
        } catch (\Throwable) {
            return $value;
        }
    };
    $timeLabel = static function ($value) use ($isTr): ?string {
        if (!is_string($value) || $value === '') return null;
        try {
            $time = \Carbon\CarbonImmutable::parse($value)->setTimezone(config('app.timezone', 'UTC'));
            return $isTr ? $time->locale('tr')->translatedFormat('j M H:i') : $time->format('M j H:i');
        } catch (\Throwable) {
            return null;
        }
    };
@endphp

<div @if($active) wire:poll.2s @endif @class([
    'rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900',
    'px-3 py-2.5' => $compact,
    'p-4' => !$compact,
])>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            @unless($compact)<p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">{{ $syncTitle }}</p>@endunless
            <div class="mt-0.5 flex items-center gap-2">
                <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $dotClass }} {{ $active ? 'animate-pulse' : '' }}"></span>
                <p class="truncate text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $stateLabel }}@if($active && $progress !== null) · %{{ $progress }}@endif</p>
            </div>
            @if($active)
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $sync['stage'] ?? ($isTr ? 'Veriler hazırlanıyor' : 'Preparing data') }}</p>
            @elseif($dataThrough)
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $isTr ? 'Veri: '.$dateLabel($dataThrough).' tarihine kadar' : 'Data through '.$dateLabel($dataThrough) }}</p>
            @elseif($lastSuccess)
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $isTr ? 'Son başarılı güncelleme: '.$timeLabel($lastSuccess) : 'Last successful update: '.$timeLabel($lastSuccess) }}</p>
            @endif
        </div>

        <button type="button" wire:click="{{ $syncAction }}" wire:loading.attr="disabled" wire:target="{{ $syncAction }}" @disabled($active || $state === 'action_required' || $state === 'unconfigured')
            class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-brand-500 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50">
            <svg wire:loading.class="animate-spin" wire:target="{{ $syncAction }}" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-width="2" d="M20 12a8 8 0 1 1-2.34-5.66M20 4v6h-6"/></svg>
            <span wire:loading.remove wire:target="{{ $syncAction }}">{{ $active ? ($isTr ? 'Güncelleniyor' : 'Updating') : $syncButtonLabel }}</span>
            <span wire:loading wire:target="{{ $syncAction }}">{{ $isTr ? 'Başlatılıyor…' : 'Starting…' }}</span>
        </button>
    </div>

    @if($active)
        <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/[0.06]">
            @if($progress !== null && ($sync['progress_determinate'] ?? false))
                <div class="h-full rounded-full bg-brand-500 transition-[width] duration-500" style="width: {{ max(2, min(100, $progress)) }}%"></div>
            @else
                <div class="h-full w-1/3 animate-pulse rounded-full bg-brand-500"></div>
            @endif
        </div>
        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-gray-400">
            @if(($sync['datasets_total'] ?? 0) > 0)<span>{{ $sync['datasets_completed'] ?? 0 }}/{{ $sync['datasets_total'] }} {{ $isTr ? 'veri grubu tamamlandı' : 'data groups completed' }}</span>@endif
            @if(($sync['rows_written'] ?? 0) > 0)<span>{{ number_format((int)$sync['rows_written']) }} {{ $isTr ? 'kayıt işlendi' : 'rows processed' }}</span>@endif
        </div>
    @endif

    @if($showProviders && !empty($sync['providers']))
        <div class="mt-3 grid gap-2 sm:grid-cols-2">
            @foreach($sync['providers'] as $provider)
                @php
                    $providerState = (string)($provider['state'] ?? 'current');
                    $providerActive = in_array($providerState, ['queued','running','retrying'], true) || $active;
                    $providerProgress = isset($provider['progress_pct']) ? (int)$provider['progress_pct'] : null;
                @endphp
                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">
                    <div class="flex items-center justify-between gap-2"><span class="text-xs font-semibold text-gray-700 dark:text-gray-300">{{ $provider['label'] }}</span>@if($providerProgress !== null && $active)<span class="text-[11px] font-semibold text-brand-600">%{{ $providerProgress }}</span>@else<span class="h-2 w-2 rounded-full {{ $providerState === 'failed' ? 'bg-rose-500' : ($providerState === 'due' ? 'bg-amber-500' : ($active ? 'bg-blue-500 animate-pulse' : 'bg-emerald-500')) }}"></span>@endif</div>
                    @if($active && !empty($provider['stage']))<p class="mt-1 truncate text-[11px] text-gray-400">{{ $provider['stage'] }}</p>@elseif(!empty($provider['data_through']))<p class="mt-1 text-[11px] text-gray-400">{{ $dateLabel($provider['data_through']) }}</p>@endif
                </div>
            @endforeach
        </div>
    @endif

    @if(!empty($sync['current_dataset']) || !empty($sync['run_ids']) || !empty($sync['error']))
        <details class="mt-3 border-t border-gray-100 pt-2 dark:border-gray-800">
            <summary class="cursor-pointer text-[11px] font-medium text-gray-400">{{ $isTr ? 'Teknik ayrıntılar' : 'Technical details' }}</summary>
            <div class="mt-2 space-y-1 font-mono text-[10px] text-gray-400">
                @if(!empty($sync['run_ids']))<p>Run: {{ implode(', ', $sync['run_ids']) }}</p>@endif
                @if(!empty($sync['current_dataset']))<p>{{ $sync['current_dataset'] }}{{ !empty($sync['technical_stage']) ? ' · '.$sync['technical_stage'] : '' }}</p>@endif
                @if(!empty($sync['error']))<p class="text-rose-500">{{ $sync['error'] }}</p>@endif
            </div>
        </details>
    @endif
</div>
