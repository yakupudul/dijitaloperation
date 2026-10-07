{{-- Marka › Ayarlar › Uzmanlar: articles go to WordPress under the "Yazar" expert's WordPress user. --}}
@php($input = 'w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white')
<section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-brand-experts>
    <h2 class="font-semibold text-gray-900 dark:text-white">Uzmanlar</h2>
    <p class="text-xs text-gray-500">Yazılar WordPress'e "Yazar" işaretli uzmanın kullanıcısıyla gider; SEO eklentisi uzmanın profilini ve Person şemasını basar. Arama motorları ve yapay zekâ yanıtları uzmanı görünen içerikleri öne alır.</p>
    <ul class="mt-3 divide-y divide-gray-100 text-sm dark:divide-gray-800">
        @forelse ($experts as $expert)
            <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2" wire:key="expert-{{ $expert->id }}" data-expert="{{ $expert->id }}">
                <span class="font-medium text-gray-900 dark:text-white">{{ $expert->name }}</span>
                @if ($expert->title)<span class="text-xs text-gray-500">{{ $expert->title }}</span>@endif
                <span class="text-xs {{ $expert->wp_author ? 'text-gray-500' : 'text-amber-700 dark:text-amber-300' }}">{{ $expert->wp_author ? 'WordPress: '.$expert->wp_author : 'WordPress kullanıcısı yok' }}</span>
                @if ($expert->profile_url)<a href="{{ $expert->profile_url }}" target="_blank" rel="noopener" class="text-xs text-brand-600 hover:underline">Profil</a>@endif
                <span class="ml-auto flex items-center gap-2 text-xs">
                    @if ($expert->is_default)
                        <span class="rounded-full bg-emerald-50 px-2 py-0.5 font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">Yazar</span>
                    @elseif ($isAdmin)
                        <button type="button" wire:click="makeDefault({{ $expert->id }})" class="font-medium text-brand-600 hover:underline">Yazar yap</button>
                    @endif
                    @if ($isAdmin)
                        <button type="button" wire:click="remove({{ $expert->id }})" wire:confirm="{{ $expert->name }} silinsin mi?" class="text-gray-400 hover:text-red-600">Sil</button>
                    @endif
                </span>
            </li>
        @empty
            <li class="py-3 text-xs text-gray-500">Uzman eklenmedi; yazılar sitenin varsayılan kullanıcısıyla gider.</li>
        @endforelse
    </ul>
    @if ($isAdmin)
        <form wire:submit="add" class="mt-3 grid gap-2 sm:grid-cols-5">
            <div><input type="text" wire:model="name" placeholder="Ad (ör. Dr. Dt. Ayşe Yılmaz)" class="{{ $input }}" />@error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
            <div><input type="text" wire:model="title" placeholder="Unvan / uzmanlık" class="{{ $input }}" /></div>
            <div><input type="text" wire:model="wpAuthor" placeholder="WordPress kullanıcı adı ya da e-posta" class="{{ $input }}" /></div>
            <div><input type="url" wire:model="profileUrl" placeholder="Sitedeki profil sayfası" class="{{ $input }}" />@error('profileUrl')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
            <button type="submit" class="h-9 rounded-lg bg-brand-500 px-3 text-sm font-semibold text-white hover:bg-brand-600">Ekle</button>
        </form>
    @endif
</section>
