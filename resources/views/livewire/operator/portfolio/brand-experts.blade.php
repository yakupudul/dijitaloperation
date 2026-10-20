{{-- Marka › Ayarlar › Uzmanlar: articles go to WordPress under the "Yazar" expert's WordPress user. --}}
@php($input = 'h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white')
<section data-brand-experts>
    <div class="section-head">
        <h2 class="section-title">Uzmanlar <span class="section-count">{{ count($experts) }}</span></h2>
    </div>
    <p class="sr-only">Yazılar WordPress'e "Yazar" işaretli uzmanın kullanıcısıyla gider; SEO eklentisi uzmanın profilini ve Person şemasını basar. Arama motorları ve yapay zekâ yanıtları uzmanı görünen içerikleri öne alır.</p>
    <ul class="card text-sm">
        @forelse ($experts as $expert)
            <li class="card-row flex-wrap gap-y-2" wire:key="expert-{{ $expert->id }}" data-expert="{{ $expert->id }}">
                <div class="min-w-0 flex-1">
                    <p class="font-medium text-gray-900 dark:text-white">{{ $expert->name }}@if ($expert->title) <span class="font-normal text-gray-500">{{ $expert->title }}</span>@endif</p>
                    <p class="mt-0.5 flex flex-wrap gap-x-3 text-[13px]">
                        <span class="{{ $expert->wp_author ? 'text-gray-500' : 'text-warning-700 dark:text-warning-300' }}">{{ $expert->wp_author ? 'WordPress: '.$expert->wp_author : 'WordPress kullanıcısı yok' }}</span>
                        @if ($expert->profile_url)<a href="{{ $expert->profile_url }}" target="_blank" rel="noopener" class="link-neutral">Profil</a>@endif
                    </p>
                </div>
                <span class="flex items-center gap-2">
                    @if ($expert->is_default)
                        <x-ta.pill tone="ok">Yazar</x-ta.pill>
                    @elseif ($isAdmin)
                        <button type="button" wire:click="makeDefault({{ $expert->id }})" class="btn h-7! px-2.5! text-xs!">Yazar yap</button>
                    @endif
                    @if ($isAdmin)
                        <button type="button" wire:click="remove({{ $expert->id }})" wire:confirm="{{ $expert->name }} silinsin mi?" class="btn-quiet h-7! px-2! hover:text-error-600!">Sil</button>
                    @endif
                </span>
            </li>
        @empty
            <li class="card-row text-[13px] text-gray-500">Uzman eklenmedi; yazılar sitenin varsayılan kullanıcısıyla gider.</li>
        @endforelse
        @if ($isAdmin)
            <li class="border-t border-gray-200 bg-gray-50/60 px-4 py-3 dark:border-gray-800 dark:bg-white/[0.02]">
                <form wire:submit="add" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_1fr_1fr_auto]">
                    <div><input type="text" wire:model="name" placeholder="Ad (ör. Dr. Dt. Ayşe Yılmaz)" aria-label="Ad" class="{{ $input }}" />@error('name')<p class="mt-1 text-xs text-error-600">{{ $message }}</p>@enderror</div>
                    <div><input type="text" wire:model="title" placeholder="Unvan / uzmanlık" aria-label="Unvan" class="{{ $input }}" /></div>
                    <div><input type="text" wire:model="wpAuthor" placeholder="WordPress kullanıcı adı ya da e-posta" aria-label="WordPress kullanıcısı" class="{{ $input }}" /></div>
                    <div><input type="url" wire:model="profileUrl" placeholder="Sitedeki profil sayfası" aria-label="Profil sayfası" class="{{ $input }}" />@error('profileUrl')<p class="mt-1 text-xs text-error-600">{{ $message }}</p>@enderror</div>
                    <button type="submit" class="btn h-9!">Ekle</button>
                </form>
            </li>
        @endif
    </ul>
</section>
