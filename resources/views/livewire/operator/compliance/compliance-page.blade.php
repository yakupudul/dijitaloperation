@php
    $select = 'rounded-lg border border-gray-200 bg-transparent px-3 py-1.5 text-sm dark:border-gray-700 dark:text-white';
    $statusLabels = ['open' => 'Açık', 'resolved' => 'Çözüldü', 'dismissed' => 'Yok sayıldı'];
@endphp
<div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Uyum</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500">Sektör paketi kurallarına takılan içerikler: AI taslakları, SEO briefleri, yayındaki Meta reklam metinleri ve hedeflemesi, site sayfaları, İşletme Profili. Kurallar taslaktır; hukuk görüşüyle <a href="{{ route('operator.settings.sector-packs') }}" wire:navigate class="text-brand-600 hover:underline">Sektör paketleri</a> ekranından düzenlenir. Google Ads reklam metinleri henüz toplanmadığı için taranmıyor.</p>
        </div>
        <button type="button" wire:click="scanNow" wire:loading.attr="disabled" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-600">Şimdi tara</button>
    </div>

    @if ($message !== '')
        <p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $message }}</p>
    @endif

    <div class="flex flex-wrap gap-2">
        <select wire:model.live="brand" class="{{ $select }}"><option value="">Tüm markalar</option>@foreach ($brands as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
        <select wire:model.live="source" class="{{ $select }}"><option value="">Tüm kaynaklar</option>@foreach ($sources as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
        <select wire:model.live="status" class="{{ $select }}"><option value="">Tüm durumlar</option>@foreach ($statusLabels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
    </div>

    <section class="divide-y divide-gray-100 rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:divide-gray-800 dark:bg-gray-900 dark:ring-gray-800">
        @forelse ($findings as $finding)
            <article wire:key="cf-{{ $finding->id }}" class="p-4" x-data="{ note: '' }">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs text-gray-500">
                            <span @class(['rounded px-1.5 py-0.5 font-semibold', 'bg-rose-50 text-rose-700' => $finding->rule?->severity === 'high', 'bg-amber-50 text-amber-700' => $finding->rule?->severity === 'medium', 'bg-gray-100 text-gray-600' => $finding->rule?->severity === 'low'])>{{ $finding->rule?->label }}</span>
                            {{ $finding->brand?->name }} · {{ $sources[$finding->source] ?? $finding->source }} · {{ $finding->subject_label }}
                        </p>
                        <p class="mt-1 text-sm text-gray-800 dark:text-gray-200">“{{ $finding->excerpt }}”</p>
                        <p class="mt-1 text-xs text-gray-600 dark:text-gray-400"><strong>{{ $finding->matched }}</strong> — {{ $finding->rule?->message }}</p>
                        @if ($finding->note)<p class="mt-1 text-xs text-gray-400">Not: {{ $finding->note }}</p>@endif
                        @if (str_starts_with($finding->subject_ref, 'page:'))
                            <a href="{{ substr($finding->subject_ref, 5) }}" target="_blank" rel="noopener" class="mt-1 inline-block text-xs text-brand-600 hover:underline">Sayfayı aç ↗</a>
                        @elseif (str_starts_with($finding->subject_ref, 'AdvisorItem:') || str_starts_with($finding->subject_ref, 'SeoTask:'))
                            <a href="{{ route('operator.archive', ['subject' => $finding->subject_ref]) }}" wire:navigate class="mt-1 inline-block text-xs text-brand-600 hover:underline">Üretim Arşivi'nde aç →</a>
                        @endif
                    </div>
                    <div class="flex items-center gap-2 text-xs">
                        <span class="text-gray-500">{{ $statusLabels[$finding->status] ?? $finding->status }} · {{ $finding->last_seen_at?->timezone('Europe/Istanbul')->format('d.m.Y') }}</span>
                        @if ($finding->status === 'open')
                            <input type="text" x-model="note" placeholder="Neden? (isteğe bağlı)" class="w-40 rounded border border-gray-200 bg-transparent px-2 py-1 dark:border-gray-700" />
                            <button type="button" x-on:click="$wire.dismiss({{ $finding->id }}, note)" class="rounded px-2 py-1 ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Yok say</button>
                        @elseif ($finding->status === 'dismissed')
                            <button type="button" wire:click="reopen({{ $finding->id }})" class="rounded px-2 py-1 ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Yeniden aç</button>
                        @endif
                    </div>
                </div>
            </article>
        @empty
            <p class="p-5 text-sm text-gray-500">{{ $hasPacks ? 'Bu filtrede bulgu yok.' : 'Sektör paketi tanımlı değil.' }} Sağlık sektöründeki markalar (Sağlık, Diş sağlığı, Medikal estetik) her gün taranır.</p>
        @endforelse
    </section>
    {{ $findings->links() }}
</div>
