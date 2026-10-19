{{-- A sent article on the site: where it stands, its Search Console numbers when published, and its date (admin click, undoable). --}}
@php
    $isPublished = $step === 'yayinda';
    $input = 'h-8 rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
@endphp
<div class="mt-1 space-y-1 text-xs" x-data="{ dating: false }" data-post="{{ $item['id'] }}">
    <p class="flex flex-wrap items-center gap-x-2 gap-y-1">
        <span @class(['font-medium', 'text-success-700 dark:text-success-400' => in_array($item['post_status'], ['publish', 'future'], true), 'text-error-600' => in_array($item['post_status'], ['trash', 'missing'], true), 'text-gray-600 dark:text-gray-300' => ! in_array($item['post_status'], ['publish', 'future', 'trash', 'missing'], true)]) data-post-state="{{ $item['post_status'] ?? 'sent' }}">{{ $item['post_line'] }}</span>
        @if ($item['sent_at'])<span class="text-gray-500">· gönderildi {{ $item['sent_at']->timezone('Europe/Istanbul')->format('d.m.Y') }}</span>@endif
        @if (! empty($item['can_apply']))
            <button type="button" wire:click="applyPageUpdate({{ $item['id'] }})" wire:confirm="Canlıdaki sayfanın başlığı ve metni bu yeni sürümle değişsin mi? WordPress'te taslakta düzelttiklerin de gider. Geri alınabilir." class="h-7 rounded-lg bg-brand-500 px-2.5 font-semibold text-white hover:bg-brand-600" data-apply-page="{{ $item['id'] }}">Sayfaya uygula</button>
        @endif
        @if ($item['can_date'])
            <button type="button" @click="dating = ! dating" class="font-medium text-brand-600 hover:underline" data-date-toggle="{{ $item['id'] }}">{{ $isPublished ? 'Tarihi değiştir' : ($item['post_status'] === 'future' ? 'Planı değiştir' : 'Yayın tarihi planla') }}</button>
        @endif
    </p>
    @if ($isPublished)
        @php $m = $item['metrics'] ?? null; @endphp
        <p class="text-gray-600 dark:text-gray-300" data-post-metrics="{{ $item['id'] }}">
            @if ($m !== null && ($m['impressions90'] > 0 || $m['clicks90'] > 0))
                Search Console · 30 gün: <span class="font-semibold tabular-nums">{{ number_format($m['clicks30'], 0, ',', '.') }}</span> tık, <span class="tabular-nums">{{ number_format($m['impressions30'], 0, ',', '.') }}</span> gösterim · 90 gün: <span class="font-semibold tabular-nums">{{ number_format($m['clicks90'], 0, ',', '.') }}</span> tık, <span class="tabular-nums">{{ number_format($m['impressions90'], 0, ',', '.') }}</span> gösterim
            @else
                Search Console'da henüz gösterim yok.
            @endif
        </p>
    @endif
    @if ($item['can_date'])
        <div x-show="dating" x-cloak class="flex flex-wrap items-center gap-2 rounded-lg bg-gray-50 p-2 dark:bg-white/5" data-date-form="{{ $item['id'] }}">
            @if ($isPublished)
                <input type="date" wire:model="dates.{{ $item['id'] }}" max="{{ now('Europe/Istanbul')->format('Y-m-d') }}" class="{{ $input }}" aria-label="Yeni yayın tarihi">
                <button type="button" wire:click="changeContentDate({{ $item['id'] }})" wire:confirm="Yayındaki yazının tarihi değişsin mi? Yalnız tarihi yenilemek Google sıralamasını yükseltmez." class="h-8 rounded-lg bg-brand-500 px-3 font-semibold text-white hover:bg-brand-600">Tarihi değiştir</button>
                <p class="w-full text-warning-800 dark:text-warning-200">Yalnız tarihi yenilemek Google'da sıralamayı yükseltmez; yazı güncellenmeden yeni tarih vermek okura ve Google'a yanıltıcı görünebilir. Yalnızca geçmiş bir tarih verilir; geri alınabilir.</p>
            @else
                <input type="datetime-local" wire:model="dates.{{ $item['id'] }}" min="{{ now('Europe/Istanbul')->addMinutes(15)->format('Y-m-d\TH:i') }}" class="{{ $input }}" aria-label="Yayın tarihi">
                <button type="button" wire:click="changeContentDate({{ $item['id'] }})" wire:confirm="Yazı seçilen tarihte WordPress'te kendiliğinden yayınlansın mı?" class="h-8 rounded-lg bg-brand-500 px-3 font-semibold text-white hover:bg-brand-600">Planla</button>
                <p class="w-full text-gray-500">WordPress yazıyı bu tarihte kendisi yayınlar (tüm dilleri birlikte). Sitede "Scheduled drafts" izni açık olmalı. Geri alınabilir.</p>
            @endif
        </div>
    @endif
</div>
