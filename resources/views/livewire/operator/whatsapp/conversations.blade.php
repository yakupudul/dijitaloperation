@php
    $suggestionLabels = [
        'pending' => ['muted', 'Öneri bekliyor'], 'requested' => ['warn', 'Öneri sırada'], 'running' => ['warn', 'Öneri hazırlanıyor'],
        'ready' => ['ok', 'Öneri hazır'], 'failed' => ['bad', 'Öneri hazırlanamadı'], 'idle' => ['muted', 'Yedekten'],
    ];
    $shortTime = function ($at): string {
        if (! $at) {
            return '';
        }
        $local = $at->timezone('Europe/Istanbul');

        return $local->isToday() ? $local->format('H:i') : ($local->isYesterday() ? 'Dün' : $local->format('d.m.Y'));
    };
@endphp
<div class="grid gap-4 lg:grid-cols-12" data-wa-inbox>
    <section class="{{ $card }} flex min-w-0 flex-col lg:col-span-4 lg:h-[calc(100vh-14rem)] lg:min-h-[32rem]">
        <div class="border-b border-gray-200 p-3 dark:border-gray-800">
            <div class="mb-2 flex items-center justify-between">
                <h2 class="font-semibold text-gray-900 dark:text-white">Görüşmeler <span class="text-xs font-normal text-gray-500">{{ $rows->count() }}</span></h2>
                <button type="button" wire:click="generateAll" wire:loading.attr="disabled" wire:target="generateAll" @disabled($awaitingReply === 0) title="Son mesajı müşteriden gelen ve cevabı hazır olmayan görüşmeler (son 30 gün, tıklama başına en çok 50)" class="rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600 disabled:opacity-50" data-wa-generate-all>Cevap üret{{ $awaitingReply ? ' ('.$awaitingReply.')' : '' }}</button>
            </div>
            <input aria-label="Görüşme ara" wire:model.live.debounce.400ms="q" maxlength="100" placeholder="İsim veya numara ara" class="w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 text-sm dark:border-gray-700" />
        </div>
        <div class="min-h-0 flex-1 overflow-y-auto">
            @forelse($rows as $row)
                @php [$rowTone, $rowText] = $suggestionLabels[$row->suggestion_status] ?? ['muted', $row->suggestion_status]; @endphp
                <button type="button" wire:key="chat-{{ $row->id }}" wire:click="selectConversation({{ $row->id }})" class="flex w-full items-start gap-3 border-b border-gray-100 px-3 py-3 text-left hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03] {{ $selected?->id === $row->id ? 'bg-brand-50 dark:bg-white/[0.05]' : '' }}">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-sm font-semibold text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">{{ mb_strtoupper(mb_substr($row->contact_name ?: $row->contact_id, 0, 1)) }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-baseline justify-between gap-2">
                            <span class="truncate font-medium text-gray-900 dark:text-white">{{ $row->contact_name ?: $phoneLabel($row->contact_id) }}</span>
                            <span class="shrink-0 text-xs text-gray-500">{{ $shortTime($row->last_message_at) }}</span>
                        </span>
                        <span class="mt-1 flex flex-wrap items-center gap-1.5">
                            <span class="rounded-full px-2 py-0.5 text-[11px] ring-1 ring-inset {{ $tone[$rowTone] }}">{{ $rowText }}</span>
                            @if($row->customer_id)<span class="rounded-full px-2 py-0.5 text-[11px] ring-1 ring-inset {{ $tone['muted'] }}">Müşteri</span>@endif
                            @if($row->opted_out_at)<span class="rounded-full px-2 py-0.5 text-[11px] ring-1 ring-inset {{ $tone['bad'] }}">Mesaj istemiyor</span>@endif
                        </span>
                    </span>
                </button>
            @empty
                <p class="p-5 text-sm text-gray-500">{{ trim($q) !== '' ? 'Aramaya uyan görüşme yok.' : ($state === 'connected' ? 'Henüz mesaj gelmedi. Numaranıza gelen ilk mesaj burada görünecek.' : 'Yedek çıkarılınca ya da numara bağlanınca görüşmeler burada listelenir.') }}</p>
            @endforelse
        </div>
    </section>

    <section class="{{ $card }} flex min-w-0 flex-col lg:col-span-8 lg:h-[calc(100vh-14rem)] lg:min-h-[32rem]">
        @if($selected)
            @php
                $fromBackup = $selected->phone_number_id === \App\Services\WhatsApp\Backup\WhatsAppBackupImporter::LINE;
                // The 24-hour window is a Cloud API rule; a backup chat is answered from the phone.
                $lastIn = $fromBackup ? null : $selected->last_incoming_at;
                $windowOpen = $lastIn && $lastIn->greaterThan(now()->subHours(24));
                $hoursLeft = $windowOpen ? max(1, (int) ceil(now()->diffInHours($lastIn->copy()->addHours(24), false))) : 0;
                $suggestionFresh = $selected->suggestion_status === 'ready' && $selected->suggested_revision === $selected->revision;
            @endphp
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-800">
                <div class="min-w-0">
                    <h2 class="truncate font-semibold text-gray-900 dark:text-white">{{ $selected->contact_name ?: $phoneLabel($selected->contact_id) }}</h2>
                    <p class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                        <span>{{ $phoneLabel($selected->contact_id) }}</span>
                        @if($fromBackup)
                            <span class="rounded-full px-2 py-0.5 ring-1 ring-inset {{ $tone['muted'] }}" title="Telefondaki yedekten geldi; yedekten sonraki mesajlar burada yok.">Yedekten</span>
                        @endif
                        @if($windowOpen)
                            <span class="rounded-full px-2 py-0.5 ring-1 ring-inset {{ $tone['ok'] }}" title="Son gelen mesajdan sonraki 24 saat içinde telefondan normal cevap verebilirsiniz.">Cevap penceresi açık · ~{{ $hoursLeft }} sa</span>
                        @elseif($lastIn)
                            <span class="rounded-full px-2 py-0.5 ring-1 ring-inset {{ $tone['muted'] }}" title="24 saat geçti; WhatsApp kuralı gereği yeni mesaj için onaylı şablon gerekir.">Cevap penceresi kapalı</span>
                        @endif
                        @if($selected->opted_out_at)
                            <span class="rounded-full px-2 py-0.5 ring-1 ring-inset {{ $tone['bad'] }}" title="{{ $selected->opted_out_at->timezone('Europe/Istanbul')->format('d.m.Y') }} tarihinde mesaj istemediğini bildirdi. KVKK gereği mesaj göndermeyin.">Mesaj istemiyor</span>
                        @endif
                    </p>
                </div>
                <div class="flex items-center gap-2 text-xs">
                    @if($linkedCustomer)
                        <a href="{{ route('operator.customer', ['customerId' => $linkedCustomer->id]) }}" wire:navigate title="{{ $selected->link_source === 'operator' ? 'Elle bağlandı' : 'Telefon numarasından eşleşti' }}" class="font-medium text-brand-600 hover:underline">{{ $linkedCustomer->name }}</a>
                    @else
                        <span class="hidden text-gray-500 sm:inline">Bu numara bir müşteriyle eşleşmedi</span>
                    @endif
                    <select aria-label="Müşteri" wire:model.live="linkCustomer" class="max-w-48 rounded-lg border border-gray-300 bg-transparent px-2 py-1.5 text-xs dark:border-gray-700">
                        <option value="">{{ $linkedCustomer ? 'Bağlantıyı kaldır' : 'Müşteriye bağla…' }}</option>
                        @foreach($customerOptions as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                </div>
            </header>

            <div class="min-h-0 flex-1 space-y-2 overflow-y-auto bg-gray-50 px-4 py-4 dark:bg-white/[0.02]" wire:key="wa-messages-{{ $selected->id }}-{{ $messages->currentPage() }}" x-data x-init="$el.scrollTop = $el.scrollHeight">
                @if($messages->hasPages())
                    <div class="text-center text-xs">{{ $messages->links() }}</div>
                @endif
                @foreach($messages->getCollection()->reverse() as $message)
                    <div wire:key="message-{{ $message->id }}" class="flex {{ $message->direction === 'outgoing' ? 'justify-end' : 'justify-start' }}">
                        <div class="max-w-[80%] rounded-2xl px-3 py-2 text-sm shadow-sm {{ $message->direction === 'outgoing' ? 'rounded-br-sm bg-emerald-100 text-gray-900 dark:bg-emerald-500/20 dark:text-gray-100' : 'rounded-bl-sm bg-white text-gray-900 dark:bg-gray-800 dark:text-gray-100' }}">
                            @if($message->reply_to_message_id)<p class="mb-1 text-[11px] text-gray-500">Önceki bir mesaja yanıt</p>@endif
                            <p class="whitespace-pre-wrap break-words">{{ $message->body }}</p>
                            <p class="mt-1 text-right text-[11px] text-gray-500">{{ $message->sent_at->timezone('Europe/Istanbul')->format('d.m H:i') }}</p>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="border-t border-gray-200 p-4 dark:border-gray-800" data-wa-suggestion>
                @if($suggestionFresh)
                    <div wire:key="suggestion-{{ $selected->id }}-{{ $selected->suggested_at?->timestamp }}" x-data="{ copyStatus: '' }" class="space-y-2">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">AI cevap önerisi @if($selected->summary)<span class="font-normal text-gray-500">· {{ $selected->summary }}</span>@endif</p>
                            <button type="button" wire:click="generate({{ $selected->id }})" wire:loading.attr="disabled" class="text-xs text-gray-500 hover:text-brand-600">Yeniden hazırla</button>
                        </div>
                        @if($selected->suggestion_action === 'wait')
                            <p class="rounded-lg px-3 py-2 text-sm ring-1 ring-inset {{ $tone['warn'] }}">Şimdilik yeni mesaj yazmayın; karşı tarafın dönüşünü bekleyin.</p>
                        @endif
                        @if(filled($selected->suggestion))
                            <div wire:ignore>
                                <textarea x-ref="reply" aria-label="Önerilen cevap; kopyalamadan önce düzenleyebilirsiniz" rows="4" class="w-full rounded-lg border border-gray-300 bg-transparent p-3 text-sm dark:border-gray-700">{{ $selected->suggestion }}</textarea>
                            </div>
                            <div class="flex flex-wrap items-center gap-3">
                                <button type="button" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600" @click="navigator.clipboard.writeText($refs.reply.value).then(() => copyStatus = 'Kopyalandı; telefondan gönderebilirsiniz.').catch(() => { $refs.reply.select(); copyStatus = 'Metni seçtim; Ctrl+C ile kopyalayın.' })">Cevabı kopyala</button>
                                <span x-text="copyStatus" role="status" class="text-xs text-gray-500"></span>
                            </div>
                        @endif
                        <details class="text-xs text-gray-500" wire:ignore.self>
                            <summary class="cursor-pointer select-none">Neden bu cevap?</summary>
                            <p class="mt-1">{{ $selected->rationale }}</p>
                            <p class="mt-1">{{ $selected->context_message_count }} mesaj okunarak {{ $when($selected->suggested_at) }} tarihinde hazırlandı.{{ $selected->context_truncated ? ' Uzun görüşme olduğu için en eski mesajların bir kısmı okunmadı.' : '' }}</p>
                        </details>
                    </div>
                @else
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="text-sm">
                            <p class="text-gray-700 dark:text-gray-200">{{ match ($selected->suggestion_status) {
                                'running', 'requested' => 'AI cevap önerisi hazırlanıyor…',
                                'failed' => 'Öneri hazırlanamadı.',
                                default => $fromBackup ? 'Cevap gerektiğinde "Mesaj üret"e basın; beyin ve talimatlarınıza göre hazırlanır.' : 'Bu görüşme için güncel öneri yok.',
                            } }}</p>
                            @if($selected->suggestion_status === 'failed' && $selected->error_code)
                                <p class="text-xs text-gray-500">{{ \App\Services\WhatsApp\WhatsAppSuggestions::ERROR_LABELS[$selected->error_code] ?? $selected->error_code }}</p>
                            @endif
                        </div>
                        <button type="button" wire:click="generate({{ $selected->id }})" wire:loading.attr="disabled" @disabled(in_array($selected->suggestion_status, ['running', 'requested'], true)) class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-50">{{ $selected->suggestion_status === 'failed' ? 'Tekrar dene' : 'Mesaj üret' }}</button>
                    </div>
                @endif
            </div>
        @else
            <div class="flex flex-1 flex-col items-center justify-center gap-2 p-10 text-center">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 12a8 8 0 0 1-11.8 7L4 20l1.1-4.6A8 8 0 1 1 21 12z"/></svg>
                </span>
                <p class="text-sm text-gray-500">Mesajları ve AI cevap önerisini görmek için soldan bir görüşme seçin.</p>
            </div>
        @endif
    </section>
</div>
