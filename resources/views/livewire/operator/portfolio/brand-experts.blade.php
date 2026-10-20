{{-- Marka › Ayarlar › Uzmanlar: articles go to WordPress under the "Yazar" expert's WordPress user. --}}
@php($input = 'w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white')
<section data-brand-experts>
    <h2 class="section-title">Uzmanlar <span class="text-sm font-normal text-gray-500">{{ count($experts) }}</span></h2>
    <p class="sr-only">Yazılar WordPress'e "Yazar" işaretli uzmanın kullanıcısıyla gider; SEO eklentisi uzmanın profilini ve Person şemasını basar. Arama motorları ve yapay zekâ yanıtları uzmanı görünen içerikleri öne alır.</p>
    <ul class="mt-2 divide-y divide-gray-200 border-b border-gray-200 text-sm dark:divide-gray-800 dark:border-gray-800">
        @forelse ($experts as $expert)
            <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5" wire:key="expert-{{ $expert->id }}" data-expert="{{ $expert->id }}">
                <span class="font-medium text-gray-900 dark:text-white">{{ $expert->name }}</span>
                @if ($expert->title)<span class="text-xs text-gray-500">{{ $expert->title }}</span>@endif
                <span class="text-xs {{ $expert->wp_author ? 'text-gray-500' : 'text-warning-700 dark:text-warning-300' }}">{{ $expert->wp_author ? 'WordPress: '.$expert->wp_author : 'WordPress kullanıcısı yok' }}</span>
                @if ($expert->profile_url)<a href="{{ $expert->profile_url }}" target="_blank" rel="noopener" class="link-neutral text-xs">Profil</a>@endif
                <span class="ml-auto flex items-center gap-2 text-xs">
                    @if ($expert->is_default)
                        <x-ta.pill tone="ok">Yazar</x-ta.pill>
                    @elseif ($isAdmin)
                        <button type="button" wire:click="makeDefault({{ $expert->id }})" class="link-neutral">Yazar yap</button>
                    @endif
                    @if ($isAdmin)
                        <button type="button" wire:click="remove({{ $expert->id }})" wire:confirm="{{ $expert->name }} silinsin mi?" class="text-gray-500 hover:text-error-600">Sil</button>
                    @endif
                </span>
            </li>
        @empty
            <li class="py-2.5 text-xs text-gray-500">Uzman eklenmedi; yazılar sitenin varsayılan kullanıcısıyla gider.</li>
        @endforelse
    </ul>
    @if ($isAdmin)
        <form wire:submit="add" class="mt-3 grid gap-2 sm:grid-cols-5">
            <div><input type="text" wire:model="name" placeholder="Ad (ör. Dr. Dt. Ayşe Yılmaz)" class="{{ $input }}" />@error('name')<p class="mt-1 text-xs text-error-600">{{ $message }}</p>@enderror</div>
            <div><input type="text" wire:model="title" placeholder="Unvan / uzmanlık" class="{{ $input }}" /></div>
            <div><input type="text" wire:model="wpAuthor" placeholder="WordPress kullanıcı adı ya da e-posta" class="{{ $input }}" /></div>
            <div><input type="url" wire:model="profileUrl" placeholder="Sitedeki profil sayfası" class="{{ $input }}" />@error('profileUrl')<p class="mt-1 text-xs text-error-600">{{ $message }}</p>@enderror</div>
            <button type="submit" class="h-9 rounded-lg px-3 text-sm font-medium text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-white dark:ring-gray-600 dark:hover:bg-white/5">Ekle</button>
        </form>
    @endif
</section>
