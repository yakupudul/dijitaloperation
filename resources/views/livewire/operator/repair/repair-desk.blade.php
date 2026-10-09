@php
    $card = 'rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $riskTone = ['low' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300', 'medium' => 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300', 'high' => 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300', 'manual' => 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300'];
@endphp
<div class="space-y-5" data-repair-desk>
    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Onarım masası</h1>
        <p class="mt-1 text-sm text-gray-500">Sistemin hazırladığı düzeltmeler: ne değişecek (eski → yeni), neden ve risk. Onayladığın an uygulanır, kayda geçer ve geri alınabilir. Değer hazır olmayan öneriler her gece kendiliğinden hazırlanıp buraya gelir.</p>
    </div>

    @if ($message !== '')
        <p class="rounded-lg bg-brand-50 px-4 py-2 text-sm text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">{{ $message }}</p>
    @endif

    <div class="flex flex-wrap gap-2 text-sm">
        <span class="rounded-lg bg-gray-100 px-3 py-1.5 font-semibold text-gray-800 dark:bg-gray-800 dark:text-white/90">Onay bekleyen {{ $counts['total'] }}</span>
        @foreach (\App\Services\Repair\RepairDesk::KINDS as $key => $label)
            @if (($counts['kinds'][$key] ?? 0) > 0)
                <button type="button" wire:click="$set('kind', '{{ $kind === $key ? '' : $key }}')" @class(['rounded-lg px-3 py-1.5', 'bg-brand-500 text-white' => $kind === $key, 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' => $kind !== $key])>{{ $label }} {{ $counts['kinds'][$key] }}</button>
            @endif
        @endforeach
    </div>

    <div class="flex flex-wrap items-end gap-3 text-sm">
        <label class="flex flex-col gap-1">
            <span class="text-xs text-gray-500">Marka</span>
            <select wire:model.live="brandId" class="rounded-lg border border-gray-300 px-2 py-1 dark:border-gray-700 dark:bg-gray-900">
                <option value="">Tümü</option>
                @foreach ($brands as $id => $name)
                    <option value="{{ $id }}">{{ $name }} ({{ $counts['brands'][$id] ?? 0 }})</option>
                @endforeach
            </select>
        </label>
        <label class="flex flex-col gap-1">
            <span class="text-xs text-gray-500">Risk</span>
            <select wire:model.live="risk" class="rounded-lg border border-gray-300 px-2 py-1 dark:border-gray-700 dark:bg-gray-900">
                <option value="">Tümü</option>
                @foreach (\App\Services\Repair\RepairDesk::RISKS as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
        @if ($isAdmin)
            <button type="button" wire:click="approveAllLow" wire:confirm="Görünen bütün düşük riskli işler uygulansın mı?" class="rounded-lg bg-brand-500 px-3 py-1.5 font-semibold text-white" data-approve-low>Düşük risklilerin hepsini onayla</button>
            <div class="flex items-center gap-1" data-repair-select>
                <button type="button" wire:click="selectVisible" class="rounded-lg border border-gray-300 px-3 py-1.5 text-gray-700 dark:border-gray-700 dark:text-gray-300">Görünenleri seç ({{ $rows->count() }})</button>
                @if ($total > $rows->count())
                    <button type="button" wire:click="selectAllMatching" class="rounded-lg border border-gray-300 px-3 py-1.5 text-gray-700 dark:border-gray-700 dark:text-gray-300">Filtredeki tümünü seç ({{ $total }})</button>
                @endif
                @if ($selected !== [])
                    <button type="button" wire:click="clearSelection" class="px-2 py-1.5 text-gray-500 hover:underline">Seçimi temizle</button>
                @endif
            </div>
            <button type="button" wire:click="approveSelected" @disabled($selected === []) class="rounded-lg border border-gray-300 px-3 py-1.5 text-gray-700 disabled:opacity-40 dark:border-gray-700 dark:text-gray-300">Seçilenleri onayla ({{ count($selected) }})</button>
            <input type="text" wire:model="rejectReason" placeholder="Red sebebi (isteğe bağlı)" class="w-56 rounded-lg border border-gray-300 px-2 py-1 dark:border-gray-700 dark:bg-gray-900">
            <button type="button" wire:click="rejectSelected" @disabled($selected === []) class="rounded-lg border border-gray-300 px-3 py-1.5 text-gray-700 disabled:opacity-40 dark:border-gray-700 dark:text-gray-300">Seçilenleri reddet ({{ count($selected) }})</button>
        @endif
    </div>

    <div class="space-y-2">
        @forelse ($rows as $row)
            <div class="{{ $card }} flex gap-3" wire:key="repair-{{ $row['id'] }}">
                @if ($isAdmin)
                    <input type="checkbox" wire:model.live="selected" value="{{ $row['id'] }}" class="mt-1 rounded border-gray-300">
                @endif
                <div class="min-w-0 flex-1 text-sm">
                    <p class="flex flex-wrap items-center gap-2">
                        <span class="rounded px-1.5 py-0.5 text-xs {{ $riskTone[$row['risk']] }}">{{ \App\Services\Repair\RepairDesk::RISKS[$row['risk']] }}</span>
                        <span class="text-xs text-gray-500">{{ $row['brand'] }} · {{ \App\Services\Repair\RepairDesk::KINDS[$row['kind']] }}</span>
                    </p>
                    <p class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $row['title'] }}</p>
                    @if ($row['target'] !== '')<p class="truncate text-xs text-gray-500">{{ $row['target'] }}</p>@endif
                    @if ($row['reason'] !== '')<p class="text-xs text-gray-500">Neden: {{ $row['reason'] }}</p>@endif
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        <div class="text-xs text-gray-500">
                            <p class="font-semibold uppercase text-gray-400">Şimdi</p>
                            @forelse ($row['before'] as $line)<p class="break-words">{{ $line }}</p>@empty<p>—</p>@endforelse
                        </div>
                        <div class="text-xs text-gray-800 dark:text-white/90">
                            <p class="font-semibold uppercase text-gray-400">{{ $row['risk'] === 'manual' ? 'Yapılacak' : 'Onaylanınca' }}</p>
                            @foreach ($row['after'] as $line)<p class="break-words">{{ $line }}</p>@endforeach
                        </div>
                    </div>
                    @if ($editing === $row['id'])
                        <form wire:submit="saveEdit" class="mt-2 flex flex-wrap gap-2">
                            <textarea wire:model="editValue" rows="2" class="min-w-0 flex-1 rounded-lg border border-gray-300 px-2 py-1 text-xs dark:border-gray-700 dark:bg-gray-900"></textarea>
                            <button type="submit" class="rounded-lg bg-brand-500 px-3 py-1 text-xs text-white">Kaydet</button>
                            <button type="button" wire:click="$set('editing', null)" class="text-xs text-gray-500">Vazgeç</button>
                        </form>
                    @endif
                </div>
                @if ($isAdmin)
                    <div class="flex shrink-0 flex-col items-end gap-1">
                        @if ($row['risk'] === 'manual')
                            <button type="button" wire:click="approve({{ $row['id'] }})" title="Bir hafta gizlenir; gece denetimi sorun kalmadıysa kapatır" class="rounded-lg border border-brand-500 px-3 py-1 text-xs font-semibold text-brand-600" data-repair-done>Yaptım</button>
                        @else
                            <button type="button" wire:click="approve({{ $row['id'] }})" class="rounded-lg bg-brand-500 px-3 py-1 text-xs font-semibold text-white">Onayla</button>
                        @endif
                        @foreach ($row['editable'] as $field)
                            <button type="button" wire:click="startEdit({{ $row['id'] }}, '{{ $field }}', @js((string) ($field === 'description' ? ($row['after'][0] ?? '') : \Illuminate\Support\Str::after(collect($row['after'])->first(fn ($l) => str_starts_with($l, $field === 'seo_title' ? 'Başlık: ' : 'Açıklama: ')) ?? '', ': '))))" class="text-xs text-brand-600 hover:underline">{{ ['seo_title' => 'Başlığı düzelt', 'meta_description' => 'Açıklamayı düzelt', 'description' => 'Metni düzelt'][$field] ?? 'Düzelt' }}</button>
                        @endforeach
                    </div>
                @endif
            </div>
        @empty
            <p class="{{ $card }} text-sm text-gray-500">Onay bekleyen hazır düzeltme yok.</p>
        @endforelse
        @if ($total > $rows->count())
            <p class="text-xs text-gray-500">İlk {{ $rows->count() }} iş gösteriliyor ({{ $total }} toplam); marka ya da tür seçerek daraltın.</p>
        @endif
    </div>

    <details class="{{ $card }} text-sm" data-repair-writes>
        <summary class="cursor-pointer font-semibold text-gray-800 dark:text-white/90">Son 7 günde uygulananlar ({{ $writes->count() }})</summary>
        <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($writes as $write)
                <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="write-{{ $write->id }}">
                    <span>{{ $write->created_at?->timezone('Europe/Istanbul')->format('d.m H:i') }} · {{ $write->digitalAsset?->name }} · {{ $write->action }} · {{ $write->statusLabel() }}@if ($write->error) <span class="text-red-600" title="{{ $write->error }}">· {{ \Illuminate\Support\Str::limit($write->error, 400) }}</span>@endif</span>
                    @if ($isAdmin && $write->isUndoable())
                        <button type="button" wire:click="undo({{ $write->id }})" wire:confirm="Bu değişiklik geri alınsın mı?" class="text-xs text-brand-600 hover:underline">Geri al</button>
                    @endif
                </li>
            @empty
                <li class="py-2 text-gray-500">Henüz yok.</li>
            @endforelse
        </ul>
    </details>
</div>
