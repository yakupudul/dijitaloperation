@php
    use App\Services\CommandCenter\CommandCenter;
    $card = 'rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $fmt = fn (float $n): string => number_format($n, 0, ',', '.');
    $tone = ['critical' => 'bg-error-50 text-error-700', 'high' => 'bg-warning-50 text-warning-700', 'medium' => 'bg-blue-50 text-blue-700', 'low' => 'bg-gray-100 text-gray-600'];
    $sevLabel = ['critical' => 'Kritik', 'high' => 'Yüksek', 'medium' => 'Orta', 'low' => 'Düşük'];
@endphp
<div class="space-y-5" wire:poll.120s>
    @include('livewire.demo.partials.flash')

    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Komuta merkezi</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">Tüm markaların, tüm kanallardaki yapılacak işleri tek listede: uyarılar, danışman ve SEO önerileri, site düzeltmeleri, Beyin önerileri, uyum, lead'ler ve onay bekleyenler. Aynı sorun bir kez görünür; burada kapattığınız iş kendi ekranında da kapanır. Sıralama önem + etki (TL, tık) ile yapılır.</p>
    </div>

    <div class="grid gap-3 sm:grid-cols-4">
        <div class="{{ $card }} p-4"><div class="text-xs text-gray-500">Açık iş</div><div class="text-xl font-semibold">{{ $fmt($summary['total']) }}</div><div class="text-xs text-gray-500">{{ $summary['brands'] }} markada</div></div>
        <div class="{{ $card }} p-4"><div class="text-xs text-gray-500">Kritik / yüksek</div><div class="text-xl font-semibold text-error-600">{{ $fmt($summary['critical']) }}</div></div>
        <div class="{{ $card }} p-4"><div class="text-xs text-gray-500">Risk altındaki tutar (tahmini)</div><div class="text-xl font-semibold">{{ $fmt($summary['money']) }} TL</div></div>
        <div class="{{ $card }} p-4"><div class="text-xs text-gray-500">Kazanılabilecek tık (90 gün)</div><div class="text-xl font-semibold">+{{ $fmt($summary['clicks']) }}</div></div>
    </div>

    <div class="flex flex-wrap items-end gap-3">
        <label class="text-sm"><span class="block text-xs text-gray-500">Marka</span>
            <select wire:model.live="brand" class="mt-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
                <option value="">Tüm markalar</option>
                @foreach ($brands as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
        </label>
        <label class="text-sm"><span class="block text-xs text-gray-500">Kaynak</span>
            <select wire:model.live="source" class="mt-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
                <option value="">Hepsi</option>
                @foreach (CommandCenter::SOURCES as $key => $label)
                    @if (($bySource[$key] ?? 0) > 0)<option value="{{ $key }}">{{ $label }} ({{ $bySource[$key] }})</option>@endif
                @endforeach
            </select>
        </label>
        <label class="text-sm"><span class="block text-xs text-gray-500">Önem</span>
            <select wire:model.live="severity" class="mt-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
                <option value="">Hepsi</option>
                @foreach ($sevLabel as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
        </label>
        @if ($visibleKeys !== [])
            <button type="button" wire:click="$set('bulkIds', @js($visibleKeys))" class="pb-2 text-xs text-brand-600 hover:underline">Görünenlerin hepsini seç</button>
        @endif
    </div>

    @if ($bulkIds !== [])
        <div class="flex flex-wrap items-center gap-3 rounded-lg bg-brand-50 px-4 py-2 text-sm dark:bg-brand-500/10">
            <span class="font-medium text-brand-700 dark:text-brand-300">{{ count($bulkIds) }} iş seçili</span>
            <button type="button" wire:click="act('done')" class="rounded-lg bg-success-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-success-600">✓ Yapıldı</button>
            <button type="button" wire:click="act('snooze', null, 7)" class="rounded-lg bg-white px-3 py-1.5 text-xs ring-1 ring-inset ring-gray-300 dark:bg-gray-800">7 gün ertele</button>
            <button type="button" wire:click="act('snooze', null, 30)" class="rounded-lg bg-white px-3 py-1.5 text-xs ring-1 ring-inset ring-gray-300 dark:bg-gray-800">30 gün ertele</button>
            <button type="button" wire:click="act('dismiss')" wire:confirm="Seçili işler kapatılsın mı?" class="rounded-lg bg-white px-3 py-1.5 text-xs ring-1 ring-inset ring-gray-300 dark:bg-gray-800">Kapat</button>
            <button type="button" wire:click="$set('bulkIds', [])" class="text-xs text-gray-500 hover:underline">Seçimi kaldır</button>
        </div>
    @endif

    <section class="{{ $card }}">
        @if ($rows->isEmpty())
            <p class="p-5 text-sm text-gray-500">Bu filtrede açık iş yok. 🎉</p>
        @else
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($rows as $row)
                    <li wire:key="cc-{{ $row['key'] }}" class="flex gap-3 p-4">
                        <input type="checkbox" value="{{ $row['key'] }}" wire:model.live="bulkIds" aria-label="Seç" class="mt-1 size-4 shrink-0 rounded border-gray-300">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500">
                                <span class="rounded-full px-2 py-0.5 {{ $tone[$row['severity']] ?? '' }}">{{ $sevLabel[$row['severity']] ?? $row['severity'] }}</span>
                                <span>{{ $row['source_label'] }}</span>
                                @if ($row['channel'])<span>· {{ $row['channel'] }}</span>@endif
                                @if ($row['brand'])<span>· <a href="{{ route('operator.brand', ['brand' => $row['brand_id']]) }}" wire:navigate class="hover:text-brand-600">{{ $row['brand'] }}</a></span>@endif
                                @if ($row['asset'])<span>· {{ $row['asset'] }}</span>@endif
                                @if ($row['impact'])<span class="font-medium text-gray-700 dark:text-gray-300">· {{ $row['impact'] }}</span>@endif
                            </div>
                            @if ($row['url'])
                                <a href="{{ $row['url'] }}" wire:navigate class="mt-1 block font-medium text-gray-800 hover:text-brand-600 dark:text-gray-200">{{ $row['title'] }}</a>
                            @else
                                <p class="mt-1 font-medium text-gray-800 dark:text-gray-200">{{ $row['title'] }}</p>
                            @endif
                            @if ($row['detail'])<p class="mt-1 line-clamp-2 text-sm text-gray-600 dark:text-gray-400">{{ $row['detail'] }}</p>@endif
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-1 text-xs">
                            @if (in_array('done', $row['actions'], true))
                                <button type="button" wire:click="act('done', '{{ $row['key'] }}')" class="rounded-lg bg-success-500 px-3 py-1 font-semibold text-white hover:bg-success-600">✓ Yapıldı</button>
                            @endif
                            @if (in_array('snooze', $row['actions'], true))
                                <button type="button" wire:click="act('snooze', '{{ $row['key'] }}', 7)" class="text-gray-500 hover:underline">7 gün ertele</button>
                            @endif
                            @if (in_array('dismiss', $row['actions'], true))
                                <button type="button" wire:click="act('dismiss', '{{ $row['key'] }}')" class="text-gray-400 hover:underline">Kapat</button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
            <div class="p-4">{{ $rows->links() }}</div>
        @endif
    </section>
</div>
