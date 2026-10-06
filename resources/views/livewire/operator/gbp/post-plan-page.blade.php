@php
    $chip = fn (string $status): string => match ($status) {
        'draft' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
        'approved' => 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
        'published' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
        'failed' => 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
        default => 'bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300',
    };
@endphp
<div class="space-y-5 dark:text-gray-200" data-gbp-post-plan>
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">İşletme gönderileri</h1>
            <p class="mt-1 text-xs text-gray-500">Her İşletme Profili için önümüzdeki {{ $horizon }} gün, günde bir gönderi; markanın sitesindeki sayfalardan yazılır. Onaylananlar günü gelince saat 10:00 civarında kendiliğinden yayınlanır.</p>
        </div>
        @if ($canWrite)
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="fillNow" wire:loading.attr="disabled" class="rounded-lg px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-700">Boş günleri şimdi doldur</button>
                <button type="button" @disabled($drafts === 0) x-on:click="if (confirm('Önümüzdeki {{ $horizon }} günün onay bekleyen {{ $drafts }} gönderisi onaylansın mı? Her biri kendi gününde yayınlanır.')) $wire.approveAll()"
                    class="rounded-lg bg-success-500 px-3 py-1.5 text-sm font-semibold text-white hover:bg-success-600 disabled:opacity-50">Tümünü onayla ({{ $drafts }})</button>
            </div>
        @endif
    </header>

    @if ($message !== '')<p role="status" class="rounded-lg bg-blue-50 p-3 text-sm text-blue-800 dark:bg-blue-950 dark:text-blue-200">{{ $message }}</p>@endif
    @unless ($canWrite)<p class="text-xs text-gray-500">Onay, düzenleme ve atlama yalnız Admin’dedir.</p>@endunless

    <section class="divide-y divide-gray-100 rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:divide-gray-700 dark:bg-gray-800 dark:ring-gray-700">
        @forelse ($locations as $location)
            @php
                $c = $counts[$location->id] ?? [];
                $planned = ($c['draft'] ?? 0) + ($c['approved'] ?? 0) + ($c['published'] ?? 0);
                $state = $states[$location->id] ?? null;
            @endphp
            <div wire:key="loc-{{ $location->id }}">
                <button type="button" wire:click="open({{ $location->id }})" class="flex w-full flex-wrap items-center gap-2 px-4 py-3 text-left hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium text-gray-900 dark:text-white">{{ $location->brand?->name }} · {{ $location->name }}</span>
                        @if ($state)<span @class(['block text-xs', 'text-rose-600' => ($state['status'] ?? '') === 'failed', 'text-gray-500' => ($state['status'] ?? '') !== 'failed']) @if (($state['status'] ?? '') === 'running') wire:poll.10s @endif>{{ $state['message'] ?? '' }}</span>@endif
                    </span>
                    <span class="text-xs text-gray-500">{{ $planned }}/{{ $horizon + 1 }} gün</span>
                    @if (($c['draft'] ?? 0) > 0)<span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $chip('draft') }}">{{ $c['draft'] }} onay bekliyor</span>@endif
                    @if (($c['approved'] ?? 0) > 0)<span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $chip('approved') }}">{{ $c['approved'] }} onaylı</span>@endif
                    @if ($planned < 15)<span class="rounded-full px-2 py-0.5 text-[11px] font-semibold bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300" title="Sitede kullanılabilecek sayfa az">İçerik az</span>@endif
                </button>

                @if ($this->location === (int) $location->id)
                    <div class="space-y-2 bg-gray-50/60 px-4 py-3 dark:bg-white/[0.02]">
                        <div class="flex flex-wrap items-center gap-3 text-sm">
                            <a href="{{ route('operator.gbp', ['assetId' => $location->id, 'tab' => 'posts']) }}" wire:navigate class="text-brand-600 hover:underline">Profil ekranı</a>
                            @if ($canWrite && ($c['draft'] ?? 0) > 0)
                                <button type="button" wire:click="approveAll({{ $location->id }})" class="font-semibold text-success-600 hover:underline">Bu işletmenin taslaklarını onayla ({{ $c['draft'] }})</button>
                            @endif
                        </div>
                        @forelse ($rows as $post)
                            @php
                                $write = $post->writeAction;
                                $failed = $post->status === 'failed' || ($write !== null && in_array($write->status, ['failed'], true));
                                $label = $failed ? 'Yayınlanamadı' : ($post->status === 'published' && $write !== null && in_array($write->status, ['queued', 'running'], true) ? 'Gönderiliyor' : $post->statusLabel());
                            @endphp
                            <div class="rounded-lg bg-white p-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-700" wire:key="post-{{ $post->id }}">
                                <div class="flex flex-wrap items-center gap-2 text-xs">
                                    <span class="font-semibold text-gray-900 dark:text-white">{{ \Illuminate\Support\Carbon::parse($post->publish_on)->locale('tr')->translatedFormat('d M D') }}</span>
                                    <span class="text-gray-500">{{ $angles[$post->angle]['label'] ?? $post->angle }}</span>
                                    @if ($post->page)<a href="{{ $post->page->url }}" target="_blank" rel="noopener" class="truncate text-brand-600 hover:underline">{{ \Illuminate\Support\Str::limit((string) $post->page->title, 70) }} ↗</a>@endif
                                    <span class="ml-auto rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $chip($failed ? 'failed' : $post->status) }}">{{ $label }}</span>
                                </div>
                                <div class="mt-2 flex gap-3">
                                    @if ($post->image_url)<img src="{{ $post->image_url }}" alt="" loading="lazy" class="h-16 w-16 shrink-0 rounded object-cover">@endif
                                    @if ($editing === $post->id)
                                        <div class="min-w-0 flex-1">
                                            <textarea wire:model="editText" rows="6" maxlength="1500" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900"></textarea>
                                            @error('editText')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                                            <div class="mt-1 flex gap-2"><button type="button" wire:click="saveEdit" class="rounded bg-brand-500 px-2 py-1 text-xs font-semibold text-white">Kaydet</button><button type="button" wire:click="$set('editing', null)" class="text-xs text-gray-500 hover:underline">Vazgeç</button></div>
                                        </div>
                                    @else
                                        <p class="min-w-0 flex-1 whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ $post->summary }}</p>
                                    @endif
                                </div>
                                @if ($post->note || ($failed && $write?->error))<p class="mt-1 text-xs {{ $failed ? 'text-rose-600' : 'text-gray-500' }}">{{ $failed && $write?->error ? $write->error : $post->note }}</p>@endif
                                @if ($canWrite && $editing !== $post->id)
                                    <div class="mt-2 flex flex-wrap gap-3 text-xs font-medium">
                                        @if ($post->status === 'draft')<button type="button" wire:click="approve({{ $post->id }})" class="text-success-600 hover:underline">Onayla</button>@endif
                                        @if (in_array($post->status, ['draft', 'approved'], true))
                                            <button type="button" wire:click="startEdit({{ $post->id }})" class="text-brand-600 hover:underline">Düzenle</button>
                                            <button type="button" wire:click="skip({{ $post->id }})" class="text-gray-600 hover:underline dark:text-gray-300">Atla</button>
                                        @endif
                                        @if ($failed)<button type="button" wire:click="retry({{ $post->id }})" class="text-rose-600 hover:underline">Tekrar dene</button>@endif
                                    </div>
                                @endif
                            </div>
                        @empty
                            <p class="text-sm text-gray-500">Bu işletme için plan yok. “Boş günleri şimdi doldur” ile hazırlanır; her sabah kendiliğinden de dolar.</p>
                        @endforelse
                    </div>
                @endif
            </div>
        @empty
            <p class="px-4 py-5 text-sm text-gray-500">Operasyonel markaya bağlı İşletme Profili yok.</p>
        @endforelse
    </section>
</div>
