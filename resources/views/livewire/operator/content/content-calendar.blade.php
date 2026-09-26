@php
    use App\Models\ContentCalendarItem;
    $card = 'rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $tone = ['draft' => 'bg-gray-100 text-gray-600', 'approved' => 'bg-blue-50 text-blue-700', 'published' => 'bg-success-50 text-success-700', 'failed' => 'bg-error-50 text-error-700', 'skipped' => 'bg-gray-50 text-gray-400'];
    $input = 'mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900';
@endphp
<div class="space-y-5">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">İçerik takvimi</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500">Tüm markaların planlı içerikleri. İşletme Profili gönderileri onaylandıktan sonra zamanı gelince sistem tarafından yayınlanır (geri alınabilir). Blog, sosyal medya ve e-bülten hatırlatmadır: günü gelince Komuta merkezinde görünür.</p>
        </div>
        <div class="flex items-end gap-3">
            <label class="text-sm"><span class="block text-xs text-gray-500">Marka</span>
                <select wire:model.live="brand" class="mt-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
                    <option value="">Tümü</option>
                    @foreach ($brands as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
            </label>
            <label class="flex items-center gap-2 pb-2 text-sm"><input type="checkbox" wire:model.live="showDone" class="size-4 rounded border-gray-300"> Yayınlananlar da</label>
            <button type="button" wire:click="startNew" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600">+ İçerik planla</button>
        </div>
    </div>

    @if ($editingId !== null)
        <form wire:submit="save" class="{{ $card }} grid gap-3 p-5 sm:grid-cols-2">
            <label class="text-sm"><span class="text-xs text-gray-500">Marka</span>
                <select wire:model.live="form.brand_id" class="{{ $input }}"><option value="">Seçin</option>@foreach ($brands as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                @error('form.brand_id')<span class="text-xs text-error-600">{{ $message }}</span>@enderror
            </label>
            <label class="text-sm"><span class="text-xs text-gray-500">Kanal</span>
                <select wire:model.live="form.channel" class="{{ $input }}">@foreach (ContentCalendarItem::CHANNELS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
            </label>
            @if ($form['channel'] === 'gbp_post')
                <label class="text-sm"><span class="text-xs text-gray-500">İşletme Profili</span>
                    <select wire:model="form.digital_asset_id" class="{{ $input }}"><option value="">Seçin</option>@foreach ($profiles as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                    @error('form.digital_asset_id')<span class="text-xs text-error-600">Bir İşletme Profili seçin.</span>@enderror
                </label>
                <label class="text-sm"><span class="text-xs text-gray-500">Düğme</span>
                    <select wire:model="form.action_type" class="{{ $input }}">@foreach (['LEARN_MORE' => 'Daha fazla bilgi', 'BOOK' => 'Randevu al', 'CALL' => 'Ara', 'ORDER' => 'Sipariş ver', 'SIGN_UP' => 'Kaydol'] as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                </label>
            @endif
            <label class="text-sm sm:col-span-2"><span class="text-xs text-gray-500">Başlık</span><input wire:model="form.title" type="text" maxlength="200" class="{{ $input }}">@error('form.title')<span class="text-xs text-error-600">{{ $message }}</span>@enderror</label>
            <label class="text-sm sm:col-span-2"><span class="text-xs text-gray-500">Metin</span><textarea wire:model="form.body" rows="4" maxlength="1400" class="{{ $input }}"></textarea></label>
            <label class="text-sm"><span class="text-xs text-gray-500">Bağlantı (isteğe bağlı)</span><input wire:model="form.url" type="url" class="{{ $input }}">@error('form.url')<span class="text-xs text-error-600">{{ $message }}</span>@enderror</label>
            <label class="text-sm"><span class="text-xs text-gray-500">Yayın zamanı</span><input wire:model="form.scheduled_for" type="datetime-local" class="{{ $input }}"></label>
            <div class="flex gap-3 sm:col-span-2">
                <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white">Kaydet</button>
                <button type="button" wire:click="$set('editingId', null)" class="text-sm text-gray-500">Vazgeç</button>
            </div>
        </form>
    @endif

    @forelse ($weeks as $week => $items)
        <section class="{{ $card }}">
            <h2 class="border-b border-gray-100 px-5 py-3 text-sm font-semibold text-gray-700 dark:border-gray-800 dark:text-gray-200">{{ \Carbon\Carbon::parse($week)->translatedFormat('d F') }} haftası</h2>
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($items as $item)
                    <li wire:key="cal-{{ $item->id }}" class="flex flex-wrap items-start gap-3 p-4">
                        <div class="w-28 shrink-0 text-xs text-gray-500">{{ $item->scheduled_for->timezone('Europe/Istanbul')->translatedFormat('D d.m H:i') }}</div>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500">
                                <span class="rounded-full px-2 py-0.5 {{ $tone[$item->status] ?? '' }}">{{ ContentCalendarItem::STATUSES[$item->status] ?? $item->status }}</span>
                                <span>{{ ContentCalendarItem::CHANNELS[$item->channel] ?? $item->channel }}</span>
                                <span>· {{ $item->brand?->name }}</span>
                                @if ($item->digitalAsset)<span>· {{ $item->digitalAsset->name }}</span>@endif
                            </div>
                            <p class="mt-1 font-medium text-gray-800 dark:text-gray-200">{{ $item->title }}</p>
                            @if ($item->body)<p class="mt-1 line-clamp-2 text-sm text-gray-600 dark:text-gray-400">{{ $item->body }}</p>@endif
                            @if ($item->error)<p class="mt-1 text-xs text-error-600">{{ $item->error }}</p>@endif
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-1 text-xs">
                            @if (in_array($item->status, ['draft', 'failed'], true) && $isAdmin)
                                <button type="button" wire:click="approve({{ $item->id }})" class="rounded-lg bg-success-500 px-3 py-1 font-semibold text-white">Onayla</button>
                            @endif
                            @if ($item->channel !== 'gbp_post' && ! in_array($item->status, ['published', 'skipped'], true))
                                <button type="button" wire:click="markPublished({{ $item->id }})" class="text-brand-600 hover:underline">Yayınlandı</button>
                            @endif
                            @if (! in_array($item->status, ['published', 'skipped'], true) && $item->write_action_id === null)
                                <button type="button" wire:click="edit({{ $item->id }})" class="text-gray-500 hover:underline">Düzenle</button>
                                <button type="button" wire:click="skip({{ $item->id }})" class="text-gray-400 hover:underline">Vazgeç</button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @empty
        <p class="{{ $card }} p-5 text-sm text-gray-500">Takvimde plan yok. "+ İçerik planla" ile başlayın.</p>
    @endforelse
</div>
