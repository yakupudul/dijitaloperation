@php
    use App\Models\SalesLead;
    use App\Models\SalesLeadEvent;

    $input = 'h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white';
    $area = 'w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm leading-relaxed placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white';
    $stageChip = ['new' => 'chip-flat', 'contacted' => 'chip-flat', 'talking' => 'chip-accent', 'meeting' => 'chip-accent', 'priced' => 'chip-accent', 'thinking' => 'chip-accent', 'later' => 'chip-flat', 'won' => 'chip-up', 'lost' => 'chip-down'];
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
            @if ($lead->isCustomer())<span class="chip chip-up" data-lead-lifecycle>Müşteri</span>@elseif ($lead->lifecycle === 'former')<span class="chip chip-flat" data-lead-lifecycle>Eski müşteri</span>@elseif ($lead->isIrrelevant())<span class="chip chip-flat" data-lead-lifecycle>İlgisiz</span>@endif
            <span class="chip {{ $stageChip[$lead->stage] ?? 'chip-flat' }}">{{ $lead->stageLabel() }}</span>
            {{-- Tür: what kind of business this record is (never assumed to be a clinic). --}}
            @if ($lead->kind !== null)
                <span class="chip chip-flat" data-lead-kind="{{ $lead->kind }}" title="Tür{{ $lead->sectorCategory ? ' · Sektör: '.$lead->sectorCategory->name : '' }}">{{ $lead->kindLabel() }}{{ $lead->sectorCategory ? ' · '.$lead->sectorCategory->name : '' }}</span>
            @elseif ($lead->suggestedKind() !== null && \App\Support\Sales\LeadKinds::has($lead->suggestedKind()))
                <span class="chip chip-flat" data-lead-kind-suggested="{{ $lead->suggestedKind() }}">{{ \App\Support\Sales\LeadKinds::label($lead->suggestedKind()) }}? <button type="button" wire:click="confirmKind" class="ml-1 font-semibold underline" data-lead-kind-confirm>Doğru</button></span>
            @else
                <button type="button" wire:click="edit" class="chip chip-flat" data-lead-kind-missing>Tür seçilmedi</button>
            @endif
            <a href="{{ route('operator.calendar', ['aday' => $lead->id]) }}" wire:navigate class="btn-quiet" data-lead-meeting>Toplantı ekle</a>
            <button type="button" wire:click="edit" class="btn-quiet">Düzenle</button>
        </div>
    </header>

    @if ($message !== '')
        <p class="mt-4 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300" data-lead-message>{{ $message }}</p>
    @endif

    @if ($duplicate)
        <div class="mt-4 flex flex-wrap items-center gap-3 rounded-lg border border-warning-200 bg-warning-50 px-3 py-2 text-sm text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-200" data-lead-duplicate>
            <span>Aynı siteden başka bir kart var: <a href="{{ route('operator.sales.lead', ['lead' => $duplicate->id]) }}" wire:navigate class="font-medium underline">{{ $duplicate->title() }}</a>. Aynı kişiyse birleştir.</span>
            <span class="ml-auto flex gap-2">
                <button type="button" wire:click="mergeDuplicate" wire:confirm="İki kart birleşsin mi? Diğer kartın konuşmaları ve işleri bu karta taşınır." class="btn">Birleştir</button>
                <button type="button" wire:click="notDuplicate" class="btn-quiet">Farklı kişi</button>
            </span>
        </div>
    @endif

    @if ($editing)
        <form wire:submit="save" class="card mt-5 p-4" data-lead-edit>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <label class="block text-xs text-gray-500">Kişi<input wire:model="form.name" type="text" class="mt-1 {{ $input }}" /></label>
                <label class="block text-xs text-gray-500">İşletme<input wire:model="form.company" type="text" placeholder="Klinik, fabrika, mağaza… adı" class="mt-1 {{ $input }}" /></label>
                <label class="block text-xs text-gray-500">Tür
                    <select wire:model="form.kind" class="mt-1 {{ $input }}" data-lead-kind-select><option value="">Seçilmedi</option>@foreach ($kinds as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                </label>
                <label class="block text-xs text-gray-500">Sektör
                    <select wire:model="form.sector_id" class="mt-1 {{ $input }}" data-lead-sector-select><option value="">Seçilmedi</option>@foreach ($sectors as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select>
                </label>
                <label class="block text-xs text-gray-500">Şehir<input wire:model="form.city" type="text" class="mt-1 {{ $input }}" /></label>
                <label class="block text-xs text-gray-500">Branş<input wire:model="form.branch" type="text" placeholder="Diş, estetik, psikoloji…" class="mt-1 {{ $input }}" /></label>
                <label class="block text-xs text-gray-500">Telefon<input wire:model="form.phone" type="tel" class="mt-1 {{ $input }}" /></label>
                <label class="block text-xs text-gray-500">E-posta<input wire:model="form.email" type="email" class="mt-1 {{ $input }}" />@error('form.email')<span class="text-error-600">{{ $message }}</span>@enderror</label>
                <label class="block text-xs text-gray-500">Site<input wire:model="form.website" type="text" class="mt-1 {{ $input }}" />@error('form.website')<span class="text-error-600">{{ $message }}</span>@enderror</label>
                <label class="block text-xs text-gray-500">Kanal (nereden yazdı)
                    <select wire:model="form.source" class="mt-1 {{ $input }}">@foreach (SalesLead::SOURCES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                </label>
                <label class="block text-xs text-gray-500">Kaynak (bizi nereden duydu)
                    <select wire:model="form.heard_from" class="mt-1 {{ $input }}"><option value="">Bilinmiyor</option>@foreach (SalesLead::HEARD_FROM as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
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
                        <p class="mt-2 text-sm text-gray-500" wire:poll.6s.visible data-lead-drafting>Claude sohbeti ve siteyi okuyup yazıyor…</p>
                    @elseif ($lead->draft_status === 'waiting')
                        <p class="mt-2 text-sm text-gray-500" data-lead-draft-waiting>{{ $lead->draft_note ?: 'Abonelikteki Claude yazacak.' }}</p>
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
                        @elseif (filled($lead->phone))
                            <span class="text-[13px] text-error-600" data-lead-phone-issue>Numara hatalı, WhatsApp açılmaz: {{ $lead->phoneIssue() }}{{ filled($lead->email) ? ' E-posta ile dönebilirsiniz: '.$lead->email : '' }}</span>
                        @else
                            <span class="text-[13px] text-gray-500">Telefon yazılınca WhatsApp'ta tek tıkla açılır.</span>
                        @endif
                        @if ($waUrl !== null && $lead->phoneIssue())<span class="text-[13px] text-warning-700">{{ $lead->phoneIssue() }}</span>@endif
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
            {{-- Yeni aday araştırması (2026-11-08): Claude web'de işletmeyi bulur (site, Instagram, Google harita kaydı). --}}
            @php
                $research = is_array($lead->research) ? $lead->research : null;
            @endphp
            <section class="card p-4" data-lead-research>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Dijital analiz & bulgular</h2>
                        <p class="text-[13px] text-gray-500">
                            @if ($research)
                                {{ $research['business_name'] ?: 'İşletme bulunamadı' }}{{ $research['city'] ? ' · '.$research['city'] : '' }} · {{ $lead->research_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}
                            @else
                                Yeni bir numara yazınca Claude isimden ve numaradan web'de arar: site, Instagram, Google harita kaydı.
                            @endif
                        </p>
                    </div>
                    <button type="button" wire:click="research" wire:loading.attr="disabled" class="btn-quiet h-8" @disabled($researching) data-lead-research-run>{{ $research ? 'Yeniden araştır' : 'Araştır' }}</button>
                </div>
                @if ($researching)
                    <p class="mt-2 text-sm text-gray-500" wire:poll.10s.visible data-lead-researching>Claude web'de arıyor, siteyi ve profilleri açıyor (1-3 dk)…</p>
                @elseif ($lead->research_note)
                    <p class="mt-2 text-sm {{ $lead->research_status === 'failed' ? 'text-error-600' : 'text-warning-700 dark:text-warning-400' }}" data-lead-research-note>{{ $lead->research_note }}</p>
                @endif
                @if ($research)
                    <div class="mt-3 space-y-2 text-sm text-gray-700 dark:text-gray-300">
                        <div class="flex flex-wrap gap-2">
                            @foreach (['website' => 'Site', 'instagram' => 'Instagram', 'google_maps' => 'Harita kaydı'] as $key => $label)
                                @if ($research[$key])
                                    <a href="{{ preg_match('#^https?://#i', $research[$key]) ? $research[$key] : 'https://'.$research[$key] }}" target="_blank" rel="noopener noreferrer" class="btn h-7 text-xs">{{ $label }}</a>
                                @else
                                    <span class="inline-flex h-7 items-center rounded-md px-2 text-xs text-gray-400 ring-1 ring-gray-200 dark:ring-gray-700">{{ $label }} yok</span>
                                @endif
                            @endforeach
                        </div>
                        @php
                $maps = array_filter($research['maps'] ?? []);
            @endphp
                        @if ($maps)
                            <p class="text-[13px]"><span class="text-gray-500">Harita kaydı:</span> {{ implode(' · ', array_filter([($maps['rating'] ?? '') ? $maps['rating'].' puan' : null, ($maps['reviews'] ?? '') ? $maps['reviews'].' yorum' : null, $maps['category'] ?? null, $maps['address'] ?? null])) }}</p>
                        @endif
                        @php
                $ig = array_filter($research['instagram_facts'] ?? []);
            @endphp
                        @if ($ig)
                            <p class="text-[13px]"><span class="text-gray-500">Instagram:</span> {{ implode(' · ', array_filter([($ig['followers'] ?? '') ? $ig['followers'].' takipçi' : null, ($ig['posts'] ?? '') ? $ig['posts'].' gönderi' : null, ($ig['last_post'] ?? '') ? 'son gönderi '.$ig['last_post'] : null])) }}</p>
                        @endif
                        @if ($research['findings'] ?? [])
                            <ol class="list-decimal space-y-1 pl-5 text-[13px]" data-lead-research-findings>
                                @foreach ($research['findings'] as $finding)
                                    <li><span class="font-medium text-gray-900 dark:text-white">{{ $finding['title'] }}</span> <span class="text-gray-600 dark:text-gray-400">{{ $finding['detail'] }}</span></li>
                                @endforeach
                            </ol>
                        @endif
                    </div>
                @endif
            </section>

            {{-- Tam analiz (3. hafta): yakup için tam yazılı analiz; müşteriye yalnız tek bulgulu mesaj gider. --}}
            <section class="card p-4" data-lead-analysis x-data="{ open: false }">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Tam analiz</h2>
                        <p class="text-[13px] text-gray-500">
                            @if ($lead->analysis)
                                {{ $lead->analysis_headline ?: 'Site, konuşma, plan, teklif ve riskler.' }} · {{ $lead->analysis_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}
                            @else
                                Site, konuşma ve notlardan: ne gördük, ne yaparız, teklif, görüşmede sorulacaklar, riskler. Aday konuşmaya başlayınca kendiliğinden yazılır.
                            @endif
                        </p>
                    </div>
                    <div class="flex items-center gap-1">
                        @if ($lead->analysis)
                            <button type="button" x-on:click="open = ! open" class="btn h-8" x-text="open ? 'Kapat' : 'Oku'">Oku</button>
                        @endif
                        <button type="button" wire:click="writeAnalysis" wire:loading.attr="disabled" class="btn-quiet h-8" @disabled($analysing) data-lead-analysis-write>{{ $lead->analysis ? 'Yeniden yaz' : 'Tam analiz yaz' }}</button>
                    </div>
                </div>
                @if ($analysing)
                    <p class="mt-2 text-sm text-gray-500" wire:poll.10s.visible data-lead-analysing>Claude siteyi, konuşmayı ve notları okuyup analizi yazıyor (1-2 dk)…</p>
                @elseif ($lead->analysis_status === 'waiting')
                    <p class="mt-2 text-sm text-gray-500" data-lead-analysis-waiting>{{ \Illuminate\Support\Str::after((string) $lead->analysis_note, 'auto: ') ?: 'Abonelikteki Claude yazacak.' }}</p>
                @elseif ($lead->analysis_status === 'failed' && $lead->analysis_note)
                    <p class="mt-2 text-sm text-error-600">{{ $lead->analysis_note }}</p>
                @endif
                @if ($lead->analysis)
                    <div x-show="open" x-cloak class="mt-3 border-t border-gray-100 pt-3 dark:border-gray-800">
                        <div class="max-w-none text-sm leading-relaxed text-gray-700 dark:text-gray-300 [&_h2]:mt-4 [&_h2]:mb-1 [&_h2]:text-[13px] [&_h2]:font-semibold [&_h2]:text-gray-900 dark:[&_h2]:text-white [&_h2:first-child]:mt-0 [&_h3]:mt-3 [&_h3]:font-semibold [&_p]:mt-1 [&_ul]:mt-1 [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:mt-1 [&_ol]:list-decimal [&_ol]:pl-5 [&_li]:mt-0.5 [&_strong]:font-semibold [&_strong]:text-gray-900 dark:[&_strong]:text-white" data-lead-analysis-text>{!! \Illuminate\Support\Str::markdown((string) $lead->analysis, ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>
                        @if ($lead->analysis_message)
                            <div class="mt-4 rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                                <p class="text-xs font-medium text-gray-500">Müşteriye gidecek mesaj</p>
                                <p class="mt-1 whitespace-pre-wrap text-sm text-gray-800 dark:text-gray-200">{{ $lead->analysis_message }}</p>
                                <button type="button" wire:click="useAnalysisMessage" class="btn mt-2 h-8">Gönderilecek mesaja koy</button>
                            </div>
                        @endif
                    </div>
                @endif
            </section>

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
                    <h2 class="section-title">İrtibat geçmişi <span class="section-count">{{ $timeline->count() }}</span></h2>
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
            {{-- Kişi bilgileri (yakup 2026-11-08): who the person is, the six statuses of Satış and who sent them. --}}
            <section class="card p-4" data-lead-person>
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">Kişi bilgileri</h2>
                <dl class="mt-2 space-y-1.5 text-[13px]">
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">Ad</dt><dd class="text-right text-gray-900 dark:text-white">{{ $lead->name ?: 'Bilinmiyor' }}</dd></div>
                    @if ($lead->company)<div class="flex justify-between gap-3"><dt class="text-gray-500">İşletme</dt><dd class="text-right text-gray-900 dark:text-white">{{ $lead->company }}</dd></div>@endif
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">WhatsApp</dt><dd class="text-right text-gray-900 dark:text-white">{{ $lead->phone ?: '–' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">E-posta</dt><dd class="text-right break-all text-gray-900 dark:text-white">{{ $lead->email ?: '–' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">Kaynak</dt><dd class="text-right text-gray-900 dark:text-white">{{ $lead->sourceLabel() }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">Kimden</dt><dd class="text-right text-gray-900 dark:text-white" data-lead-came-via>{{ $lead->cameViaLabel() ?: 'Moximu' }}</dd></div>
                </dl>
                <p class="mt-3 text-xs text-gray-500">Durum</p>
                <div class="mt-1 flex flex-wrap gap-1.5" data-lead-status>
                    @foreach (SalesLead::STATUSES as $key => $label)
                        <button type="button" wire:click="setStatus('{{ $key }}')" @class(['seg h-8', 'seg-active' => $lead->status() === $key]) data-lead-status-option="{{ $key }}">{{ $label }}</button>
                    @endforeach
                </div>
                @if ($lead->strong_hint && ! $lead->is_strong && $lead->isOpen())<p class="mt-2 text-xs text-gray-500" data-lead-strong-hint>Claude güçlü aday diyor: {{ $lead->strong_hint }}</p>@endif
            </section>

            {{-- Aşama --}}
            <section class="card p-4" data-lead-stage>
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">Görüşme aşaması</h2>
                <p class="mt-1 text-xs text-gray-500">Mesaj, yanıt, toplantı ve fiyat görüldükçe kendiliğinden ilerler; burada seçtiğin kazanır. Durum yukarıdaki tek yerden değişir.</p>
                <div class="mt-3 flex flex-wrap gap-1.5">
                    @foreach (SalesLead::FLOW as $stage)
                        <button type="button" wire:click="setStage('{{ $stage }}')" @class(['seg h-8', 'seg-active' => $lead->stage === $stage]) data-lead-stage-option="{{ $stage }}">{{ SalesLead::STAGES[$stage] }}</button>
                    @endforeach
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-1.5" data-lead-later>
                    <span class="text-[13px] text-gray-500">{{ $lead->stage === 'later' ? 'Sonra: '.($lead->next_step_at?->timezone('Europe/Istanbul')->format('d.m.Y') ?? '') : 'Şimdi değil, sonra:' }}</span>
                    <input wire:model="laterDate" type="date" class="h-8 rounded-lg border border-gray-200 bg-white px-2 text-[13px] dark:border-gray-700 dark:bg-transparent dark:text-gray-200" aria-label="Ne zaman tekrar yazılsın" />
                    <button type="button" wire:click="later" class="btn-quiet">Ertele</button>
                    @error('laterDate')<span class="text-xs text-error-600">{{ $message }}</span>@enderror
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    @if ($isAdmin && $lead->stage !== 'won' && ! $winning)
                        <button type="button" wire:click="openWin" class="btn-dark" data-lead-win>{{ $lead->isCustomer() ? 'Fırsatı kazan' : 'Kazanıldı: müşteri yap' }}</button>
                    @endif
                    @if ($lead->stage !== 'lost')
                        <div class="flex items-center gap-1">
                            <select wire:model="lostReason" class="h-8 rounded-lg border border-gray-200 bg-white px-2 text-[13px] dark:border-gray-700 dark:bg-transparent dark:text-gray-200" aria-label="Kayıp nedeni">
                                <option value="">Neden?</option>
                                @foreach (SalesLead::LOST_REASONS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                            </select>
                            <button type="button" wire:click="setStage('lost')" class="btn-quiet">{{ $lead->isCustomer() ? 'Fırsatı kapat' : 'Kapat' }}</button>
                        </div>
                        @error('lostReason')<span class="text-xs text-error-600">{{ $message }}</span>@enderror
                    @endif
                    @if (! $lead->isCustomer() && ! $lead->isIrrelevant())
                        <button type="button" wire:click="markIrrelevant" wire:confirm="Bu kayıt aday değil mi (tedarikçi, tanıdık, spam)? Listelerden ve raporlardan çıkar." class="btn-quiet text-gray-400" data-lead-irrelevant title="Aday değil: listelerden ve raporlardan çıkar">İlgisiz</button>
                    @endif
                </div>
                @if ($winning)
                    {{-- Kazanıldı: the brand's terms (aylık / dönemlik / tek seferlik) start with the win; the same form as on the brand page. --}}
                    <form wire:submit="win" class="mt-3 space-y-3 rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-white/[0.03]" data-lead-win-form>
                        <p class="text-[13px] font-medium text-gray-900 dark:text-white">{{ $lead->isCustomer() ? 'Yeni anlaşma' : 'Müşteri ve marka açılır; anlaşma şu şartlarla başlar:' }}</p>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach (\App\Models\Agreement::KINDS as $key => $label)
                                <button type="button" wire:click="$set('agreement.kind', '{{ $key }}')" @class(['seg h-8 text-[13px]', 'seg-active' => $agreement['kind'] === $key])>{{ $label }}</button>
                            @endforeach
                        </div>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            <label class="block text-xs text-gray-500">{{ $agreement['kind'] === 'one_time' ? 'Tutar (₺, KDV hariç)' : 'Aylık ücret (₺, KDV hariç)' }}<input wire:model="agreement.fee" type="text" inputmode="numeric" placeholder="14.000" class="mt-1 {{ $input }}" data-lead-win-fee />@error('fee')<span class="text-error-600">{{ $message }}</span>@enderror</label>
                            @if ($agreement['kind'] === 'term')
                                <label class="block text-xs text-gray-500">Dönem (ay)<input wire:model="agreement.term_months" type="number" min="1" max="36" class="mt-1 {{ $input }}" />@error('term_months')<span class="text-error-600">{{ $message }}</span>@enderror</label>
                            @endif
                            <label class="block text-xs text-gray-500">Başlangıç<input wire:model="agreement.starts_on" type="date" class="mt-1 {{ $input }}" />@error('starts_on')<span class="text-error-600">{{ $message }}</span>@enderror</label>
                            @if ($agreement['kind'] !== 'one_time')
                                <label class="block text-xs text-gray-500">Ödeme günü<input wire:model="agreement.due_day" type="number" min="1" max="28" class="mt-1 {{ $input }}" />@error('due_day')<span class="text-error-600">{{ $message }}</span>@enderror</label>
                            @endif
                        </div>
                        <div class="flex flex-wrap gap-x-3 gap-y-1 text-[13px]">
                            @foreach (SalesLead::SERVICES as $key => $label)
                                <label class="inline-flex items-center gap-1.5"><input type="checkbox" wire:model="agreement.services" value="{{ $key }}" class="rounded border-gray-300" /> {{ $label }}</label>
                            @endforeach
                        </div>
                        <input wire:model="agreement.note" type="text" placeholder="Not (ör. 6 ayda site hediye)" class="{{ $input }}" />
                        <div class="flex items-center gap-2">
                            <button type="submit" class="btn-dark" wire:loading.attr="disabled" data-lead-win-save>{{ $lead->isCustomer() ? 'Fırsatı kazan' : 'Müşteri yap' }}</button>
                            <button type="button" wire:click="cancelWin" class="btn-quiet">Vazgeç</button>
                            <span class="text-xs text-gray-400">Ücret boşsa anlaşma sonra marka sayfasından girilir.</span>
                        </div>
                    </form>
                @endif
                @if ($closing !== null)
                    {{-- Anlaşma adımları (Satış Düzeni 3.1): "Kazanıldı" is five steps; the open one carries its own action. --}}
                    <div class="mt-4 border-t border-gray-200 pt-3 dark:border-gray-800" data-lead-closing="{{ $closing['current'] ?? 'bitti' }}">
                        <p class="text-[13px] font-medium text-gray-900 dark:text-white">Anlaşma adımları{{ $closing['finished'] ? ' tamam' : '' }}</p>
                        <ol class="mt-2 space-y-1.5">
                            @foreach ($closing['steps'] as $step)
                                <li class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px]" data-lead-closing-step="{{ $step['key'] }}" data-done="{{ $step['done'] ? '1' : '0' }}">
                                    <span @class(['inline-flex size-5 shrink-0 items-center justify-center rounded-full text-[11px]', 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' => $step['done'], 'border border-gray-300 text-gray-400 dark:border-gray-700' => ! $step['done']]) aria-hidden="true">{{ $step['done'] ? '✓' : $loop->iteration }}</span>
                                    <span @class(['font-medium text-gray-900 dark:text-white' => $step['key'] === $closing['current'], 'text-gray-500' => $step['key'] !== $closing['current']])>{{ $step['label'] }}</span>
                                    @if ($step['note'] !== '')<span class="text-gray-500">{{ $step['note'] }}</span>@endif
                                    @if ($isAdmin && $step['key'] === 'contract' && $closing['agreement'] !== null)
                                        @if ($step['done'])
                                            <button type="button" wire:click="contractSigned(false)" class="link-neutral text-xs">geri al</button>
                                        @else
                                            <button type="button" wire:click="contractSigned" class="btn h-7 px-2.5 text-[13px]" data-lead-closing-contract>Sözleşme imzalandı</button>
                                        @endif
                                    @elseif ($isAdmin && $step['key'] === 'payment' && ! $step['done'] && $closing['agreement'] !== null)
                                        <button type="button" wire:click="firstPaymentTaken" class="btn h-7 px-2.5 text-[13px]" data-lead-closing-payment>İlk ödeme alındı</button>
                                    @elseif ($step['key'] === 'access' && ! $step['done'])
                                        <a href="{{ route('operator.integrations.connection-health', ['gorunum' => 'harita']) }}" wire:navigate class="link-neutral">Erişim iste</a>
                                    @elseif ($step['key'] === 'setup' && ! $step['done'] && $brand)
                                        <a href="{{ route('operator.brand', ['brand' => $brand->id]) }}" wire:navigate class="link-neutral">Marka sayfası</a>
                                    @elseif (in_array($step['key'], ['contract', 'payment'], true) && $closing['agreement'] === null && $brand)
                                        <a href="{{ route('operator.brand', ['brand' => $brand->id]) }}" wire:navigate class="link-neutral">Anlaşmayı gir</a>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    </div>
                @endif
                @if ($lead->offer_amount !== null)
                    <p class="mt-3 text-sm text-gray-700 dark:text-gray-300" data-lead-offer>Teklif: <span class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ number_format($lead->offer_amount, 0, ',', '.') }} ₺/ay</span>@if ($lead->serviceLabel()) · {{ $lead->serviceLabel() }}@endif @if (! $lead->offer_confirmed)<span class="text-xs text-gray-400">(mesajdan okundu)</span>@endif</p>
                @endif
                @if ($lead->notes)
                    <p class="mt-4 whitespace-pre-wrap border-t border-gray-200 pt-3 text-sm text-gray-700 dark:border-gray-800 dark:text-gray-300">{{ $lead->notes }}</p>
                @endif
            </section>

            {{-- Fırsatlar: the sale being pursued now, and the ones won and lost (an upsell to a customer opens here). --}}
            <section class="card p-4" data-lead-opportunities>
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">Fırsatlar</h2>
                    @if ($openOpportunity === null && ! $lead->isIrrelevant())
                        <button type="button" wire:click="$toggle('opening')" class="btn h-8" data-lead-opportunity-new>+ Yeni fırsat</button>
                    @endif
                </div>
                @if ($opening)
                    <form wire:submit="newOpportunity" class="mt-3 space-y-2 rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-white/[0.03]" data-lead-opportunity-form>
                        <div class="grid grid-cols-2 gap-2">
                            <label class="block text-xs text-gray-500">Hizmet<select wire:model="opp.service" class="mt-1 {{ $input }}"><option value="">Seç</option>@foreach (SalesLead::SERVICES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
                            <label class="block text-xs text-gray-500">Tutar (₺)<input wire:model="opp.amount" type="text" inputmode="numeric" placeholder="18.000" class="mt-1 {{ $input }}" /></label>
                        </div>
                        <label class="block text-xs text-gray-500">Başlık (isteğe bağlı)<input wire:model="opp.title" type="text" placeholder="Ör. Ücret artışı 40.000'e" class="mt-1 {{ $input }}" /></label>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach (\App\Models\SalesOpportunity::BILLINGS as $key => $label)
                                <button type="button" wire:click="$set('opp.billing', '{{ $key }}')" @class(['seg h-8 text-[13px]', 'seg-active' => $opp['billing'] === $key])>{{ $label }}</button>
                            @endforeach
                        </div>
                        @error('opportunity.service')<p class="text-xs text-error-600">{{ $message }}</p>@enderror
                        <div class="flex items-center gap-2">
                            <button type="submit" class="btn-dark" wire:loading.attr="disabled" data-lead-opportunity-save>Fırsatı aç</button>
                            <button type="button" wire:click="$set('opening', false)" class="btn-quiet">Vazgeç</button>
                        </div>
                    </form>
                @endif
                @if ($opportunities->isEmpty())
                    <p class="mt-2 text-[13px] text-gray-500">{{ $lead->isCustomer() ? 'Yeni bir satış konuşuluyorsa (ücret artışı, ek hizmet, tek seferlik iş) buradan fırsat aç.' : 'Teklif verilince ilk fırsat kendiliğinden açılır; önceden açmak istersen buradan.' }}</p>
                @else
                    <ul class="mt-2 divide-y divide-gray-200 text-[13px] dark:divide-gray-800">
                        @foreach ($opportunities as $o)
                            <li wire:key="opp-{{ $o->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2" data-lead-opportunity="{{ $o->id }}">
                                <span class="chip {{ $o->status === 'won' ? 'chip-up' : ($o->status === 'lost' ? 'chip-down' : 'chip-accent') }}">{{ \App\Models\SalesOpportunity::STATUSES[$o->status] ?? $o->status }}</span>
                                <span class="min-w-0 flex-1">
                                    <span class="font-medium text-gray-900 dark:text-white">{{ $o->title() }}</span>
                                    <span class="text-gray-500"> · {{ $o->amountLabel() }}</span>
                                    <span class="block text-[11px] text-gray-400">{{ ($o->won_at ?? $o->lost_at ?? $o->created_at)?->timezone('Europe/Istanbul')->format('d.m.Y') }}{{ $o->status === 'lost' && $o->lost_reason ? ' · '.(SalesLead::LOST_REASONS[$o->lost_reason] ?? $o->lost_reason) : '' }}{{ $o->note ? ' · '.$o->note : '' }}</span>
                                </span>
                                @if ($o->isOpen())
                                    @if ($isAdmin)<button type="button" wire:click="openWin({{ $o->id }})" class="btn h-7 px-2.5 text-[12px]" data-lead-opportunity-win>Kazan</button>@endif
                                    <span class="flex items-center gap-1">
                                        <select wire:model="oppLostReason" class="h-7 rounded-lg border border-gray-200 bg-white px-1.5 text-[12px] dark:border-gray-700 dark:bg-transparent dark:text-gray-200" aria-label="Neden kaybedildi"><option value="">Neden?</option>@foreach (SalesLead::LOST_REASONS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                                        <button type="button" wire:click="loseOpportunity({{ $o->id }})" class="btn-quiet h-7 px-2 text-[12px]" data-lead-opportunity-lose>Kaybedildi</button>
                                    </span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @error('oppLostReason')<p class="mt-1 text-xs text-error-600">{{ $message }}</p>@enderror
                @endif
            </section>

            {{-- Anlaşma: once a customer, what the brand pays and the monthly ticks (the same card as Marka › Ayarlar). --}}
            @if ($brand !== null && $terms !== null)
                <section class="card p-4" data-lead-agreement>
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Anlaşma</h2>
                        <a href="{{ route('operator.brand', ['brand' => $brand->id, 'tab' => 'ayarlar']) }}" wire:navigate class="link-neutral text-[13px]">Markada düzenle</a>
                    </div>
                    @php $a = $terms['agreement']; @endphp
                    @if ($a === null)
                        <p class="mt-2 text-[13px] text-gray-500">{{ $brand->name }} için anlaşma girilmedi. <button type="button" wire:click="openWin" class="link-neutral font-medium">Şimdi gir</button></p>
                    @else
                        <p class="mt-2 text-sm text-gray-900 dark:text-white"><span class="font-semibold tabular-nums">{{ $a->feeLabel() }}</span> <span class="text-gray-500">· {{ $a->kindLabel() }} · {{ $a->statusLabel() }}{{ $a->kind !== 'one_time' ? ' · ödeme günü '.$a->due_day : '' }}</span></p>
                        @if ($a->services)<p class="text-[12px] text-gray-500">{{ collect($a->services)->map(fn (string $s): string => SalesLead::SERVICES[$s] ?? $s)->implode(' · ') }}</p>@endif
                        @if ($terms['risks'] !== [])
                            <div class="mt-2 flex flex-wrap gap-1.5">@foreach ($terms['risks'] as $risk)<span class="chip chip-down" data-lead-risk="{{ $risk['key'] }}">{{ $risk['label'] }}</span>@endforeach</div>
                        @endif
                        <div class="mt-3 flex flex-wrap gap-1.5" data-lead-payments>
                            @foreach ($terms['payments'] as $p)
                                <button type="button" wire:click="togglePaid({{ $p->id }})" @class(['seg h-8 text-[12px]', 'seg-active' => $p->isPaid(), 'border-error-300 text-error-700 dark:border-error-500/40 dark:text-error-300' => $p->isOverdue()]) title="{{ $p->periodLabel() }} · {{ number_format($p->amount, 0, ',', '.') }} ₺ · {{ $p->isPaid() ? 'ödendi '.$p->paid_on?->format('d.m') : ($p->isOverdue() ? $p->daysLate().' gün gecikti' : 'vade '.$p->due_on?->format('d.m')) }}" data-lead-payment="{{ $p->period }}">
                                    {{ $p->isPaid() ? '✓' : ($p->isOverdue() ? '!' : '○') }} {{ \Carbon\CarbonImmutable::parse($p->period.'-01')->locale('tr')->isoFormat('MMM') }}
                                </button>
                            @endforeach
                            @if ($terms['next_due'] !== null)<span class="self-center text-[12px] text-gray-400">sonraki {{ $terms['next_due']->format('d.m') }}</span>@endif
                        </div>
                        @foreach ($terms['one_time'] as $job)
                            <p class="mt-2 text-[13px] text-gray-600 dark:text-gray-300">Tek seferlik: {{ $job->feeLabel() }} · {{ $job->statusLabel() }}@foreach ($job->payments as $p) · <button type="button" wire:click="togglePaid({{ $p->id }})" class="link-neutral">{{ $p->isPaid() ? '✓ ödendi' : ($p->isOverdue() ? '! '.$p->daysLate().' gün gecikti' : '○ ödenmedi') }}</button>@endforeach</p>
                        @endforeach
                    @endif
                </section>
            @endif

            {{-- Toplantılar: bu adayla Takvim'deki görüşmeler ve notları --}}
            @if ($meetings->isNotEmpty())
                <section class="card p-4" data-lead-meetings>
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">Görüşmeler</h2>
                    <ul class="mt-2 space-y-2 text-[13px]">
                        @foreach ($meetings as $m)
                            <li>
                                <a href="{{ route('operator.calendar', ['gun' => $m->remind_at->timezone('Europe/Istanbul')->toDateString()]) }}" wire:navigate class="font-medium text-gray-900 hover:underline dark:text-white">{{ $m->remind_at->timezone('Europe/Istanbul')->locale('tr')->isoFormat('D MMM ddd HH:mm') }}</a>
                                <span class="text-gray-500">· {{ $m->title }}</span>
                                @if ($m->outcome)<p class="mt-0.5 whitespace-pre-wrap text-gray-600 dark:text-gray-300">{{ $m->outcome }}</p>
                                @elseif ($m->remind_at->isPast())<p class="mt-0.5 text-warning-700">Not yazılmadı: Takvim'de "Not yaz".</p>@endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

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
                    <p class="mt-2 text-sm text-gray-500" wire:poll.6s.visible>{{ $lead->website_host }} okunuyor…</p>
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
                    @if ($lead->email)<dt class="text-gray-500">E-posta</dt><dd class="break-all"><a href="mailto:{{ $lead->email }}" class="link-neutral">{{ $lead->email }}</a></dd>@endif
                    <dt class="text-gray-500">Kaynak</dt><dd>{{ SalesLead::HEARD_FROM[$lead->heard_from] ?? 'bilinmiyor' }}</dd>
                    <dt class="text-gray-500">Kayıt</dt><dd>{{ $lead->lifecycleLabel() }}</dd>
                    <dt class="text-gray-500">Tür</dt><dd>{{ $lead->kindLabel() ?? 'seçilmedi' }}@if ($lead->sectorCategory) · {{ $lead->sectorCategory->name }}@endif</dd>
                    @php $cls = (array) ($lead->classification ?? []); @endphp
                    @if (! empty($cls['why']))<dt class="text-gray-500">Claude</dt><dd class="text-gray-500" data-lead-classification>{{ implode(' · ', array_slice((array) $cls['why'], 0, 3)) }}</dd>@endif
                    @php $origin = (array) ($lead->origin ?? []); @endphp
                    @if (! empty($origin['page']))<dt class="text-gray-500">Form sayfası</dt><dd class="break-all">{{ preg_replace('#^https?://#', '', (string) strtok((string) $origin['page'], '?')) }}</dd>@endif
                    @if (! empty($origin['utm_campaign']) || ! empty($origin['utm_source']))<dt class="text-gray-500">Kampanya</dt><dd>{{ trim(($origin['utm_source'] ?? '').' / '.($origin['utm_campaign'] ?? ''), ' /') }}</dd>@endif
                    <dt class="text-gray-500">Takip</dt><dd>{{ $lead->followup_step > 0 ? $lead->followup_step.'. takip gönderildi' : 'ilk mesaj aşaması' }}</dd>
                </dl>
            </section>
        </div>
    </div>
</div>
