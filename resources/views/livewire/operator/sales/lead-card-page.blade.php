@php
    use App\Models\SalesLead;
    use App\Models\SalesLeadEvent;

    $input = 'h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white';
    $area = 'w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm leading-relaxed placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white';
    $stageChip = ['new' => 'chip-flat', 'contacted' => 'chip-flat', 'priced' => 'chip-accent', 'won' => 'chip-up', 'lost' => 'chip-down'];
    $severity = ['high' => 'critical', 'medium' => 'look', 'low' => 'info'];
    $due = $lead->next_step_at?->timezone('Europe/Istanbul');
    $kindIcon = ['message_in' => '←', 'message_out' => '→', 'note' => '✎', 'call' => '☎', 'finding' => '◎', 'report' => '▤', 'meeting' => '◷', 'offer' => '₺', 'stage' => '◆', 'task' => '☐', 'share' => '⇪', 'system' => '·'];
@endphp
{{-- Kişi kartı (2026-11-04): who, where from, stage, next step, the ready text, findings and the whole story. --}}
<div class="pb-24" data-lead-card="{{ $lead->id }}">
    <a href="{{ route('operator.sales') }}" wire:navigate class="link-neutral text-sm">← Satış</a>

    <header class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div class="flex min-w-0 items-center gap-4">
            <x-ta.brand-avatar :name="$lead->title()" size="lg" />
            <div class="min-w-0">
                <h1 class="page-title truncate">{{ $lead->title() }}</h1>
                <p class="page-sub">
                    {{ $lead->subtitle() !== '' ? $lead->subtitle().' · ' : '' }}{{ $lead->sourceLabel() }}
                    @if ($lead->phone) · <a href="tel:{{ $lead->waNumber() ? '+'.$lead->waNumber() : $lead->phone }}" class="link-neutral">{{ $lead->phone }}</a>@endif
                    @if ($lead->website) · <a href="{{ $lead->website }}" target="_blank" rel="noopener" class="link-neutral">{{ $lead->website_host }} ↗</a>@endif
                    @if ($lead->conversation_id) · <a href="{{ route('operator.whatsapp', ['conversation' => $lead->conversation_id, 'tab' => 'inbox']) }}" wire:navigate class="link-neutral">WhatsApp sohbeti</a>@endif
                    @if ($lead->brand_id) · <a href="{{ route('operator.brand', ['brand' => $lead->brand_id]) }}" wire:navigate class="link-neutral">Marka sayfası</a>@endif
                </p>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="chip {{ $stageChip[$lead->stage] ?? 'chip-flat' }}">{{ $lead->stageLabel() }}</span>
            <button type="button" wire:click="edit" class="btn-quiet">Düzenle</button>
        </div>
    </header>

    @if ($message !== '')
        <p class="mt-4 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300" data-lead-message>{{ $message }}</p>
    @endif

    @if ($editing)
        <form wire:submit="save" class="card mt-5 p-4" data-lead-edit>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <label class="block text-xs text-gray-500">Kişi<input wire:model="form.name" type="text" class="mt-1 {{ $input }}" /></label>
                <label class="block text-xs text-gray-500">Klinik<input wire:model="form.clinic" type="text" class="mt-1 {{ $input }}" /></label>
                <label class="block text-xs text-gray-500">Şehir<input wire:model="form.city" type="text" class="mt-1 {{ $input }}" /></label>
                <label class="block text-xs text-gray-500">Branş<input wire:model="form.branch" type="text" placeholder="Diş, estetik, psikoloji…" class="mt-1 {{ $input }}" /></label>
                <label class="block text-xs text-gray-500">Telefon<input wire:model="form.phone" type="tel" class="mt-1 {{ $input }}" /></label>
                <label class="block text-xs text-gray-500">Site<input wire:model="form.website" type="text" class="mt-1 {{ $input }}" />@error('form.website')<span class="text-error-600">{{ $message }}</span>@enderror</label>
                <label class="block text-xs text-gray-500">Nereden geldi
                    <select wire:model="form.source" class="mt-1 {{ $input }}">@foreach (SalesLead::SOURCES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                </label>
                <label class="block text-xs text-gray-500 sm:col-span-2 lg:col-span-4">Notlar<textarea wire:model="form.notes" rows="3" class="mt-1 {{ $area }}"></textarea></label>
            </div>
            <div class="mt-3 flex items-center gap-2">
                <button type="submit" class="btn-dark" wire:loading.attr="disabled">Kaydet</button>
                <button type="button" wire:click="$set('editing', false)" class="btn-quiet">Vazgeç</button>
            </div>
        </form>
    @endif

    <div class="mt-6 grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
        <div class="space-y-5">
            {{-- Sonraki adım + hazır metin --}}
            <section class="card p-4" data-lead-next>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">Sonraki adım</h2>
                    @if ($lead->isOpen() && $due !== null)
                        <span @class(['text-[13px] font-medium', 'text-error-600' => $due->toDateString() < $today, 'text-gray-900 dark:text-white' => $due->toDateString() >= $today])>{{ $due->toDateString() === $today ? 'Bugün' : ($due->toDateString() < $today ? $due->format('d.m').' tarihinden kaldı' : $due->locale('tr')->isoFormat('D MMM dddd')) }}</span>
                    @endif
                </div>
                @if ($lead->isOpen())
                    <form wire:submit="saveNextStep" class="mt-3 flex flex-wrap items-end gap-2">
                        <label class="block min-w-56 flex-1 text-xs text-gray-500">Ne yapacaksın<input wire:model="nextStep" type="text" placeholder="İlk mesaj, 2. gün takibi, arama…" class="mt-1 {{ $input }}" /></label>
                        <label class="block text-xs text-gray-500">Ne zaman<input wire:model="nextStepAt" type="date" class="mt-1 {{ $input }}" /></label>
                        <button type="submit" class="btn" wire:loading.attr="disabled">Kaydet</button>
                    </form>
                    @error('nextStepAt')<p class="mt-1 text-sm text-error-600">{{ $message }}</p>@enderror

                    <div class="mt-5 flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Gönderilecek mesaj <span class="ml-1 text-xs font-normal text-gray-500">Claude yazar, sen düzeltirsin</span></h3>
                        <div class="flex items-center gap-1">
                            <button type="button" wire:click="writeNow" wire:loading.attr="disabled" wire:target="writeNow,pasteChat" class="btn-quiet h-7" data-lead-write>
                                <span wire:loading.remove wire:target="writeNow">{{ filled($lead->draft) ? 'Yeniden yaz' : 'Claude yazsın' }}</span>
                                <span wire:loading wire:target="writeNow">Claude yazıyor… (10-30 sn)</span>
                            </button>
                            <button type="button" wire:click="saveDraft" wire:loading.attr="disabled" class="btn-quiet h-7">Metni kaydet</button>
                        </div>
                    </div>
                    @if ($drafting)
                        <p class="mt-2 text-sm text-gray-500" wire:poll.4s data-lead-drafting>Claude sohbeti ve siteyi okuyup yazıyor…</p>
                    @endif
                    @error('draft')<p class="mt-2 text-sm text-error-600">{{ $message }}</p>@enderror
                    @if ($lead->draft_status === 'ready' && (filled($lead->draft_note) || $lead->draft_send_on !== null))
                        <div class="mt-2 rounded-lg bg-accent-soft px-3 py-2 text-[13px] text-accent-ink" data-lead-ai-note>
                            @if ($lead->draft_send_on !== null)<span class="font-medium">Gönderme günü: {{ $lead->draft_send_on->toDateString() <= $today ? 'bugün' : $lead->draft_send_on->locale('tr')->isoFormat('D MMMM dddd') }}.</span>@endif
                            @if (filled($lead->draft_note)) Claude'un notu: {{ $lead->draft_note }}@endif
                        </div>
                    @endif
                    <textarea wire:model="draft" rows="8" class="mt-2 {{ $area }}" data-lead-draft aria-label="Gönderilecek metin"></textarea>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        @if ($waUrl !== null)
                            <a href="{{ $waUrl }}" target="_blank" rel="noopener" class="btn-primary" data-lead-wa>WhatsApp'ta aç ↗</a>
                        @else
                            <span class="text-[13px] text-gray-500">Telefon yazılınca WhatsApp'ta tek tıkla açılır.</span>
                        @endif
                        <button type="button" x-data x-on:click="navigator.clipboard?.writeText($root.querySelector('[data-lead-draft]').value); $el.textContent = 'Kopyalandı'; setTimeout(() => $el.textContent = 'Kopyala', 1500)" class="btn">Kopyala</button>
                        <button type="button" wire:click="markSent" wire:loading.attr="disabled" class="btn-dark" data-lead-sent>Gönderdim</button>
                        <span class="text-xs text-gray-500">"Gönderdim" metni zaman çizelgesine yazar, takibi kurar.</span>
                    </div>
                    @if (filled($lead->draft_summary))
                        <p class="mt-2 text-xs text-gray-500" data-lead-summary>Durum: {{ $lead->draft_summary }}</p>
                    @endif
                @else
                    <p class="mt-2 text-sm text-gray-500">
                        @if ($lead->stage === 'won')Müşteri oldu{{ $lead->won_at ? ' · '.$lead->won_at->timezone('Europe/Istanbul')->format('d.m.Y') : '' }}.@else Kapandı{{ $lead->lost_at ? ' · '.$lead->lost_at->timezone('Europe/Istanbul')->format('d.m.Y') : '' }} · {{ SalesLead::LOST_REASONS[$lead->lost_reason] ?? '' }}@endif
                        <button type="button" wire:click="setStage('new')" class="link-neutral ml-1">Yeniden aç</button>
                    </p>
                @endif
            </section>

            {{-- Sohbeti yapıştır: WhatsApp'tan kopyalanan sohbet karta işlenir, Claude hemen cevabı yazar. --}}
            <section class="card p-4" data-lead-paste x-data="{ open: {{ $errors->has('paste') ? 'true' : 'false' }} }">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Sohbeti yapıştır</h2>
                        <p class="text-[13px] text-gray-500">WhatsApp'ta mesajları seç, kopyala, buraya yapıştır; Claude son mesaja cevabı yazar.</p>
                    </div>
                    <button type="button" x-on:click="open = ! open" class="btn h-8" x-text="open ? 'Kapat' : 'Yapıştır'">Yapıştır</button>
                </div>
                <form wire:submit="pasteChat" x-show="open" x-cloak class="mt-3 space-y-2">
                    <textarea wire:model="paste" rows="6" class="{{ $area }}" placeholder="[22:35, 30.10.2026] Yaşar Özdemir: Fiyata KDV dahil mi?" aria-label="Sohbet"></textarea>
                    <input wire:model="pasteNote" type="text" class="{{ $input }}" placeholder="Claude'a not (isteğe bağlı): telefonda konuştuk, fiyatı uygun buldu…" aria-label="Claude'a not" />
                    @error('paste')<p class="text-sm text-error-600">{{ $message }}</p>@enderror
                    <button type="submit" class="btn-dark" wire:loading.attr="disabled" wire:target="pasteChat,writeNow">
                        <span wire:loading.remove wire:target="pasteChat">Karta işle ve cevabı yaz</span>
                        <span wire:loading wire:target="pasteChat">Claude yazıyor… (10-30 sn)</span>
                    </button>
                </form>
            </section>

            {{-- Not / arama --}}
            <section class="card p-4" data-lead-note>
                <form wire:submit="addNote" class="flex flex-wrap items-end gap-2">
                    <label class="block min-w-56 flex-1 text-xs text-gray-500">Not ya da arama<input wire:model="note" type="text" placeholder='"Aradım, pazartesi dönecek" ya da "Fiyatı sordu, 12.000 dedim"' class="mt-1 {{ $input }}" /></label>
                    <button type="submit" class="btn" wire:loading.attr="disabled">Ekle</button>
                </form>
            </section>

            {{-- Zaman çizelgesi --}}
            <section data-lead-timeline>
                <div class="section-head">
                    <h2 class="section-title">Ne oldu <span class="section-count">{{ $timeline->count() }}</span></h2>
                    <p class="text-sm text-gray-500">Mesajlar, notlar, aramalar, bulgular ve aşama değişiklikleri; en yeni üstte.</p>
                </div>
                <div class="card">
                    @forelse ($timeline as $row)
                        @php $kind = $row['kind']; $in = $kind === 'message_in'; $out = $kind === 'message_out'; @endphp
                        <div class="flex gap-3 border-t border-gray-200 px-4 py-3 first:border-t-0 dark:border-gray-800">
                            <span @class(['row-icon text-sm', 'bg-accent-soft text-accent-ink border-transparent' => $out, 'bg-gray-900 text-white dark:bg-white dark:text-gray-900 border-transparent' => $in])>{{ $kindIcon[$kind] ?? '·' }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="text-xs text-gray-500"><span class="font-medium text-gray-700 dark:text-gray-300">{{ $in ? ($lead->title()) : ($out ? 'Sen' : (SalesLeadEvent::KINDS[$kind] ?? $kind)) }}</span> · {{ $row['at']->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}@if (! empty($row['meta']['whatsapp'])) · WhatsApp @endif</p>
                                <p class="mt-0.5 whitespace-pre-wrap text-sm text-gray-800 dark:text-gray-200">{{ $row['body'] !== '' ? $row['body'] : '(ek: '.($row['meta']['type'] ?? 'dosya').')' }}</p>
                            </div>
                        </div>
                    @empty
                        <x-ta.empty-state title="Henüz bir şey olmadı" message="İlk metni gönderip 'Gönderdim' dediğinde ya da bir not eklediğinde burada görünür." />
                    @endforelse
                </div>
            </section>
        </div>

        <div class="space-y-5">
            {{-- Aşama --}}
            <section class="card p-4" data-lead-stage>
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">Aşama</h2>
                <div class="mt-3 flex flex-wrap gap-1.5">
                    @foreach (['new', 'contacted', 'priced'] as $stage)
                        <button type="button" wire:click="setStage('{{ $stage }}')" @class(['seg h-8', 'seg-active' => $lead->stage === $stage])>{{ SalesLead::STAGES[$stage] }}</button>
                    @endforeach
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    @if ($isAdmin && $lead->stage !== 'won')
                        <button type="button" wire:click="win" wire:confirm="{{ $lead->title() }} müşteri olsun mu? Müşteri ve marka kaydı açılır." class="btn-dark" data-lead-win>Kazanıldı: müşteri yap</button>
                    @endif
                    @if ($lead->stage !== 'lost')
                        <div class="flex items-center gap-1">
                            <select wire:model="lostReason" class="h-8 rounded-lg border border-gray-200 bg-white px-2 text-[13px] dark:border-gray-700 dark:bg-transparent dark:text-gray-200" aria-label="Kayıp nedeni">
                                @foreach (SalesLead::LOST_REASONS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                            </select>
                            <button type="button" wire:click="setStage('lost')" class="btn-quiet">Kapat</button>
                        </div>
                    @endif
                </div>
                @if ($lead->notes)
                    <p class="mt-4 whitespace-pre-wrap border-t border-gray-200 pt-3 text-sm text-gray-700 dark:border-gray-800 dark:text-gray-300">{{ $lead->notes }}</p>
                @endif
            </section>

            {{-- Bulgular --}}
            <section class="card p-4" data-lead-findings>
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">Sitede ne gördük</h2>
                    @if ($lead->website_host !== null)
                        <button type="button" wire:click="checkSite" wire:loading.attr="disabled" class="btn-quiet h-7">{{ $findings === [] ? 'Kontrol et' : 'Yeniden bak' }}</button>
                    @endif
                </div>
                @if ($lead->website_host === null)
                    <p class="mt-2 text-sm text-gray-500">Site yazılınca ana sayfası okunur; mesajda kullanacağın bulgular burada çıkar.</p>
                @elseif ($lead->findings_status === 'queued' || $lead->findings_status === 'running')
                    <p class="mt-2 text-sm text-gray-500" wire:poll.4s>{{ $lead->website_host }} okunuyor…</p>
                @elseif ($lead->findings_status === 'failed')
                    <p class="mt-2 text-sm text-gray-500">{{ $lead->website_host }} buradan okunamadı (sunucu botlara kapalı ya da yanıt vermedi). Telefondan bir bak; bulgu yazmadan gönderme.</p>
                @elseif ($findings === [])
                    <p class="mt-2 text-sm text-gray-500">Belirgin bir eksik bulunmadı. Konuyu Google İşletme Profili ve yorumlar üzerinden aç.</p>
                @else
                    <ul class="mt-3 space-y-2.5">
                        @foreach ($findings as $finding)
                            <li class="text-sm">
                                <x-ta.pill :tone="$severity[$finding['severity'] ?? 'low'] ?? 'info'">{{ $finding['title'] ?? '' }}</x-ta.pill>
                                <p class="mt-0.5 text-[13px] text-gray-600 dark:text-gray-300">{{ $finding['consequence'] ?? '' }}@if (! empty($finding['fix'])) <span class="text-gray-500">→ {{ $finding['fix'] }}</span>@endif</p>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-3 text-xs text-gray-500">{{ $lead->findings_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i') }} · kendi okumamız, tahmin değil.</p>
                @endif
                @if ($scan !== null && ($scan['pages_read'] ?? 0) > 0)
                    @php
                        $read = (int) $scan['pages_read'];
                        $rows = array_filter([
                            'Okunan sayfa' => $read.(($scan['pages_in_sitemap'] ?? 0) > $read ? ' / '.$scan['pages_in_sitemap'] : ''),
                            'Başlığında şehir yok' => $lead->city ? ($scan['titles_without_city'] ?? null) : null,
                            'Açıklaması boş' => $scan['descriptions_missing'] ?? null,
                            'Başlığı yok / çok uzun' => isset($scan['titles_missing']) ? ($scan['titles_missing'].' / '.($scan['titles_too_long'] ?? 0)) : null,
                            'Ana başlığı (H1) yok' => $scan['h1_missing'] ?? null,
                            'Aynı başlığı taşıyan' => ($n = array_sum(array_column((array) ($scan['duplicate_titles'] ?? []), 'pages'))) > 0 ? $n.' sayfa' : null,
                            'Tek dokunuşla arama' => isset($scan['pages_with_tap_to_call']) ? $scan['pages_with_tap_to_call'].' sayfada' : null,
                            'WhatsApp butonu' => isset($scan['pages_with_whatsapp_button']) ? $scan['pages_with_whatsapp_button'].' sayfada' : null,
                            'Randevu platformu' => implode(', ', (array) ($scan['booking_platform_links'] ?? [])) ?: null,
                            'Diller' => implode(', ', array_unique(array_merge((array) ($scan['page_languages'] ?? []), (array) ($scan['hreflang_languages'] ?? []), (array) ($scan['language_subdomains'] ?? [])))) ?: null,
                        ], fn ($v) => $v !== null && $v !== '');
                    @endphp
                    <dl class="mt-4 grid grid-cols-[1fr_auto] gap-x-3 gap-y-1 border-t border-gray-200 pt-3 text-[13px] dark:border-gray-800" data-lead-scan>
                        @foreach ($rows as $label => $value)
                            <dt class="text-gray-500">{{ $label }}</dt><dd class="text-right tabular-nums text-gray-800 dark:text-gray-200">{{ $value }}</dd>
                        @endforeach
                    </dl>
                    <p class="mt-2 text-xs text-gray-500">Sitenin tüm sayfalarından sayım; Claude mesajda bu sayıları kullanır.</p>
                @endif
            </section>

            {{-- Kısa bilgi --}}
            <section class="card p-4 text-[13px] text-gray-600 dark:text-gray-300" data-lead-facts>
                <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5">
                    <dt class="text-gray-500">Açıldı</dt><dd>{{ $lead->created_at?->timezone('Europe/Istanbul')->format('d.m.Y') }}</dd>
                    <dt class="text-gray-500">Son temas</dt><dd>{{ $lead->last_contact_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i') ?? 'yok' }}</dd>
                    <dt class="text-gray-500">Son gelen</dt><dd>{{ $lead->last_incoming_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i') ?? 'yok' }}</dd>
                    <dt class="text-gray-500">Takip</dt><dd>{{ $lead->followup_step > 0 ? $lead->followup_step.'. takip gönderildi' : 'ilk mesaj aşaması' }}</dd>
                </dl>
            </section>
        </div>
    </div>
</div>
