@php
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $num = fn ($value) => number_format((float) $value, 0, ',', '.');
    $delta = $clicks !== null && $clicks['prev'] > 0 ? (int) round(($clicks['clicks'] - $clicks['prev']) / $clicks['prev'] * 100) : null;
@endphp
<div class="space-y-4" data-overview>
    @foreach ($banners as $bannerState => $group)
        <section class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200" data-data-status-banner="{{ $bannerState }}">
            <span>{{ __('data_status.banner.'.$bannerState.'_title', ['sources' => $group->map(fn ($status) => $status->sourceLabel())->implode(' / ')], 'tr') }}</span>
            @if ($bannerState === 'not_bound')
                <a href="{{ route('operator.asset.sources', ['assetId' => $this->assetId]) }}" wire:navigate class="rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white">{{ __('data_status.actions.bind', [], 'tr') }}</a>
            @endif
        </section>
    @endforeach
    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" data-numbers>
        <div class="{{ $card }}">
            <p class="text-xs text-gray-500">Sayfa</p>
            <p class="text-2xl font-semibold tabular-nums">{{ $num($pageTotal) }}</p>
            <p class="text-xs text-gray-500">
                @foreach (\App\Models\Page::CATEGORY_LABELS as $key => $label)@if (($categories[$key] ?? 0) > 0){{ $label }} {{ $categories[$key] }} · @endif @endforeach
                @if ($unassignedCount > 0)sınıflanmamış {{ $unassignedCount }}@endif
            </p>
        </div>
        <div class="{{ $card }}">
            <p class="text-xs text-gray-500">Küme kapsama</p>
            <p class="text-2xl font-semibold tabular-nums">{{ $coverageTotal > 0 ? '%'.(int) round($coverageOk / $coverageTotal * 100) : '—' }}</p>
            <p class="text-xs text-gray-500">{{ $coverageOk }} / {{ $coverageTotal }} küme yeterli</p>
        </div>
        <div class="{{ $card }}">
            <p class="text-xs text-gray-500">Açık öneri</p>
            <p class="text-2xl font-semibold tabular-nums">{{ $num($openCount) }}</p>
        </div>
        <div class="{{ $card }}">
            <p class="text-xs text-gray-500">Organik tıklama · 28 gün</p>
            <p class="text-2xl font-semibold tabular-nums">{{ $clicks !== null ? $num($clicks['clicks']) : '—' }}</p>
            <p class="text-xs {{ $delta !== null && $delta < 0 ? 'text-rose-600' : 'text-gray-500' }}">{{ $clicks === null ? 'Search Console verisi yok' : ($delta === null ? 'önceki 28 gün yok' : ($delta >= 0 ? '+' : '').$delta.'% önceki 28 güne göre') }}</p>
        </div>
        <div class="{{ $card }}">
            <p class="text-xs text-gray-500">Son içerik</p>
            <p class="text-2xl font-semibold tabular-nums">{{ $lastContent ?? '—' }}</p>
        </div>
        <div class="{{ $card }}">
            <p class="text-xs text-gray-500">Son veri</p>
            <p class="text-2xl font-semibold tabular-nums">{{ $lastData ?? '—' }}</p>
        </div>
    </section>

    <section class="{{ $card }}" data-top-suggestions>
        <h2 class="mb-2 text-sm font-semibold">Açık öneriler</h2>
        <ul class="divide-y divide-gray-100 text-xs dark:divide-gray-800">
            @forelse ($top as $suggestion)
                <li class="flex items-center justify-between gap-3 py-2" wire:key="top-{{ $suggestion->id }}">
                    <span><span class="font-medium">{{ $suggestion->title }}</span> <span class="text-gray-500">· {{ \App\Services\Site\SiteSuggestionTypes::label((string) $suggestion->action_type) }}@if ($suggestion->reason) · {{ $suggestion->reason }}@endif</span></span>
                    <button type="button" wire:click="$parent.setSub('oneriler')" class="rounded-lg px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 dark:text-gray-300 dark:ring-gray-700">Aç</button>
                </li>
            @empty
                <li class="py-2 text-gray-500">Açık öneri yok.</li>
            @endforelse
        </ul>
    </section>
</div>
