@php
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $input = 'rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-white';
    $btn = 'rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600 disabled:opacity-50';
    $ghost = 'rounded-lg px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700';
    $statusTone = ['yok' => 'bg-gray-100 text-gray-600', 'basvuru' => 'bg-blue-50 text-blue-700', 'verildi' => 'bg-amber-50 text-amber-700', 'dogrulandi' => 'bg-emerald-50 text-emerald-700', 'kaldirildi' => 'bg-rose-50 text-rose-700'];
@endphp
<div class="space-y-4 text-sm dark:text-gray-200" data-backlinks-tab @if ($polling) wire:poll.4s @endif>
    @if ($message !== '')
        <p role="status" class="rounded-lg bg-blue-50 p-2 text-blue-800 dark:bg-blue-950 dark:text-blue-200">{{ $message }}</p>
    @endif
    @error('brand')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror

    {{-- Bağlantı verenler --}}
    <section class="{{ $card }}" data-section="linking">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-gray-900 dark:text-white">Bağlantı verenler <span class="text-xs font-normal text-gray-500">{{ $backlinks->total() }} bağlantı · {{ $domains }} site</span></h2>
            <form wire:submit="importExport" class="flex items-center gap-2">
                <input type="file" wire:model="export" accept=".csv,.xlsx,text/csv" aria-label="Search Console dışa aktarımı" class="text-xs">
                <button type="submit" class="{{ $btn }}">GSC içe aktar</button>
            </form>
        </div>
        @error('export')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
        <form wire:submit="addBacklink" class="mt-2 flex flex-wrap items-center gap-2">
            <input type="url" wire:model="linkUrl" placeholder="Bağlantı veren sayfa" aria-label="Bağlantı veren sayfa" class="{{ $input }} w-64">
            <input type="url" wire:model="linkTarget" placeholder="Hedef sayfa (isteğe bağlı)" aria-label="Hedef sayfa" class="{{ $input }} w-56">
            <button type="submit" class="{{ $ghost }}">Ekle</button>
            @error('linkUrl')<span class="text-xs text-rose-600">{{ $message }}</span>@enderror
            @error('linkTarget')<span class="text-xs text-rose-600">{{ $message }}</span>@enderror
        </form>
        <table class="mt-3 w-full text-left text-xs">
            <thead class="text-gray-500"><tr><th class="py-1">Kaynak</th><th>Hedef</th><th class="w-24">İlk görülme</th><th class="w-20">Kaynak</th><th class="w-10"></th></tr></thead>
            <tbody>
                @forelse ($backlinks as $link)
                    <tr class="border-t border-gray-100 dark:border-gray-800" wire:key="bl-{{ $link->id }}">
                        <td class="py-1">@if ($link->source_url)<a href="{{ $link->source_url }}" target="_blank" rel="noopener noreferrer" class="hover:underline">{{ \Illuminate\Support\Str::limit($link->source_url, 70) }}</a>@else {{ $link->source_domain }} @endif</td>
                        <td class="text-gray-500">{{ $link->target_url ? \Illuminate\Support\Str::limit($link->target_url, 50) : '—' }}</td>
                        <td>{{ $link->first_seen?->format('d.m.Y') ?? '—' }}</td>
                        <td>{{ \App\Models\Backlink::SOURCES[$link->source] ?? $link->source }}</td>
                        <td><button type="button" wire:click="removeBacklink({{ $link->id }})" class="text-gray-400 hover:text-rose-600" aria-label="Sil">×</button></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-2 text-gray-500">Search Console › Bağlantılar › Dışa aktar (CSV / XLSX) dosyasını yükleyin.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="mt-2">{{ $backlinks->links() }}</div>
    </section>

    {{-- Potansiyel kaynaklar --}}
    <section class="{{ $card }}" data-section="potential">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-gray-900 dark:text-white">Potansiyel kaynaklar <span class="text-xs font-normal text-gray-500">{{ $sources->total() }}</span></h2>
            <div class="flex items-center gap-2">
                @if (($proposal['status'] ?? null) === 'running')<span class="text-xs text-gray-500">hazırlanıyor…</span>
                @elseif (($proposal['status'] ?? null) === 'ready')<span class="text-xs text-gray-500">{{ $proposal['added'] }} yeni kaynak</span>
                @elseif ($proposal !== null)<span class="text-xs text-rose-600">{{ ['not_operational' => 'marka hizmet dışı', 'no_provider' => 'AI bağlı değil', 'error' => 'hata'][$proposal['status']] ?? $proposal['status'] }}</span>@endif
                <button type="button" wire:click="proposeSources" @disabled(! $operational) class="{{ $btn }}" data-action="propose">AI ile kaynak öner</button>
                <x-operator.ai-prompt-info operation="backlinks.sources" />
            </div>
        </div>
        <form wire:submit="addSource" class="mt-2 flex flex-wrap items-center gap-2">
            <input type="text" wire:model="sourceName" placeholder="Ad" aria-label="Kaynak adı" class="{{ $input }} w-40">
            <input type="url" wire:model="sourceUrl" placeholder="https://" aria-label="Kaynak adresi" class="{{ $input }} w-64">
            <button type="submit" class="{{ $ghost }}">Ekle</button>
            @error('sourceName')<span class="text-xs text-rose-600">{{ $message }}</span>@enderror
            @error('sourceUrl')<span class="text-xs text-rose-600">{{ $message }}</span>@enderror
        </form>
        <table class="mt-3 w-full text-left text-xs">
            <thead class="text-gray-500"><tr><th class="py-1">Kaynak</th><th class="w-20">Tür</th><th class="w-28">Ücret</th><th class="w-24">Durum</th><th>Bağlantı</th><th class="w-10"></th></tr></thead>
            <tbody>
                @forelse ($sources as $source)
                    <tr class="border-t border-gray-100 align-top dark:border-gray-800" wire:key="src-{{ $source->id }}" data-source="{{ $source->domain }}">
                        <td class="py-1"><a href="{{ $source->url }}" target="_blank" rel="noopener noreferrer" class="font-medium hover:underline">{{ $source->name }}</a>
                            @if ($source->origin === \App\Services\Site\Backlinks\SerpMentionSources::ORIGIN)<span class="ml-1 rounded-full bg-sky-50 px-1.5 py-0.5 text-[10px] font-medium text-sky-700 dark:bg-sky-500/10 dark:text-sky-300" data-source-serp>Google'da görünüyor</span>@endif
                            @if ($source->reason)<p class="text-gray-500">{{ $source->reason }}</p>@endif</td>
                        <td>{{ \App\Models\BacklinkSource::KIND_LABELS[$source->kind] ?? $source->kind }}</td>
                        <td>@if ($source->fee_evidence_url)<a href="{{ $source->fee_evidence_url }}" target="_blank" rel="noopener noreferrer" class="hover:underline">{{ \App\Models\BacklinkSource::FEE_LABELS[$source->fee] ?? $source->fee }}</a>@else {{ \App\Models\BacklinkSource::FEE_LABELS[$source->fee] ?? $source->fee }} @endif</td>
                        <td><span class="rounded-full px-2 py-0.5 {{ $statusTone[$source->status] ?? '' }}" data-status>{{ \App\Models\BacklinkSource::STATUS_LABELS[$source->status] ?? $source->status }}</span>
                            @if ($source->note)<p class="text-gray-500">{{ $source->note }}</p>@endif</td>
                        <td>
                            @if (in_array($source->status, ['yok', 'basvuru', 'kaldirildi'], true))
                                <form wire:submit="markGiven({{ $source->id }})" class="flex items-center gap-1">
                                    <input type="url" wire:model="given.{{ $source->id }}" placeholder="Bağlantının olduğu sayfa" aria-label="Bağlantı adresi" class="{{ $input }} w-48 py-1">
                                    <button type="submit" class="{{ $ghost }}">Eklendi</button>
                                    @if ($source->status !== 'basvuru')<button type="button" wire:click="markApplied({{ $source->id }})" class="{{ $ghost }}">Başvuru yapıldı</button>@endif
                                </form>
                                @error('given.'.$source->id)<span class="text-rose-600">{{ $message }}</span>@enderror
                            @else
                                <a href="{{ $source->link_url }}" target="_blank" rel="noopener noreferrer" class="hover:underline">{{ \Illuminate\Support\Str::limit((string) $source->link_url, 50) }}</a>
                                @if ($source->verified_at)<span class="text-gray-400">· {{ $source->verified_at->format('d.m.Y') }}</span>@endif
                                <button type="button" wire:click="markNone({{ $source->id }})" class="ml-1 text-gray-400 hover:text-gray-700">geri al</button>
                            @endif
                        </td>
                        <td><button type="button" wire:click="removeSource({{ $source->id }})" class="text-gray-400 hover:text-rose-600" aria-label="Sil">×</button></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-2 text-gray-500">Kaynak yok.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="mt-2">{{ $sources->links() }}</div>
    </section>
</div>
