@php($tr = app()->getLocale() === 'tr')
<div class="space-y-4" wire:poll.60s>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="section-title">{{ $tr ? 'WordPress işlem geçmişi' : 'WordPress activity' }}</h2>
            <p class="mt-1 text-sm text-gray-500">{{ $tr ? 'İçerik, SEO ve bakım işlemleri. Tarihler UTC; ziyaretçi tıklamaları dahil değildir.' : 'Content, SEO and maintenance events. Dates are UTC; visitor clicks are excluded.' }}</p>
        </div>
        <button type="button" wire:click="$refresh" class="rounded-lg px-3 py-2 text-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:ring-gray-700">{{ $tr ? 'Yenile' : 'Refresh' }}</button>
    </div>
    <div class="grid grid-cols-2 gap-x-8 gap-y-6 sm:grid-cols-3">
        <div><p class="text-xs text-gray-500">{{ $tr ? 'Son bağlantı' : 'Last contact' }}</p><p class="mt-1 text-sm">{{ $delivery?->last_received_at ?? '—' }}</p></div>
        <div><p class="text-xs text-gray-500">{{ $tr ? 'Son bildirilen kuyruk' : 'Last reported queue' }}</p><p class="mt-1 text-lg font-semibold tabular-nums">{{ $delivery?->pending_count ?? '—' }}</p></div>
        <div><p class="text-xs text-gray-500">{{ $tr ? 'Filtrelenen işlem sayısı' : 'Filtered events' }}</p><p class="mt-1 text-lg font-semibold tabular-nums">{{ $counts->sum() }}</p></div>
    </div>
    @if(!$delivery)
        <p class="rounded-lg p-3 text-sm tone-look">{{ $tr ? 'Henüz bildirim alınmadı. Connector 1.1.0 veya üzerini kurun. Mevcut eşleştirme korunur.' : 'No activity received. Install Connector 1.1.0 or later; existing pairing is preserved.' }}</p>
    @elseif(\Carbon\CarbonImmutable::parse($delivery->last_received_at)->lt(now()->subMinutes(30)))
        <p class="rounded-lg p-3 text-sm tone-look">{{ $tr ? '30 dakikadır bağlantı bildirimi yok. Bu, sitede işlem yapılmadığı anlamına gelmez; bağlantıyı ve WordPress zamanlayıcısını kontrol edin.' : 'No contact for 30 minutes. This does not mean no work occurred; check connectivity and WordPress scheduling.' }}</p>
    @endif
    @if($delivery?->gap_at)
        <p class="rounded-lg p-3 text-sm tone-look">{{ $tr ? 'Kayıt boşluğu bildirildi: ' : 'Activity coverage gap reported: ' }}{{ $delivery->gap_at }}. {{ $tr ? 'Envanter yenilenebilir; kaybolan geçmiş işlemler yeniden oluşturulamaz.' : 'Inventory can be refreshed; missing historical events cannot be reconstructed.' }}</p>
    @endif
    @if($delivery?->last_error)
        <p class="rounded-lg p-3 text-sm tone-critical">{{ $delivery->last_error }}</p>
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
    <div class="overflow-x-auto">
        <table class="min-w-full text-left text-sm">
            <thead class="border-b border-gray-200 text-xs text-gray-500 dark:border-gray-800 [&_th]:font-normal"><tr><th class="p-3">{{ $tr ? 'Zaman' : 'Time' }}</th><th class="p-3">{{ $tr ? 'İşlem' : 'Event' }}</th><th class="p-3">{{ $tr ? 'İçerik / Bileşen' : 'Content / Component' }}</th><th class="p-3">{{ $tr ? 'Yapan' : 'Actor' }}</th></tr></thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
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
