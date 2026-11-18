{{-- The post's side panel: opened from any tab, closed with Esc or a click outside. The only place where the full
     picture or the video is loaded, and the only place with the actions. --}}
@php
    $media = $opened->mediaItems();
    [$stateLabel, $stateTone] = $state($opened);
    $byPlatform = $opened->targets->keyBy(fn ($t) => (string) $t->account?->platform);
    $fbTarget = $byPlatform->get('facebook');
    $isReady = in_array($opened->status, ['draft', 'approved', 'failed', 'partial', 'expired'], true) && ! $opened->targets->contains(fn ($t) => $t->status === 'published');
    $linePlace = $place($opened);
    $lineOf = $opened->kind === 'text' ? $textLine : ($opened->kind === 'story' ? $storyLine : ($opened->kind === 'reels' && $reelLine !== null ? $reelLine : $line));
    $lineAt = array_search($opened->id, $lineOf, true);
    $texts = ['instagram' => $opened->caption, 'facebook' => $opened->caption, 'linkedin' => $opened->linkedin_text, 'threads' => $opened->threads_text, 'linkedin_me' => $opened->personal_text];
    $openWord = ['instagram' => "Instagram'da aç", 'facebook' => "Facebook'ta aç", 'linkedin' => "LinkedIn'de aç", 'threads' => "Threads'te aç", 'linkedin_me' => "LinkedIn'de aç"];
    $firstChannel = collect(array_keys($channelNames))->first(fn ($k) => $byPlatform->has($k)) ?? 'instagram';
