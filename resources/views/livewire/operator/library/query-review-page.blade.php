@php
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $btn = 'rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600 disabled:opacity-50';
    $ghost = 'rounded-lg px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700';
    $ready = $review->status === \App\Models\QueryReview::READY;
    $name = fn ($service) => $service?->primaryName?->raw_label ?? '—';
@endphp
<div class="space-y-4 text-sm dark:text-gray-200" data-query-review>
    <header class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Filtre taraması</h1>
        <a href="{{ route('operator.library.queries') }}" wire:navigate class="{{ $ghost }}">Sorgular</a>
    </header>

    @if ($message !== '')
        <p role="status" class="rounded-lg bg-blue-50 p-2 text-blue-800 dark:bg-blue-950 dark:text-blue-200">{{ $message }}</p>
    @elseif (! $ready)
        <p class="rounded-lg bg-gray-50 p-2 text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ $review->status === \App\Models\QueryReview::APPLIED ? 'Bu tarama onaylandı.' : 'Tarama sürüyor.' }}</p>
    @endif

    <section class="{{ $card }}" data-list="delete">
        <div class="mb-2 flex flex-wrap items-center gap-2">
            <h2 class="font-semibold">Silinecek sorgular · {{ $review->deletions }}</h2>
            @if ($ready && $deletions->total() > 0)
                <button type="button" wire:click="setAll('delete', true)" class="{{ $ghost }} ml-auto">Tümü</button>
                <button type="button" wire:click="setAll('delete', false)" class="{{ $ghost }}">Hiçbiri</button>
            @endif
        </div>
        <table class="w-full text-left text-xs">
            <thead class="text-gray-500"><tr><th class="w-6 py-1"></th><th>Sorgu</th><th>Filtre terimi</th><th class="text-right">Gösterim</th></tr></thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($deletions as $item)
                    <tr wire:key="d-{{ $item->id }}">
                        <td class="py-1"><input type="checkbox" wire:click="toggle({{ $item->id }})" @checked($all[$item->kind] !== in_array((int) $item->id, array_map('intval', $flipped), true)) @disabled(! $ready) aria-label="Seç"></td>
                        <td class="font-medium">{{ $item->searchQuery?->text }}</td>
                        <td>{{ $item->term }}</td>
                        <td class="text-right tabular-nums">{{ number_format((float) ($item->searchQuery?->impressions ?? 0), 0, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-2 text-gray-500">Yok.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="mt-2">{{ $deletions->links() }}</div>
    </section>

    <section class="{{ $card }}" data-list="service">
        <div class="mb-2 flex flex-wrap items-center gap-2">
            <h2 class="font-semibold">Hizmeti değişecek sorgular · {{ $review->changes }}</h2>
            @if ($ready && $changes->total() > 0)
                <button type="button" wire:click="setAll('service', true)" class="{{ $ghost }} ml-auto">Tümü</button>
                <button type="button" wire:click="setAll('service', false)" class="{{ $ghost }}">Hiçbiri</button>
            @endif
        </div>
        <table class="w-full text-left text-xs">
            <thead class="text-gray-500"><tr><th class="w-6 py-1"></th><th>Sorgu</th><th>Mevcut</th><th>Yeni</th><th>Değişiklik</th></tr></thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($changes as $item)
                    <tr wire:key="s-{{ $item->id }}">
                        <td class="py-1"><input type="checkbox" wire:click="toggle({{ $item->id }})" @checked($all[$item->kind] !== in_array((int) $item->id, array_map('intval', $flipped), true)) @disabled(! $ready) aria-label="Seç"></td>
                        <td class="font-medium">{{ $item->searchQuery?->text }}</td>
                        <td>{{ $name($item->fromService) }}</td>
                        <td>{{ $name($item->toService) }}</td>
                        <td class="text-gray-500">{{ $item->from_service_id === null ? 'yeni atama' : ($item->to_service_id === null ? 'atama kalkıyor' : 'değişiyor') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-2 text-gray-500">Yok.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="mt-2">{{ $changes->links() }}</div>
    </section>

    @if ($ready)
        <div class="flex justify-end"><button type="button" wire:click="approve" wire:confirm="Seçilen sorgular silinsin ve hizmetleri değişsin mi?" class="{{ $btn }}">Onayla</button></div>
    @endif
</div>
