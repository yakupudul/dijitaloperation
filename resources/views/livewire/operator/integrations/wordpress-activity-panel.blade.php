@php($tr = app()->getLocale() === 'tr')
<div class="space-y-4" wire:poll.60s>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">{{ $tr ? 'WordPress işlem geçmişi' : 'WordPress activity' }}</h2>
            <p class="mt-1 text-sm text-gray-500">{{ $tr ? 'İçerik, SEO ve bakım işlemleri. Tarihler UTC; ziyaretçi tıklamaları dahil değildir.' : 'Content, SEO and maintenance events. Dates are UTC; visitor clicks are excluded.' }}</p>
        </div>
        <button type="button" wire:click="$refresh" class="rounded-lg border px-3 py-2 text-sm">{{ $tr ? 'Yenile' : 'Refresh' }}</button>
    </div>
    <div class="grid gap-3 sm:grid-cols-3">
        <div class="rounded-lg border p-3"><p class="text-xs text-gray-500">{{ $tr ? 'Son bağlantı' : 'Last contact' }}</p><p class="mt-1 text-sm">{{ $delivery?->last_received_at ?? '—' }}</p></div>
        <div class="rounded-lg border p-3"><p class="text-xs text-gray-500">{{ $tr ? 'Son bildirilen kuyruk' : 'Last reported queue' }}</p><p class="mt-1">{{ $delivery?->pending_count ?? '—' }}</p></div>
        <div class="rounded-lg border p-3"><p class="text-xs text-gray-500">{{ $tr ? 'Filtrelenen işlem sayısı' : 'Filtered events' }}</p><p class="mt-1">{{ $counts->sum() }}</p></div>
    </div>
    @if(!$delivery)
        <p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">{{ $tr ? 'Henüz bildirim alınmadı. Connector 1.1.0 veya üzerini kurun. Mevcut eşleştirme korunur.' : 'No activity received. Install Connector 1.1.0 or later; existing pairing is preserved.' }}</p>
    @elseif(\Carbon\CarbonImmutable::parse($delivery->last_received_at)->lt(now()->subMinutes(30)))
        <p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">{{ $tr ? '30 dakikadır bağlantı bildirimi yok. Bu, sitede işlem yapılmadığı anlamına gelmez; bağlantıyı ve WordPress zamanlayıcısını kontrol edin.' : 'No contact for 30 minutes. This does not mean no work occurred; check connectivity and WordPress scheduling.' }}</p>
    @endif
    @if($delivery?->gap_at)
        <p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">{{ $tr ? 'Kayıt boşluğu bildirildi: ' : 'Activity coverage gap reported: ' }}{{ $delivery->gap_at }}. {{ $tr ? 'Envanter yenilenebilir; kaybolan geçmiş işlemler yeniden oluşturulamaz.' : 'Inventory can be refreshed; missing historical events cannot be reconstructed.' }}</p>
    @endif
    @if($delivery?->last_error)
        <p class="rounded-lg bg-red-50 p-3 text-sm text-red-800">{{ $delivery->last_error }}</p>
    @endif
    <div class="flex flex-wrap gap-3">
        <label class="text-sm">{{ $tr ? 'Başlangıç' : 'From' }}<input type="date" wire:model.live="from" class="ml-2 rounded-lg border-gray-300 dark:bg-gray-900"></label>
        <label class="text-sm">{{ $tr ? 'Bitiş' : 'Until' }}<input type="date" wire:model.live="until" class="ml-2 rounded-lg border-gray-300 dark:bg-gray-900"></label>
        <select aria-label="{{ $tr ? 'İşlem türü' : 'Event type' }}" wire:model.live="kind" class="rounded-lg border-gray-300 text-sm dark:bg-gray-900">
            <option value="">{{ $tr ? 'Tüm işlemler' : 'All events' }}</option>
            @foreach(['content'=>'İçerik / Content','seo'=>'SEO','maintenance'=>'Bakım / Maintenance','settings'=>'Ayarlar / Settings','access'=>'Yetki / Access'] as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
        </select>
        <select aria-label="{{ $tr ? 'Kullanıcı' : 'User' }}" wire:model.live="actor" class="rounded-lg border-gray-300 text-sm dark:bg-gray-900"><option value="">{{ $tr ? 'Tüm kullanıcılar' : 'All users' }}</option>@foreach($actors as $name)<option value="{{ $name }}">{{ $name }}</option>@endforeach</select>
    </div>
    <div class="overflow-x-auto rounded-xl border">
        <table class="min-w-full text-left text-sm">
            <thead class="bg-gray-50 text-gray-500 dark:bg-gray-900"><tr><th class="p-3">{{ $tr ? 'Zaman' : 'Time' }}</th><th class="p-3">{{ $tr ? 'İşlem' : 'Event' }}</th><th class="p-3">{{ $tr ? 'İçerik / Bileşen' : 'Content / Component' }}</th><th class="p-3">{{ $tr ? 'Yapan' : 'Actor' }}</th></tr></thead>
            <tbody class="divide-y">
            @forelse($events as $event)
                @php($payload = json_decode($event->payload, true))
                <tr wire:key="wp-event-{{ $event->id }}"><td class="whitespace-nowrap p-3">{{ $event->occurred_at }}</td><td class="p-3">{{ __('wordpress-events.'.$event->type) }}</td><td class="p-3">
                    <p>{{ $event->title ?: $event->object_type.' #'.$event->object_id }}</p>
                    <details class="mt-1 text-xs text-gray-500"><summary class="cursor-pointer">{{ $tr ? 'Değişen alanlar' : 'Changed fields' }}</summary><p>{{ implode(', ', $payload['fields'] ?? []) }}</p></details>
                </td><td class="p-3">{{ $event->actor_name ?: ($tr ? 'WordPress otomasyonu' : 'WordPress automation') }}</td></tr>
            @empty
                <tr><td colspan="4" class="p-6 text-gray-500">{{ $tr ? 'Bu filtrelerde kaydedilmiş işlem yok.' : 'No recorded events match these filters.' }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $events->links() }}
</div>
