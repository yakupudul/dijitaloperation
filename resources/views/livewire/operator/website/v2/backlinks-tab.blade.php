{{-- Backlinkler: who links to the site today, and which places could link next (a source, its fee, where it stands). --}}
@php
    $input = 'h-8 rounded-lg border border-gray-200 bg-white px-2.5 text-[13px] text-gray-700 shadow-card focus:border-gray-300 focus:ring-2 focus:ring-gray-200 dark:border-gray-700 dark:bg-gray-900 dark:text-white';
    $btn = 'btn h-8! px-3! text-[13px]!';
    $small = 'btn h-7! px-2.5! text-xs!';
    $remove = 'inline-flex h-7 w-7 items-center justify-center rounded-md text-gray-400 hover:bg-gray-100 hover:text-error-600 dark:hover:bg-white/[0.05]';
    $statusTone = ['yok' => 'info', 'basvuru' => 'info', 'verildi' => 'look', 'dogrulandi' => 'ok', 'kaldirildi' => 'critical'];
@endphp
<div class="min-w-0 space-y-10" data-backlinks-tab @if ($polling) wire:poll.4s @endif>
    @if ($message !== '')
        <p role="status" class="rounded-lg border border-gray-200 bg-white px-4 py-2.5 text-sm text-gray-800 shadow-card dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">{{ $message }}</p>
    @endif
    @error('brand')<p class="text-[13px] text-error-600">{{ $message }}</p>@enderror

    {{-- Bağlantı verenler --}}
    <section class="min-w-0" data-section="linking">
        <div class="section-head justify-between gap-y-2">
            <div class="flex min-w-0 flex-wrap items-baseline gap-x-3">
                <h2 class="section-title">Bağlantı verenler</h2>
                <span class="text-[13px] tabular-nums text-gray-500 dark:text-gray-400">{{ $backlinks->total() }} bağlantı, {{ $domains }} site</span>
            </div>
            <form wire:submit="importExport" class="flex w-full min-w-0 flex-wrap items-center gap-2 sm:w-auto">
                <input type="file" wire:model="export" accept=".csv,.xlsx,text/csv" aria-label="Search Console dışa aktarımı"
                       class="min-w-0 flex-1 text-[13px] text-gray-600 file:mr-2 file:h-8 file:rounded-lg file:border file:border-gray-200 file:bg-white file:px-2.5 file:text-[13px] file:text-gray-700 sm:flex-none dark:text-gray-300 dark:file:border-gray-700 dark:file:bg-gray-900 dark:file:text-gray-200">
                <button type="submit" class="{{ $btn }}">GSC içe aktar</button>
            </form>
        </div>
        @error('export')<p class="mb-2 text-[13px] text-error-600">{{ $message }}</p>@enderror

        <div class="card">
            <form wire:submit="addBacklink" class="flex flex-wrap items-center gap-2 border-b border-gray-200 bg-gray-50/70 px-4 py-3 dark:border-gray-800 dark:bg-white/[0.02]">
                <input type="url" wire:model="linkUrl" placeholder="Bağlantı veren sayfa" aria-label="Bağlantı veren sayfa" class="{{ $input }} w-full sm:w-72">
                <input type="url" wire:model="linkTarget" placeholder="Hedef sayfa (isteğe bağlı)" aria-label="Hedef sayfa" class="{{ $input }} w-full sm:w-60">
                <button type="submit" class="{{ $btn }} w-full sm:w-auto">Ekle</button>
                @error('linkUrl')<span class="text-[13px] text-error-600">{{ $message }}</span>@enderror
                @error('linkTarget')<span class="text-[13px] text-error-600">{{ $message }}</span>@enderror
            </form>
            @if ($backlinks->isEmpty())
                <p class="px-4 py-5 text-[13px] text-gray-500 dark:text-gray-400">Search Console › Bağlantılar › Dışa aktar (CSV / XLSX) dosyasını yükleyin.</p>
            @else
            <div class="overflow-x-auto">
            <table class="data-table">
                <thead><tr><th>Kaynak</th><th>Hedef</th><th class="w-28">İlk görülme</th><th class="w-24">Geldiği yer</th><th class="w-10"><span class="sr-only">Sil</span></th></tr></thead>
                <tbody>
                    @foreach ($backlinks as $link)
                        <tr wire:key="bl-{{ $link->id }}">
                            <td class="min-w-[16rem] [overflow-wrap:anywhere]">@if ($link->source_url)<a href="{{ $link->source_url }}" target="_blank" rel="noopener noreferrer" class="link-neutral">{{ \Illuminate\Support\Str::limit($link->source_url, 70) }}</a>@else {{ $link->source_domain }} @endif</td>
                            <td class="min-w-[10rem] text-[13px] text-gray-500 [overflow-wrap:anywhere]">{{ $link->target_url ? \App\Services\SeoTasks\SeoText::urlPath($link->target_url) : '—' }}</td>
                            <td class="whitespace-nowrap text-[13px] tabular-nums">{{ $link->first_seen?->format('d.m.Y') ?? '—' }}</td>
                            <td class="whitespace-nowrap text-[13px]">{{ \App\Models\Backlink::SOURCES[$link->source] ?? $link->source }}</td>
                            <td><button type="button" wire:click="removeBacklink({{ $link->id }})" class="{{ $remove }}" aria-label="Sil">×</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
            @endif
        </div>
        @if ($backlinks->hasPages())<div class="mt-3">{{ $backlinks->links() }}</div>@endif
    </section>

    {{-- Potansiyel kaynaklar --}}
    <section class="min-w-0" data-section="potential">
        <div class="section-head justify-between gap-y-2">
            <div class="flex min-w-0 flex-wrap items-baseline gap-x-3">
                <h2 class="section-title">Potansiyel kaynaklar</h2>
                <span class="text-[13px] tabular-nums text-gray-500 dark:text-gray-400">{{ $sources->total() }} kaynak</span>
            </div>
            <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
                @if (($proposal['status'] ?? null) === 'running')<span class="inline-flex items-center gap-1.5 text-[13px] text-gray-500"><span class="live-dot h-1.5 w-1.5 rounded-full" aria-hidden="true"></span>hazırlanıyor…</span>
                @elseif (($proposal['status'] ?? null) === 'ready')<span class="text-[13px] text-gray-500">{{ $proposal['added'] }} yeni kaynak</span>
                @elseif ($proposal !== null)<span class="text-[13px] text-error-600">{{ ['not_operational' => 'marka hizmet dışı', 'no_provider' => 'AI bağlı değil', 'error' => 'hata'][$proposal['status']] ?? $proposal['status'] }}</span>@endif
                <button type="button" wire:click="proposeSources" @disabled(! $operational) class="btn-primary flex-1 sm:flex-none" data-action="propose">AI ile kaynak öner</button>
                <x-operator.ai-prompt-info operation="backlinks.sources" />
            </div>
        </div>

        <div class="card">
            <form wire:submit="addSource" class="flex flex-wrap items-center gap-2 border-b border-gray-200 bg-gray-50/70 px-4 py-3 dark:border-gray-800 dark:bg-white/[0.02]">
                <input type="text" wire:model="sourceName" placeholder="Ad" aria-label="Kaynak adı" class="{{ $input }} w-full sm:w-44">
                <input type="url" wire:model="sourceUrl" placeholder="https://" aria-label="Kaynak adresi" class="{{ $input }} w-full sm:w-72">
                <button type="submit" class="{{ $btn }} w-full sm:w-auto">Ekle</button>
                @error('sourceName')<span class="text-[13px] text-error-600">{{ $message }}</span>@enderror
                @error('sourceUrl')<span class="text-[13px] text-error-600">{{ $message }}</span>@enderror
            </form>
            @if ($sources->isEmpty())
                <p class="px-4 py-5 text-[13px] text-gray-500 dark:text-gray-400">Kaynak yok.</p>
            @else
            <div class="overflow-x-auto">
            <table class="data-table">
                <thead><tr><th>Kaynak</th><th class="w-24">Tür</th><th class="w-28">Ücret</th><th class="w-36">Durum</th><th>Bağlantı</th><th class="w-10"><span class="sr-only">Sil</span></th></tr></thead>
                <tbody>
                    @foreach ($sources as $source)
                        <tr class="align-top" wire:key="src-{{ $source->id }}" data-source="{{ $source->domain }}">
                            <td class="min-w-[15rem]">
                                <a href="{{ $source->url }}" target="_blank" rel="noopener noreferrer" class="font-medium text-gray-900 hover:underline dark:text-white">{{ $source->name }}</a>
                                @if ($source->origin === \App\Services\Site\Backlinks\SerpMentionSources::ORIGIN)<span class="ml-1 text-xs text-gray-500" data-source-serp>Google'da görünüyor</span>@endif
                                @if ($source->reason)<p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $source->reason }}</p>@endif
                            </td>
                            <td class="whitespace-nowrap text-[13px]">{{ \App\Models\BacklinkSource::KIND_LABELS[$source->kind] ?? $source->kind }}</td>
                            <td class="whitespace-nowrap text-[13px]">@if ($source->fee_evidence_url)<a href="{{ $source->fee_evidence_url }}" target="_blank" rel="noopener noreferrer" class="link-neutral">{{ \App\Models\BacklinkSource::FEE_LABELS[$source->fee] ?? $source->fee }}</a>@else {{ \App\Models\BacklinkSource::FEE_LABELS[$source->fee] ?? $source->fee }} @endif</td>
                            <td class="min-w-[9rem]"><x-ta.pill :tone="$statusTone[$source->status] ?? 'info'" data-status>{{ \App\Models\BacklinkSource::STATUS_LABELS[$source->status] ?? $source->status }}</x-ta.pill>
                                @if ($source->note)<p class="mt-1 text-xs text-gray-500">{{ $source->note }}</p>@endif</td>
                            <td class="min-w-[18rem]">
                                @if (in_array($source->status, ['yok', 'basvuru', 'kaldirildi'], true))
                                    <form wire:submit="markGiven({{ $source->id }})" class="flex flex-wrap items-center gap-1.5">
                                        <input type="url" wire:model="given.{{ $source->id }}" placeholder="Bağlantının olduğu sayfa" aria-label="Bağlantı adresi" class="{{ $input }} h-7! w-48 text-xs!">
                                        <button type="submit" class="{{ $small }}">Eklendi</button>
                                        @if ($source->status !== 'basvuru')<button type="button" wire:click="markApplied({{ $source->id }})" class="btn-quiet h-7! px-2! text-xs!">Başvuru yapıldı</button>@endif
                                    </form>
                                    @error('given.'.$source->id)<span class="text-xs text-error-600">{{ $message }}</span>@enderror
                                @else
                                    <span class="flex min-w-0 flex-wrap items-baseline gap-x-2 text-[13px]">
                                        <a href="{{ $source->link_url }}" target="_blank" rel="noopener noreferrer" class="link-neutral min-w-0 [overflow-wrap:anywhere]">{{ \Illuminate\Support\Str::limit((string) $source->link_url, 50) }}</a>
                                        @if ($source->verified_at)<span class="text-xs tabular-nums text-gray-500">{{ $source->verified_at->format('d.m.Y') }}</span>@endif
                                        <button type="button" wire:click="markNone({{ $source->id }})" class="text-xs text-gray-500 hover:text-gray-900 hover:underline dark:hover:text-white">geri al</button>
                                    </span>
                                @endif
                            </td>
                            <td><button type="button" wire:click="removeSource({{ $source->id }})" class="{{ $remove }}" aria-label="Sil">×</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
            @endif
        </div>
        @if ($sources->hasPages())<div class="mt-3">{{ $sources->links() }}</div>@endif
    </section>
</div>
