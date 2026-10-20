@php($tr = app()->getLocale() === 'tr')
@php($field = 'h-9 rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-white')
<section class="space-y-4" wire:poll.60s>
    <div class="section-head items-center">
        <h2 class="section-title" title="{{ $tr ? 'İçerik, SEO ve bakım işlemleri. Tarihler UTC; ziyaretçi tıklamaları dahil değildir.' : 'Content, SEO and maintenance events. Dates are UTC; visitor clicks are excluded.' }}">{{ $tr ? 'WordPress işlem geçmişi' : 'WordPress activity' }}</h2>
        <button type="button" wire:click="$refresh" class="btn-quiet ml-auto">{{ $tr ? 'Yenile' : 'Refresh' }}</button>
    </div>

    @if(!$delivery)
        <p class="card px-4 py-3 text-sm text-warning-800 dark:text-warning-400">{{ $tr ? 'Henüz bildirim alınmadı. Connector 1.1.0 veya üzerini kurun. Mevcut eşleştirme korunur.' : 'No activity received. Install Connector 1.1.0 or later; existing pairing is preserved.' }}</p>
    @elseif(\Carbon\CarbonImmutable::parse($delivery->last_received_at)->lt(now()->subMinutes(30)))
        <p class="card px-4 py-3 text-sm text-warning-800 dark:text-warning-400">{{ $tr ? '30 dakikadır bağlantı bildirimi yok. Bu, sitede işlem yapılmadığı anlamına gelmez; bağlantıyı ve WordPress zamanlayıcısını kontrol edin.' : 'No contact for 30 minutes. This does not mean no work occurred; check connectivity and WordPress scheduling.' }}</p>
    @endif
    @if($delivery?->gap_at)
        <p class="card px-4 py-3 text-sm text-warning-800 dark:text-warning-400">{{ $tr ? 'Kayıt boşluğu bildirildi: ' : 'Activity coverage gap reported: ' }}{{ $delivery->gap_at }}. {{ $tr ? 'Envanter yenilenebilir; kaybolan geçmiş işlemler yeniden oluşturulamaz.' : 'Inventory can be refreshed; missing historical events cannot be reconstructed.' }}</p>
    @endif
    @if($delivery?->last_error)
        <p class="card px-4 py-3 text-sm text-error-700 dark:text-error-400">{{ $delivery->last_error }}</p>
    @endif

    <div class="metric-strip grid-cols-3">
        <x-ta.metric-card :label="$tr ? 'Son bağlantı' : 'Last contact'" :value="$delivery?->last_received_at ? \Illuminate\Support\Carbon::parse($delivery->last_received_at)->format('d.m H:i') : '—'" :muted="! $delivery?->last_received_at" />
        <x-ta.metric-card :label="$tr ? 'Son bildirilen kuyruk' : 'Last reported queue'" :value="$delivery?->pending_count ?? '—'" />
        <x-ta.metric-card :label="$tr ? 'Filtrelenen işlem sayısı' : 'Filtered events'" :value="$counts->sum()" />
    </div>

    <div class="flex flex-wrap gap-2">
        <label class="flex items-center gap-2 text-[13px] text-gray-600 dark:text-gray-300">{{ $tr ? 'Başlangıç' : 'From' }}<input type="date" wire:model.live="from" class="{{ $field }}"></label>
        <label class="flex items-center gap-2 text-[13px] text-gray-600 dark:text-gray-300">{{ $tr ? 'Bitiş' : 'Until' }}<input type="date" wire:model.live="until" class="{{ $field }}"></label>
        <select aria-label="{{ $tr ? 'İşlem türü' : 'Event type' }}" wire:model.live="kind" class="{{ $field }} w-full sm:w-auto">
            <option value="">{{ $tr ? 'Tüm işlemler' : 'All events' }}</option>
            @foreach(['content' => $tr ? 'İçerik' : 'Content', 'seo' => 'SEO', 'maintenance' => $tr ? 'Bakım' : 'Maintenance', 'settings' => $tr ? 'Ayarlar' : 'Settings', 'access' => $tr ? 'Yetki' : 'Access'] as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
        </select>
        <select aria-label="{{ $tr ? 'Kullanıcı' : 'User' }}" wire:model.live="actor" class="{{ $field }} w-full sm:w-auto"><option value="">{{ $tr ? 'Tüm kullanıcılar' : 'All users' }}</option>@foreach($actors as $name)<option value="{{ $name }}">{{ $name }}</option>@endforeach</select>
    </div>

    <div class="card overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>{{ $tr ? 'Zaman' : 'Time' }}</th><th>{{ $tr ? 'İşlem' : 'Event' }}</th><th>{{ $tr ? 'İçerik / Bileşen' : 'Content / Component' }}</th><th class="hidden sm:table-cell">{{ $tr ? 'Yapan' : 'Actor' }}</th></tr></thead>
            <tbody>
            @forelse($events as $event)
                @php($payload = json_decode($event->payload, true))
                <tr wire:key="wp-event-{{ $event->id }}">
                    <td class="whitespace-nowrap text-[13px] tabular-nums text-gray-500">{{ $event->occurred_at }}</td>
                    <td class="text-[13px]">{{ __('wordpress-events.'.$event->type) }}</td>
                    <td>
                        <p class="text-gray-900 dark:text-white">{{ $event->title ?: $event->object_type.' #'.$event->object_id }}</p>
                        <details class="mt-1 text-xs text-gray-500"><summary class="cursor-pointer">{{ $tr ? 'Değişen alanlar' : 'Changed fields' }}</summary><p>{{ implode(', ', $payload['fields'] ?? []) }}</p></details>
                    </td>
                    <td class="hidden text-[13px] text-gray-600 sm:table-cell dark:text-gray-300">{{ $event->actor_name ?: ($tr ? 'WordPress otomasyonu' : 'WordPress automation') }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-8 text-center text-gray-500">{{ $tr ? 'Bu filtrelerde kaydedilmiş işlem yok.' : 'No recorded events match these filters.' }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div>{{ $events->links() }}</div>
</section>
