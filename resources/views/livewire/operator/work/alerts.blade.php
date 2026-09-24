@php
    $card = 'rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $select = 'rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900';
    $labels = ['critical' => 'Kritik', 'high' => 'Yüksek', 'medium' => 'Orta', 'low' => 'Düşük'];
@endphp
<div class="space-y-5">
    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Uyarılar</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">Tüm varlıklardaki açık uyarılar (harcama sıçraması, dönüşüm durması, trafik düşüşü, eski veri, site erişilemiyor…). Durum düzelince uyarı kendiliğinden kapanır; şimdilik ilgilenmeyeceğiniz uyarıyı sessize alabilirsiniz.</p>
    </div>
    @if ($message !== '')<p role="status" class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $message }}</p>@endif

    <div class="grid gap-3 sm:grid-cols-4">
        @foreach ($labels as $key => $label)
            <button type="button" wire:click="$set('severity', '{{ $severity === $key ? '' : $key }}')" @class([$card, 'text-left', 'ring-2 ring-brand-500' => $severity === $key])>
                <p class="text-xs text-gray-500">{{ $label }}</p>
                <p class="mt-1 text-2xl font-semibold {{ in_array($key, ['critical', 'high'], true) && ($counts[$key] ?? 0) > 0 ? 'text-rose-600' : 'text-gray-800 dark:text-white/90' }}">{{ (int) ($counts[$key] ?? 0) }}</p>
            </button>
        @endforeach
    </div>

    <section class="{{ $card }}">
        <div class="flex flex-wrap items-center gap-2">
            <select wire:model.live="show" aria-label="Görünüm" class="{{ $select }}">
                <option value="active">Açık</option>
                <option value="snoozed">Sessizde ({{ $snoozedCount }})</option>
                <option value="resolved">Son 30 günde kapanan</option>
            </select>
            <select wire:model.live="brand" aria-label="Marka" class="{{ $select }}"><option value="">Tüm markalar</option>@foreach ($brands as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
            <select wire:model.live="severity" aria-label="Önem" class="{{ $select }}"><option value="">Tüm önemler</option>@foreach ($labels as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
        </div>
        <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($alerts as $alert)
                <li wire:key="alert-{{ $alert->id }}" class="flex flex-wrap items-start justify-between gap-3 py-3">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $alert->title }}</p>
                        <p class="text-xs text-gray-500">{{ $alert->brand?->name ?? '—' }} · {{ $alert->digitalAsset?->name ?? '—' }} · ilk görülme {{ $alert->first_detected_at?->format('d.m.Y') }}@if ($alert->resolved_at) · kapandı {{ $alert->resolved_at->format('d.m.Y') }}@endif</p>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $alert->message }}</p>
                        @if ($alert->isSnoozed())<p class="mt-1 text-xs text-amber-700">{{ $alert->snoozed_until->format('d.m.Y') }} tarihine kadar sessizde</p>@endif
                        @if (isset($causeInsights[$alert->id]) && ($causeFor === $alert->id || $causeInsights[$alert->id]['production'] || $causeInsights[$alert->id]['state']))
                            <x-operator.ai-insight :insight="$causeInsights[$alert->id]" :compact="true" class="mt-3" />
                        @endif
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <x-ta.badge :color="$alert->severityColor()" size="sm">{{ $alert->severityLabel() }}</x-ta.badge>
                        @if ($alert->resolved_at === null && ! (isset($causeInsights[$alert->id]) && ($causeFor === $alert->id || $causeInsights[$alert->id]['production'])))
                            <button type="button" wire:click="$set('causeFor', {{ $alert->id }})" class="rounded border border-violet-300 px-2 py-0.5 text-xs text-violet-700 hover:bg-violet-50 dark:border-violet-500/30 dark:text-violet-300">✨ Olası neden</button>
                        @endif
                        @if ($url = $assetUrl($alert))<a href="{{ $url }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">Varlığı aç</a>@endif
                        @if ($alert->resolved_at === null)
                            @if ($alert->isSnoozed())
                                <button type="button" wire:click="unsnooze({{ $alert->id }})" class="text-xs text-gray-600 hover:underline">Sessizden çıkar</button>
                            @else
                                @foreach (\App\Livewire\Operator\Work\AlertsPage::SNOOZE_DAYS as $days)
                                    <button type="button" wire:click="snooze({{ $alert->id }}, {{ $days }})" class="rounded border border-gray-300 px-2 py-0.5 text-xs text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300">{{ $days }} gün sustur</button>
                                @endforeach
                            @endif
                        @endif
                    </div>
                </li>
            @empty
                <li class="py-6 text-center text-sm text-gray-500">Bu görünümde uyarı yok.</li>
            @endforelse
        </ul>
        <div class="mt-3">{{ $alerts->links() }}</div>
    </section>
</div>