@endphp
<div class="fixed inset-0 z-99999 flex justify-end bg-gray-900/40" wire:click.self="closePost" x-data="{ ch: '{{ $firstChannel }}' }" x-on:keydown.escape.window="$wire.closePost()" data-social-post-modal="{{ $opened->id }}">
    <aside class="flex h-full w-full flex-col bg-white shadow-2xl sm:max-w-[500px] dark:bg-gray-900" role="dialog" aria-label="Gönderi">
        <div class="flex items-start justify-between gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-800">
            <div class="min-w-0">
                <p class="text-[13px] text-gray-500">{{ $kindName($opened) }}{{ $opened->pillarLabel() ? ' · '.$opened->pillarLabel() : '' }} · #{{ $opened->id }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-gray-900 dark:text-white">{{ $opened->title ?: 'Adsız gönderi' }}</h2>
            </div>
            <button type="button" wire:click="closePost" class="btn" aria-label="Kapat">Kapat</button>
        </div>

        <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-5">
            <div class="flex gap-4">
                <div class="no-scrollbar flex w-[132px] shrink-0 snap-x snap-mandatory overflow-x-auto rounded-lg border border-gray-200 bg-gray-100 dark:border-gray-800 dark:bg-black" data-social-media>
                    @forelse ($media as $item)
                        <div class="flex w-full shrink-0 snap-center items-center justify-center">
                            @if ($item['type'] === 'video')<video src="{{ $item['url'] }}" controls preload="metadata" playsinline class="max-h-[236px] w-full"></video>@else<a href="{{ $item['url'] }}" target="_blank" rel="noopener"><img src="{{ $item['url'] }}" alt="" loading="lazy" class="max-h-[236px] w-full object-contain" /></a>@endif
                        </div>
                    @empty
                        <span class="flex h-[165px] w-full items-center justify-center text-xs text-gray-500">Yalnız metin</span>
                    @endforelse
                </div>
                <dl class="min-w-0 flex-1 space-y-2 text-[13px]">
                    <div class="flex gap-3"><dt class="w-[74px] shrink-0 text-gray-500">Durum</dt><dd><span class="chip {{ $stateTone }}">{{ $stateLabel }}</span></dd></div>
                    <div class="flex gap-3"><dt class="w-[74px] shrink-0 text-gray-500">Tarih</dt><dd class="text-gray-900 dark:text-white">{{ $opened->publish_at->timezone($tz)->translatedFormat('j F Y, H:i') }}@if ($opened->pinned && $opened->isEditable()) <span class="text-gray-500" data-pinned>· Sabit tarih</span>@endif</dd></div>
                    @if ($linePlace)<div class="flex gap-3"><dt class="w-[74px] shrink-0 text-gray-500">Sıra</dt><dd class="text-gray-900 dark:text-white" data-line-place>{{ $linePlace }}</dd></div>@endif
                    <div class="flex gap-3"><dt class="w-[74px] shrink-0 text-gray-500">Hazırlayan</dt><dd class="text-gray-900 dark:text-white">{{ $opened->created_by === 'claude' ? 'Claude' : 'Panel' }}</dd></div>
                    @if (count($media) > 1)<div class="flex gap-3"><dt class="w-[74px] shrink-0 text-gray-500">Medya</dt><dd class="text-gray-900 dark:text-white">{{ count($media) }} dosya; yana kaydırın</dd></div>@endif
                    @if ($opened->source_url)<div class="flex gap-3"><dt class="w-[74px] shrink-0 text-gray-500">Kaynak</dt><dd class="min-w-0"><a href="{{ $opened->source_url }}" target="_blank" rel="noopener" class="link-neutral block truncate">{{ $opened->source_url }}</a></dd></div>@endif
                </dl>
            </div>

            @if ($editing === $opened->id)
                <div class="space-y-3" data-social-edit>
                    <label class="block text-[13px] text-gray-500">Instagram ve Facebook metni
                        <textarea wire:model="editCaption" rows="7" maxlength="2200" class="mt-1 w-full rounded-lg border border-gray-300 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"></textarea></label>
                    <label class="block text-[13px] text-gray-500">LinkedIn metni (boşsa LinkedIn’e gitmez)
                        <textarea wire:model="editLinkedin" rows="6" maxlength="3000" class="mt-1 w-full rounded-lg border border-gray-300 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"></textarea></label>
                    <label class="block text-[13px] text-gray-500">Threads metni (en çok 500 karakter; boşsa Threads’e gitmez)
                        <textarea wire:model="editThreads" rows="4" maxlength="500" class="mt-1 w-full rounded-lg border border-gray-300 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"></textarea></label>
                    <label class="block text-[13px] text-gray-500">Kişisel LinkedIn metni (kendi profiliniz; boşsa profilinize gitmez)
                        <textarea wire:model="editPersonal" rows="6" maxlength="3000" class="mt-1 w-full rounded-lg border border-gray-300 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" data-social-edit-personal></textarea></label>
                    <label class="block text-[13px] text-gray-500">Yayın zamanı (değiştirirseniz tarih sabitlenir)
                        <input type="datetime-local" wire:model="editTime" class="mt-1 w-full rounded-lg border border-gray-300 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"></label>
                    <div class="flex gap-2"><button type="button" wire:click="saveEdit" class="btn-primary">Kaydet</button><button type="button" wire:click="cancelEdit" class="btn">Vazgeç</button></div>
                </div>
            @else
                <div data-social-channel-texts>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($channelNames as $key => $label)
                            <button type="button" x-on:click="ch = '{{ $key }}'" class="btn" x-bind:class="ch === '{{ $key }}' ? 'border-second bg-second-soft text-second' : ''">{{ $label }}@if (! $byPlatform->has($key)) <span class="font-normal text-gray-400">yok</span>@endif</button>
                        @endforeach
                    </div>
                    @foreach ($channelNames as $key => $label)
                        @php $target = $byPlatform->get($key); @endphp
                        <div x-show="ch === '{{ $key }}'" @if ($key !== $firstChannel) x-cloak @endif class="mt-3 space-y-3" data-channel-text="{{ $key }}">
                            @if ($target === null)
                                <p class="rounded-lg border border-dashed border-gray-300 px-3.5 py-3 text-[13px] text-gray-500 dark:border-gray-700">Bu gönderi {{ $label }} için hazırlanmadı.{{ $key === 'linkedin' ? ' LinkedIn metni yazılırsa oraya da gider (hikâyeler hariç).' : ($key === 'threads' ? ' Threads metni yazılırsa oraya da gider.' : '') }}</p>
                            @else
                                <p class="text-[13px] text-gray-500"><span class="font-medium text-gray-900 dark:text-white">{{ $target->statusLabel() }}</span>{{ $target->published_at ? ' · '.$target->published_at->timezone($tz)->translatedFormat('j F H:i') : '' }}@if ($target->permalink) · <a href="{{ $target->permalink }}" target="_blank" rel="noopener" class="link-neutral">{!! $openWord[$key] !!} ↗</a>@endif</p>
                                @if ($target->error)<p class="tone-critical rounded-lg px-3 py-2 text-[13px]">{{ $target->error }}</p>@endif
                                @if ($insight = \App\Services\Social\SocialInsights::line($target))<p class="text-[13px] text-gray-600 dark:text-gray-300" title="Ölçüm: {{ $target->insights_at?->timezone($tz)->format('d.m H:i') }}">{{ $insight }}</p>@endif
                                <p class="max-h-[260px] overflow-y-auto rounded-lg border border-gray-200 px-3.5 py-3 text-sm whitespace-pre-line text-gray-900 dark:border-gray-800 dark:text-gray-100">{{ filled($texts[$key]) ? $texts[$key] : 'Metin yok.' }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        @if ($canWrite && $editing !== $opened->id)
            <div class="flex flex-wrap gap-2 border-t border-gray-200 py-3.5 pr-20 pl-5 dark:border-gray-800" data-social-post-actions>
                @if ($isReady)
                    @if (in_array($opened->status, ['draft', 'expired'], true))<button type="button" wire:click="approve({{ $opened->id }})" class="btn-primary h-9">Onayla{{ $opened->status === 'draft' ? ' ('.$opened->publish_at->timezone($tz)->format('d.m H:i').')' : '' }}</button>@endif
                    @if (in_array($opened->status, ['failed', 'partial'], true))<button type="button" wire:click="retry({{ $opened->id }})" class="btn-primary h-9">Yeniden dene</button>@endif
                    <button type="button" wire:click="publishNow({{ $opened->id }})" wire:confirm="Bu gönderi şimdi yayınlansın mı?" class="btn h-9" data-social-publish-now>{{ $opened->status === 'approved' ? 'Şimdi yayınla' : 'Onayla ve şimdi yayınla' }}</button>
                    @if ($opened->isEditable())<button type="button" wire:click="edit({{ $opened->id }})" class="btn h-9">Düzenle</button>@endif
                    @if ($lineAt !== false)
                        <button type="button" wire:click="move({{ $opened->id }}, -1)" class="btn h-9" @disabled($lineAt === 0) aria-label="Sırada öne al">↑ Öne al</button>
                        <button type="button" wire:click="move({{ $opened->id }}, 1)" class="btn h-9" @disabled($lineAt === count($lineOf) - 1) aria-label="Sırada arkaya al">↓ Arkaya al</button>
                    @endif
                    @if ($opened->isEditable())
                        @if ($opened->kind === 'text')@elseif ($opened->pinned)<button type="button" wire:click="pin({{ $opened->id }}, false)" class="btn h-9">Sıraya al</button>@else<button type="button" wire:click="pin({{ $opened->id }}, true)" class="btn h-9">Tarihi sabitle</button>@endif
                    @endif
                    @if ($opened->status === 'approved')<button type="button" wire:click="unapprove({{ $opened->id }})" class="btn-quiet h-9">Onayı geri al</button>@endif
                    @if ($opened->isEditable() || in_array($opened->status, ['failed', 'partial'], true))<button type="button" wire:click="skip({{ $opened->id }})" class="btn-quiet h-9">Atla</button>@endif
                    @if (in_array($opened->status, ['draft', 'expired'], true))<button type="button" wire:click="remove({{ $opened->id }})" wire:confirm="Gönderi silinsin mi?" class="btn-quiet h-9 text-error-600">Sil</button>@endif
                @elseif ($opened->status === 'skipped')
                    <button type="button" wire:click="remove({{ $opened->id }})" wire:confirm="Gönderi silinsin mi?" class="btn-quiet h-9 text-error-600">Sil</button>
                @else
                    <button type="button" wire:click="takeDown({{ $opened->id }})" wire:confirm="{{ $fbTarget?->external_id ? 'Facebook\'taki gönderi silinir ve gönderi bu listeden kalkar. ' : 'Gönderi bu listeden kalkar. ' }}Instagram'daki gönderiyi Instagram uygulamasından silmen gerekir (Meta buna izin vermiyor). Devam edilsin mi?" class="btn-quiet h-9 text-error-600" data-social-take-down>Yayından kaldır</button>
                @endif
            </div>
        @endif
    </aside>
</div>
