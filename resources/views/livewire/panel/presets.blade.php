@php
    use App\Livewire\Panel\PresetsPage;
    $input = 'h-10 w-full rounded-lg border border-gray-200 bg-white px-3 text-base text-gray-900 outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10 sm:h-9 sm:text-sm';
@endphp
<div class="space-y-8">
    <header class="space-y-4">
        <a href="{{ route('panel.leads') }}" wire:navigate class="link-neutral text-[13px]">Adaylar</a>
        <div class="min-w-0">
            <h1 class="page-title">Hazır listeler</h1>
            <p class="page-sub">Bir kez yazın, sonra seçin. Buradaki her şey yalnız sizin markanızın kullanıcılarına görünür.</p>
        </div>
    </header>

    <div class="grid gap-8 xl:grid-cols-3">
        @foreach (PresetsPage::KINDS as $kind => $meta)
            <section class="min-w-0 space-y-3" data-panel-presets="{{ $kind }}">
                <div>
                    <h2 class="text-[15px] font-semibold text-gray-900">{{ $meta['title'] }}</h2>
                    <p class="mt-0.5 text-[13px] text-gray-500">{{ $meta['hint'] }}</p>
                </div>
                @if ($canWrite)
                    <form wire:submit="add('{{ $kind }}')" class="space-y-2">
                        <input type="text" wire:model="draft.{{ $kind }}.label" class="{{ $input }}" placeholder="{{ $meta['placeholder'] }}" aria-label="{{ $meta['name'] }}" autocomplete="off">
                        @error("draft.$kind.label")<span class="block text-[13px] text-error-600">{{ $message }}</span>@enderror
                        @if ($meta['body'])
                            <textarea wire:model="draft.{{ $kind }}.body" rows="3" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-base text-gray-900 outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10 sm:text-sm" placeholder="{{ $kind === 'message' ? 'Merhaba {ad}, yarınki randevunuzu hatırlatmak isteriz.' : 'Arandı, ulaşılamadı. Yarın tekrar aranacak.' }}" aria-label="Metin"></textarea>
                            @error("draft.$kind.body")<span class="block text-[13px] text-error-600">{{ $message }}</span>@enderror
                        @endif
                        <button type="submit" class="btn">Ekle</button>
                    </form>
                @endif
                <div class="card">
                    @forelse ($lists[$kind] as $preset)
                        <div class="card-row items-start!" wire:key="preset-{{ $preset->id }}">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-gray-900">{{ $preset->label }}</p>
                                @if ($preset->body)
                                    <p class="mt-0.5 line-clamp-3 whitespace-pre-line text-[13px] text-gray-500">{{ $preset->body }}</p>
                                @endif
                            </div>
                            @if ($isManager)
                                <button type="button" wire:click="remove({{ $preset->id }})" wire:confirm="“{{ $preset->label }}” listeden çıkarılsın mı?" class="btn-quiet shrink-0">Çıkar</button>
                            @endif
                        </div>
                    @empty
                        <x-ta.empty-state size="sm" icon="none" title="Henüz eklenmedi." />
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>
</div>
