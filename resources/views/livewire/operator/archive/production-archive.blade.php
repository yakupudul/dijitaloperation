@php
    $select = 'rounded-lg border border-gray-200 bg-transparent px-3 py-1.5 text-sm dark:border-gray-700 dark:text-white';
@endphp
<div class="space-y-5">
    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Üretim Arşivi</h1>
        <p class="mt-1 text-sm text-gray-500">AI'ın hazırladığı her metin sürüm olarak burada kalır; yeniden üretmek eskisini silmez. Kullandıklarını ve yayınladıklarını işaretle, iyi/kötü diye puanla.</p>
    </div>

    <div class="flex flex-wrap gap-2">
        <select wire:model.live="kind" class="{{ $select }}">
            <option value="">Tüm türler</option>
            @foreach ($kinds as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
        <select wire:model.live="status" class="{{ $select }}">
            <option value="">Tüm durumlar</option>
            @foreach ($statusLabels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
        <select wire:model.live="brand" class="{{ $select }}">
            <option value="">Tüm markalar</option>
            @foreach ($brands as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
        </select>
        @if ($subject !== '')
            <button type="button" wire:click="$set('subject', '')" class="rounded-lg px-3 py-1.5 text-sm text-brand-600 ring-1 ring-inset ring-brand-200">Tek iş filtresini kaldır ×</button>
        @endif
    </div>

    <section class="divide-y divide-gray-100 rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:divide-gray-800 dark:bg-gray-900 dark:ring-gray-800">
        @forelse ($productions as $production)
            @php
                $text = \App\Livewire\Operator\Archive\ProductionArchivePage::text($production->content ?? []);
            @endphp
            <article wire:key="prod-{{ $production->id }}" class="p-4" x-data="{ copied: false }">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <button type="button" wire:click="toggle({{ $production->id }})" class="min-w-0 text-left">
                        <p class="text-xs text-gray-500">{{ $kinds[$production->kind] ?? $production->kind }} · {{ $production->brand?->name ?? '—' }} · sürüm {{ $production->version }} · {{ $production->created_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}{{ $production->model ? ' · '.$production->model : '' }}</p>
                        <p class="mt-0.5 font-medium text-gray-800 dark:text-gray-200">{{ $production->title ?: '—' }}</p>
                        @if ($openId !== $production->id)
                            <p class="mt-1 line-clamp-2 text-sm text-gray-500">{{ \Illuminate\Support\Str::limit(str_replace("\n", ' · ', $text), 220) }}</p>
                        @endif
                    </button>
                    <div class="flex flex-wrap items-center gap-1 text-xs">
                        <span @class(['rounded px-2 py-0.5 font-semibold',
                            'bg-gray-100 text-gray-600' => $production->status === 'new',
                            'bg-blue-50 text-blue-700' => $production->status === 'used',
                            'bg-emerald-50 text-emerald-700' => $production->status === 'published',
                            'bg-gray-50 text-gray-400 line-through' => $production->status === 'discarded'])>{{ $statusLabels[$production->status] ?? $production->status }}</span>
                        <button type="button" wire:click="rate({{ $production->id }}, 1)" title="İyi" @class(['rounded px-1.5 py-0.5 ring-1 ring-inset ring-gray-200', 'bg-emerald-50' => $production->rating === 1])>👍</button>
                        <button type="button" wire:click="rate({{ $production->id }}, -1)" title="Kötü" @class(['rounded px-1.5 py-0.5 ring-1 ring-inset ring-gray-200', 'bg-rose-50' => $production->rating === -1])>👎</button>
                        <select wire:change="mark({{ $production->id }}, $event.target.value)" class="rounded border border-gray-200 bg-transparent px-1.5 py-0.5 text-xs dark:border-gray-700">
                            @foreach ($statusLabels as $value => $label)<option value="{{ $value }}" @selected($production->status === $value)>{{ $label }}</option>@endforeach
                        </select>
                        <button type="button" x-on:click="navigator.clipboard.writeText(@js($text)); copied = true; setTimeout(() => copied = false, 2000)" class="rounded px-2 py-0.5 text-brand-600 ring-1 ring-inset ring-brand-200"><span x-show="! copied">Kopyala</span><span x-show="copied">Kopyalandı</span></button>
                    </div>
                </div>
                @if ($openId === $production->id)
                    <pre class="mt-3 whitespace-pre-wrap rounded-lg bg-gray-50 p-3 text-sm text-gray-800 dark:bg-white/[0.03] dark:text-gray-200">{{ $text }}</pre>
                @endif
            </article>
        @empty
            <p class="p-5 text-sm text-gray-500">Arşivde kayıt yok. Danışman taslakları, SEO briefleri, WhatsApp önerileri ve marka kurulum önerileri üretildikçe burada birikir.</p>
        @endforelse
    </section>
    {{ $productions->links() }}
</div>
