@php
    use App\Services\WhatsApp\ConversationArchive;

    $who = fn (array $row): string => $row['name'] ?? $row['phone'] ?? 'Gizli numara';
    $field = 'h-9 rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white';
@endphp
<div class="space-y-4" data-wa-archive>
    <header class="flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-xl font-semibold text-gray-900 dark:text-white">WhatsApp konuşmaları</h1>
            <p class="text-sm text-gray-500">Canlı numara ve bütün yedekler bir arada. Mesaj içinde arayın: her kelime mesajda geçmeli, büyük küçük harf ve Türkçe harfler fark etmez.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('operator.inbox') }}" wire:navigate class="btn h-8 px-3 text-[13px]">Gelen kutusu</a>
            <a href="{{ route('operator.whatsapp') }}" wire:navigate class="btn h-8 px-3 text-[13px]">WhatsApp ayarları</a>
        </div>
    </header>

    <div class="flex flex-col gap-2 sm:flex-row">
        <input type="search" aria-label="Konuşmalarda ara" wire:model.live.debounce.500ms="q" maxlength="200" placeholder="Örn. fatura istem, ödeme, implant, isim ya da numara" class="{{ $field }} w-full sm:flex-1" data-wa-archive-search autofocus />
        <select aria-label="Kaynak" wire:model.live="source" class="{{ $field }} sm:w-64" data-wa-archive-source>
            <option value="">Bütün kaynaklar</option>
            @foreach ($sources as $item)
                <option value="{{ $item['key'] }}">{{ $item['label'] }} ({{ number_format($item['conversations'], 0, ',', '.') }})</option>
            @endforeach
        </select>
    </div>

    <div class="grid gap-4 lg:grid-cols-12">
        <section @class(['card min-w-0 flex-col lg:col-span-5 lg:flex lg:h-[calc(100dvh-15rem)] lg:min-h-[28rem]', 'hidden' => $chat, 'flex' => ! $chat]) data-wa-archive-list>
            <div class="min-h-0 flex-1 overflow-y-auto">
                <div wire:loading.delay class="px-4 py-2 text-xs text-gray-500">Aranıyor…</div>
                @if ($result !== null)
                    <h2 class="section-title border-b border-gray-200 px-4 py-3 dark:border-gray-800">Mesajlar <span class="section-count">{{ count($result['hits']) }}{{ $result['more'] ? '+' : '' }}</span></h2>
                    @if (! $result['complete'])
                        <p class="px-4 py-2 text-xs text-gray-500">Süre doldu: en yeni {{ number_format($result['scanned'], 0, ',', '.') }} mesaja bakıldı. Kaynak seçerek daraltın.</p>
                    @endif
                    @forelse ($result['hits'] as $hit)
                        <button type="button" wire:key="hit-{{ $hit['message_id'] }}" wire:click="open({{ $hit['conversation_id'] }}, {{ $hit['message_id'] }})" class="block w-full border-b border-gray-200 px-4 py-3 text-left hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03] {{ $around === $hit['message_id'] ? 'bg-gray-50 dark:bg-white/[0.05]' : '' }}" data-wa-archive-hit>
                            <span class="flex items-baseline justify-between gap-2">
                                <span class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $who($hit) }}@if ($hit['customer'])<span class="font-normal text-gray-500"> · {{ $hit['customer'] }}</span>@endif</span>
                                <span class="shrink-0 text-xs text-gray-500">{{ $hit['at'] }}</span>
                            </span>
                            <span class="mt-1 block text-[13px] text-gray-700 dark:text-gray-300"><span class="text-gray-500">{{ $hit['direction'] === 'Moximu' ? 'Siz: ' : '' }}</span>{{ ConversationArchive::highlight($hit['text'], $words) }}</span>
                            <span class="mt-1 block text-xs text-gray-400">{{ $hit['source'] }}</span>
                        </button>
                    @empty
                        <p class="px-4 py-6 text-sm text-gray-500">Bu kelimelerin hepsini içeren mesaj yok. Kelimenin kökünü yazmayı deneyin (örn. “istemiyorum” yerine “istem”).</p>
                    @endforelse
                @endif

                @if ($result === null || $people->isNotEmpty())
                    <h2 class="section-title border-b border-gray-200 px-4 py-3 dark:border-gray-800">{{ $result === null ? 'Görüşmeler' : 'Kişiler' }} <span class="section-count">{{ $people->count() }}</span></h2>
                    @forelse ($people as $row)
                        <button type="button" wire:key="chat-{{ $row['conversation_id'] }}" wire:click="open({{ $row['conversation_id'] }})" class="flex w-full items-baseline justify-between gap-2 border-b border-gray-200 px-4 py-3 text-left hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03] {{ $conversation === $row['conversation_id'] && ! $around ? 'bg-gray-50 dark:bg-white/[0.05]' : '' }}" data-wa-archive-chat>
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium text-gray-900 dark:text-white">{{ $who($row) }}@if ($row['customer'])<span class="font-normal text-gray-500"> · {{ $row['customer'] }}</span>@endif</span>
                                <span class="block truncate text-xs text-gray-500">{{ $row['phone'] && $row['name'] ? $row['phone'].' · ' : '' }}{{ $row['source'] }} · {{ number_format($row['messages'], 0, ',', '.') }} mesaj</span>
                            </span>
                            <span class="shrink-0 text-xs text-gray-500">{{ $row['last_message_at'] }}</span>
                        </button>
                    @empty
                        <p class="px-4 py-6 text-sm text-gray-500">Görüşme yok.</p>
                    @endforelse
                @endif
            </div>
        </section>

        <section @class(['card min-w-0 flex-col lg:col-span-7 lg:flex lg:h-[calc(100dvh-15rem)] lg:min-h-[28rem]', 'hidden' => ! $chat, 'flex' => $chat]) data-wa-archive-reader>
            @if ($chat)
                <div class="flex items-center gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-800">
                    <button type="button" wire:click="close" class="btn-quiet h-8! px-2! lg:hidden" aria-label="Listeye dön">←</button>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $who($chat['conversation']) }}@if ($chat['conversation']['customer'])<span class="font-normal text-gray-500"> · {{ $chat['conversation']['customer'] }}</span>@endif</p>
                        <p class="truncate text-xs text-gray-500">{{ $chat['conversation']['phone'] ?? 'Gizli numara' }} · {{ $chat['conversation']['source'] }} · {{ number_format($chat['conversation']['messages'], 0, ',', '.') }} mesaj</p>
                    </div>
                    <a href="{{ route('operator.whatsapp.export', ['conversation' => $chat['conversation']['conversation_id']]) }}" class="btn-quiet h-7! px-2! text-xs!" title="Bu görüşmeyi Excel'e aktar">Excel</a>
                </div>
                <div class="min-h-0 flex-1 space-y-2 overflow-y-auto bg-gray-50 p-4 dark:bg-white/[0.02]" data-wa-archive-messages>
                    @if ($chat['older'])
                        <div class="flex justify-center"><button type="button" wire:click="older({{ $chat['older'] }})" class="btn-quiet h-7! px-2.5! text-xs!">Daha eski mesajlar</button></div>
                    @endif
                    @php $previousDay = null; @endphp
                    @foreach ($chat['messages'] as $message)
                        @php $day = substr((string) $message['at'], 0, 10); @endphp
                        @if ($previousDay !== $day)
                            @php $previousDay = $day; @endphp
                            <div wire:key="day-{{ $day }}" class="flex justify-center py-1"><span class="rounded-full bg-white px-3 py-0.5 text-xs text-gray-500 ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">{{ \Carbon\CarbonImmutable::parse($day)->locale('tr')->translatedFormat('j F Y') }}</span></div>
                        @endif
                        @php $outgoing = $message['from'] === 'Moximu'; $hit = $around === $message['id']; @endphp
                        <div wire:key="m-{{ $message['id'] }}" @if ($hit) id="wa-hit" x-init="$el.scrollIntoView({ block: 'center' })" @endif class="flex {{ $outgoing ? 'justify-end' : 'justify-start' }}">
                            <div @class([
                                'max-w-[85%] rounded-2xl px-3 py-2 text-sm shadow-sm',
                                'rounded-br-sm bg-success-100 text-gray-900 dark:bg-success-500/20 dark:text-gray-100' => $outgoing,
                                'rounded-bl-sm bg-white text-gray-900 dark:bg-gray-800 dark:text-gray-100' => ! $outgoing,
                                'ring-2 ring-warning-500' => $hit,
                            ])>
                                @if ($message['type'])<p class="mb-1 text-xs text-gray-500">{{ $message['type'] }}</p>@endif
                                <p class="whitespace-pre-wrap break-words">{{ ConversationArchive::highlight($message['text'], $hit ? $words : []) }}</p>
                                <p class="mt-1 text-right text-xs text-gray-500">{{ substr((string) $message['at'], 11) }}</p>
                            </div>
                        </div>
                    @endforeach
                    @if ($chat['newer'])
                        <div class="flex justify-center"><button type="button" wire:click="open({{ $chat['conversation']['conversation_id'] }})" class="btn-quiet h-7! px-2.5! text-xs!">En yeni mesajlara git</button></div>
                    @endif
                </div>
            @else
                <div class="flex flex-1 items-center justify-center p-8 text-sm text-gray-500">Okumak için soldan bir görüşme ya da mesaj seçin.</div>
            @endif
        </section>
    </div>
</div>
