@php
    use App\Livewire\Operator\Links\LinkDeskPage;
    use App\Models\BacklinkSource;
    use App\Models\LinkSource;
    use App\Services\Site\Backlinks\LinkDesk;

    $n = fn (int $v): string => number_format($v, 0, ',', '.');
    $input = 'h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white';
    $path = fn (?string $url): string => $url ? rawurldecode((string) (parse_url($url, PHP_URL_PATH) ?: '/')) : '/';
    $counts = ['masa' => $groups['ready']->where('material_status', 'ready')->count() + $groups['person']->count(), 'hedefler' => null, 'havuz' => null];
@endphp
{{-- Bağlantılar (Backlink hizmeti, 2026-11-06): one desk for every brand's link work. Nothing is sent by itself. --}}
<div class="pb-24" data-links>
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="page-title">Bağ<span class="highlight">lantılar</span></h1>
            <p class="page-sub">Her markanın link gücüne ihtiyacı olan sayfaları ve o sayfalara link verebilecek yerler. Başvuru malzemesi hazır gelir; gönderen sensin, linki sistem doğrular.</p>
        </div>
        <label class="text-xs text-gray-500">Marka
            <select wire:model.live="brand" class="mt-1 {{ $input }} w-56" data-links-brand>
                <option value="0">Tüm markalar</option>
                @foreach ($brands as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
            </select>
        </label>
    </header>

    <div class="mt-7 flex flex-wrap items-center gap-1.5" role="tablist" aria-label="Bağlantı sekmeleri">
        @foreach (LinkDeskPage::TABS as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['seg', 'seg-active' => $tab === $key]) role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" data-links-tab="{{ $key }}">
                {{ $label }}@if ($counts[$key] !== null) <span @class(['tabular-nums', 'text-accent' => $tab === $key && $counts[$key] > 0, 'text-gray-400' => $tab !== $key || $counts[$key] === 0])>{{ $n($counts[$key]) }}</span>@endif
            </button>
        @endforeach
    </div>

    @if ($message !== '')
        <p class="mt-4 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300" data-links-message>{{ $message }}</p>
    @endif

    @if ($tab === 'masa')
        @foreach (['ready' => 'Başvurular', 'person' => 'Sen yap: telefon, ziyaret, üyelik', 'waiting' => 'Cevap bekleniyor', 'won' => 'Bu ayın linkleri'] as $key => $title)
            <section class="mt-8" data-links-group="{{ $key }}">
                <div class="section-head">
                    <h2 class="section-title">{{ $title }} <span class="section-count">{{ $n($groups[$key]->count()) }}</span></h2>
                    @if ($key === 'ready' && $mailable > 0)
                        <button type="button" wire:click="sendAll" wire:confirm="{{ $mailable }} hazır e-posta kaynaklara gönderilsin mi?" wire:loading.attr="disabled" class="btn-dark text-xs" @disabled(! $canMail) title="{{ $canMail ? '' : 'Ayarlar › E-posta eksik' }}" data-links-send-all>{{ $mailable }} e-postayı gönder</button>
                    @endif
                </div>
                @if ($groups[$key]->isEmpty())
                    <p class="text-sm text-gray-500">
                        @switch($key)
                            @case('ready') Hazır başvuru yok. Masa her pazartesi markaların paketine göre dolar; Hedefler'den bir markayı hemen doldurabilirsin. @break
                            @case('person') Telefon ya da ziyaret gerektiren iş yok. @break
                            @case('waiting') Cevap beklenen başvuru yok. @break
                            @default Bu ay henüz link gelmedi.
                        @endswitch
                    </p>
                @else
                    <div class="card divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($groups[$key] as $row)
                            @php
                                $m = (array) $row->material;
                                $late = $row->status === BacklinkSource::APPLIED && $row->sent_at?->lt(now()->subDays(BacklinkSource::FOLLOW_UP_DAYS));
                            @endphp
                            <div wire:key="row-{{ $row->id }}" data-links-row="{{ $row->id }}">
                                <button type="button" wire:click="toggle({{ $row->id }})" class="card-row w-full py-3! text-left hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                    <x-ta.brand-avatar :name="$row->brand?->name ?? ''" size="sm" />
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm text-gray-900 dark:text-white">{{ $row->name }} <span class="text-gray-500">· {{ $row->brand?->name }}</span></span>
                                        <span class="block truncate text-xs text-gray-500">{{ $row->engineLabel() }} · {{ $row->target ? $path($row->target->url) : 'ana sayfa' }}@if ($row->anchor) · bağlantı metni “{{ $row->anchor }}”@endif @if ($row->paid) · ücretli (sponsored olmalı)@endif</span>
                                    </span>
                                    <span @class(['chip-flat text-xs', 'chip-accent' => $row->stepLabel() === 'hazır', 'chip-down' => $late || $row->status === BacklinkSource::REMOVED, 'chip-up' => $row->status === BacklinkSource::VERIFIED])>{{ $row->stepLabel() }}</span>
                                </button>
                                @if ($openRow === $row->id)
                                    <div class="space-y-3 px-4 pb-4 text-sm text-gray-700 dark:text-gray-300" data-links-detail>
                                        @if ($row->reason)<p class="text-xs text-gray-500">{{ $row->reason }}</p>@endif
                                        @if ($row->material_note)<p class="text-xs text-gray-500">{{ $row->material_note }}</p>@endif
                                        @if ($row->note)<p class="text-xs text-gray-500">{{ $row->note }}</p>@endif
                                        @if ($napLine = \App\Services\Site\Backlinks\LinkNapCheck::line($row))<p class="text-xs text-error-600" data-links-nap>{{ $napLine }}</p>@endif
                                        @foreach ($row->messages as $mail)
                                            <p class="text-xs text-gray-500">{{ $mail->kind === 'takip' ? 'Hatırlatma' : 'E-posta' }} → {{ $mail->to }} · {{ $mail->sent_at ? $mail->sent_at->timezone('Europe/Istanbul')->format('d.m.Y H:i') : 'gönderilemedi: '.$mail->error }}</p>
                                        @endforeach
                                        @if (! empty($m['steps']))
                                            <ol class="list-decimal space-y-0.5 pl-5">@foreach ($m['steps'] as $step)<li>{{ $step }}</li>@endforeach</ol>
                                        @endif
                                        @foreach (array_filter(['Kısa açıklama' => $m['short'] ?? '', 'Açıklama' => $m['description'] ?? '', 'Telefon notu' => $m['phone_note'] ?? '']) as $label => $text)
                                            <div x-data="{ copied: false }">
                                                <div class="flex items-center justify-between"><span class="text-xs font-medium text-gray-500">{{ $label }}</span>
                                                    <button type="button" class="link-neutral text-xs" x-on:click="navigator.clipboard.writeText(@js($text)); copied = true; setTimeout(() => copied = false, 1500)" x-text="copied ? 'Kopyalandı' : 'Kopyala'">Kopyala</button></div>
                                                <p class="mt-1 whitespace-pre-line rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">{{ $text }}</p>
                                            </div>
                                        @endforeach
                                        @if (! empty($m['fields']))
                                            <div class="grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                                                @foreach ($m['fields'] as $field)
                                                    <button type="button" x-data="{ copied: false }" x-on:click="navigator.clipboard.writeText(@js($field['value'])); copied = true; setTimeout(() => copied = false, 1500)" class="rounded-lg border border-gray-200 px-3 py-1.5 text-left hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03]" title="Kopyala">
                                                        <span class="block text-[11px] text-gray-500">{{ $field['label'] }} <span x-show="copied" class="text-accent">· kopyalandı</span></span>
                                                        <span class="block truncate">{{ $field['value'] }}</span>
                                                    </button>
                                                @endforeach
                                            </div>
                                        @endif
                                        @if (! empty($m['email_body']))
                                            @php $to = \App\Services\Site\Backlinks\LinkMailer::recipient($row) ?? ''; @endphp
                                            <div x-data="{ copied: false }">
                                                <div class="flex items-center justify-between"><span class="text-xs font-medium text-gray-500">E-posta{{ $to ? ' · '.$to : '' }} · {{ $m['email_subject'] ?? '' }}</span>
                                                    <span class="flex gap-3">
                                                        <button type="button" class="link-neutral text-xs" x-on:click="navigator.clipboard.writeText(@js($m['email_body'])); copied = true; setTimeout(() => copied = false, 1500)" x-text="copied ? 'Kopyalandı' : 'Kopyala'">Kopyala</button>
                                                        <a class="link-neutral text-xs" href="mailto:{{ $to }}?subject={{ rawurlencode((string) ($m['email_subject'] ?? '')) }}&body={{ rawurlencode((string) $m['email_body']) }}">Postada aç ↗</a>
                                                    </span></div>
                                                <p class="mt-1 whitespace-pre-line rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">{{ $m['email_body'] }}</p>
                                                @if ($row->status === BacklinkSource::NONE && $to === '')
                                                    <div class="mt-2 flex flex-wrap items-center gap-2">
                                                        <input type="email" wire:model="recipient.{{ $row->id }}" placeholder="Kaynağın e-posta adresi" class="{{ $input }} w-64" aria-label="Alıcı" />
                                                        <button type="button" wire:click="setRecipient({{ $row->id }})" class="btn-quiet">Kaydet</button>
                                                    </div>
                                                    @error('recipient.'.$row->id)<p class="text-sm text-error-600">{{ $message }}</p>@enderror
                                                @endif
                                            </div>
                                        @endif
                                        @if (\App\Services\Site\Backlinks\LinkMailer::followUpDue($row))
                                            @php $fu = \App\Services\Site\Backlinks\LinkMailer::followUp($row); @endphp
                                            <div class="rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-800" data-links-followup>
                                                <p class="text-xs font-medium text-gray-500">Hatırlatma · {{ $fu['subject'] }}</p>
                                                <p class="mt-1 whitespace-pre-line text-sm">{{ $fu['body'] }}</p>
                                                <button type="button" wire:click="sendFollowUp({{ $row->id }})" class="btn-dark mt-2" @disabled(! $canMail)>Hatırlatmayı gönder</button>
                                            </div>
                                        @endif
                                        <div class="flex flex-wrap items-center gap-2 pt-1">
                                            @if ($row->link_url ?: $row->url)<a href="{{ $row->link_url ?: $row->url }}" target="_blank" rel="noopener" class="btn">Siteyi aç ↗</a>@endif
                                            @if ($row->status === BacklinkSource::NONE)
                                                @if (\App\Services\Site\Backlinks\LinkMailer::mailReady($row) && $canMail)
                                                    <button type="button" wire:click="sendMail({{ $row->id }})" wire:confirm="E-posta {{ \App\Services\Site\Backlinks\LinkMailer::recipient($row) }} adresine gönderilsin mi?" class="btn-dark">E-postayı gönder</button>
                                                @endif
                                                <button type="button" wire:click="sent({{ $row->id }})" @class(['btn-dark' => ! (\App\Services\Site\Backlinks\LinkMailer::mailReady($row) && $canMail), 'btn' => \App\Services\Site\Backlinks\LinkMailer::mailReady($row) && $canMail]) @disabled($row->material_status !== 'ready')>Başvurdum</button>
                                                <button type="button" wire:click="rewrite({{ $row->id }})" class="btn-quiet">Yeniden yaz</button>
                                            @endif
                                            @if (in_array($row->status, [BacklinkSource::NONE, BacklinkSource::APPLIED], true))
                                                <input type="url" wire:model="linkUrl.{{ $row->id }}" placeholder="Link verilen sayfa: https://…" class="{{ $input }} w-72" aria-label="Link verilen sayfa" />
                                                <button type="button" wire:click="given({{ $row->id }})" class="btn">Link verildi</button>
                                                <button type="button" wire:click="drop({{ $row->id }})" wire:confirm="Bu kaynak masadan kalksın mı?" class="btn-quiet">Vazgeç</button>
                                            @endif
                                        </div>
                                        @error('linkUrl.'.$row->id)<p class="text-sm text-error-600">{{ $message }}</p>@enderror
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        @endforeach
    @elseif ($tab === 'hedefler')
        <section class="mt-6 space-y-4" data-links-plans>
            <p class="text-sm text-gray-500">Hedef sayfa: ana hizmet aramasında Google'da 4-20. sırada, gösterimi olan ve içeriği aramayı karşılayan sayfa. Linklerin çoğu marka adı ve adresle gelir; anahtar kelimeli bağlantı metni %10'u geçmez.</p>
            @foreach ($plans as $plan)
                @php $b = $plan['brand']; $mo = $plan['month']; @endphp
                <div class="card p-4" wire:key="plan-{{ $b->id }}" data-links-plan="{{ $b->id }}">
                    <div class="flex flex-wrap items-center gap-3">
                        <x-ta.brand-avatar :name="$b->name" size="sm" />
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $b->name }}</h3>
                        <span class="text-xs text-gray-500">Bu ay {{ $mo['won'] }} / {{ $mo['budget'] }} link · {{ $mo['open'] }} açık başvuru</span>
                        <span class="ml-auto flex items-center gap-2">
                            <select wire:change="setPackage({{ $b->id }}, $event.target.value)" class="h-8 rounded-lg border border-gray-200 bg-white px-2 text-xs dark:border-gray-700 dark:bg-transparent" aria-label="Paket">
                                @foreach (LinkDesk::PACKAGE_LABELS as $key => $label)<option value="{{ $key }}" @selected(($b->link_package ?? 'temel') === $key)>{{ $label }}</option>@endforeach
                            </select>
                            <button type="button" wire:click="fillBrand({{ $b->id }})" wire:loading.attr="disabled" class="btn-quiet text-xs">Şimdi doldur</button>
                            <a href="{{ route('operator.links.report', ['brand' => $b->id]) }}" target="_blank" rel="noopener" class="link-neutral text-xs" data-links-report="{{ $b->id }}">Aylık rapor ↗</a>
                        </span>
                    </div>
                    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-gray-500" data-links-survey="{{ $b->id }}">
                        @if ($plan['survey']?->answered_at)
                            <span>Müşteri "Bağlantı kaynakları" formunu {{ $plan['survey']->answered_at->timezone('Europe/Istanbul')->format('d.m.Y') }} tarihinde doldurdu · {{ $plan['survey']->rows_created }} ilişki satırı.</span>
                        @else
                            <span>Müşterinin gerçek ilişkileri (üyelik, tedarikçi, üniversite, sponsorluk) en değerli linkleri getirir.</span>
                        @endif
                        @if (isset($surveyUrl[$b->id]))
                            <span x-data="{ copied: false }" class="flex items-center gap-2"><code class="rounded bg-gray-50 px-2 py-0.5 dark:bg-white/[0.04]">{{ $surveyUrl[$b->id] }}</code>
                                <button type="button" class="link-neutral" x-on:click="navigator.clipboard.writeText(@js($surveyUrl[$b->id])); copied = true" x-text="copied ? 'Kopyalandı' : 'Kopyala'">Kopyala</button></span>
                        @else
                            <button type="button" wire:click="surveyLink({{ $b->id }})" class="link-neutral">Müşteri formunun bağlantısını al</button>
                        @endif
                    </div>
                    @if ($plan['magnet'] || $plan['magnetIdea'])
                        <div class="mt-2 text-xs text-gray-500" data-links-magnet="{{ $b->id }}">
                            @if ($plan['magnetIdea'])
                                <span>Link mıknatısı: “{{ $plan['magnetIdea']->title }}” · @switch($plan['magnetIdea']->status)
                                    @case('open') İçerik'te onay bekliyor @break
                                    @case('approved') yazılıyor @break
                                    @case('applied') yayında; yerel haber ve dernek başvuruları bu sayfaya yönlenir @break
                                    @case('dismissed') İçerik'te reddedildi @break
                                    @default {{ $plan['magnetIdea']->status }}
                                @endswitch</span>
                            @else
                                <span>Link mıknatısı: diğer sitelerin kendiliğinden kaynak gösterdiği kalıcı bir rehber. Öneri: “{{ $plan['magnet']['title'] }}”.</span>
                                <button type="button" wire:click="proposeMagnet({{ $b->id }})" class="link-neutral ml-1">İçerik'e ekle</button>
                            @endif
                            @if ($plan['magnet']['tool'] ?? null)
                                <span class="block">Daha güçlüsü bir araç: “{{ $plan['magnet']['tool'] }}”. Yazıyla değil, sitede çalışan bir araçla olur; geliştirme olarak ayrıca planlanmalı.</span>
                            @endif
                        </div>
                    @endif
                    <div class="mt-2 text-xs text-gray-500" data-links-alert="{{ $b->id }}">
                        @if ($b->link_alert_feed && ! array_key_exists($b->id, $alertFeed))
                            <span>Anılmalar: Google Alerts akışı her sabah okunuyor{{ $b->link_alert_read_at ? ' (son '.$b->link_alert_read_at->timezone('Europe/Istanbul')->format('d.m H:i').')' : '' }}.</span>
                            <button type="button" wire:click="$set('alertFeed.{{ $b->id }}', @js($b->link_alert_feed))" class="link-neutral ml-1">Değiştir</button>
                        @else
                            <span class="block">Anılmalar: Google Alerts'te "{{ $b->name }}" için uyarı kur, "Gönderim yeri: RSS akışı" seç ve akış adresini buraya yapıştır. Adı geçip link vermeyen sayfalar masaya gelir.</span>
                            <span class="mt-1 flex flex-wrap items-center gap-2">
                                <input type="url" wire:model="alertFeed.{{ $b->id }}" placeholder="https://www.google.com/alerts/feeds/…" class="{{ $input }} w-96 max-w-full" aria-label="Google Alerts RSS akışı" />
                                <button type="button" wire:click="setAlertFeed({{ $b->id }})" class="btn-quiet text-xs">Kaydet</button>
                            </span>
                            @error('alertFeed.'.$b->id)<p class="text-sm text-error-600">{{ $message }}</p>@enderror
                        @endif
                    </div>
                    @forelse ($plan['targets'] as $t)
                        <div class="mt-3 border-t border-gray-100 pt-3 text-sm dark:border-gray-800" wire:key="target-{{ $t->id }}">
                            <div class="flex flex-wrap items-baseline gap-2">
                                <a href="{{ $t->url }}" target="_blank" rel="noopener" class="font-medium text-gray-900 hover:underline dark:text-white">{{ $t->title ?: $path($t->url) }}</a>
                                <span class="text-xs text-gray-500">{{ $path($t->url) }}</span>
                                @if ($t->status === 'tamam')<span class="chip-up text-xs">ilk 3'e çıktı</span>@endif
                                @if ($t->kind === 'miknatis')<span class="chip-accent text-xs">link mıknatısı</span>@endif
                            </div>
                            <p class="text-xs text-gray-500">{{ $t->reason }}</p>
                            @foreach ([60, 90] as $d)
                                @if (isset($t->impact['d'.$d]))<p class="text-xs text-gray-500">İlk linkten {{ $d }} gün sonra: {{ $t->impact['d'.$d]['position'] }}. sıra, {{ $t->impact['d'.$d]['clicks'] }} tıklama</p>@endif
                            @endforeach
                            @if ($t->before)
                                <p class="text-xs text-gray-500">Seçildiği gün ({{ \Illuminate\Support\Carbon::parse($t->before['at'])->format('d.m.Y') }}): {{ $t->before['position'] }}. sıra, {{ $t->before['clicks'] }} tıklama · şimdi {{ $t->position }}. sıra, {{ $t->clicks }} tıklama</p>
                            @endif
                            <p class="mt-1 text-xs text-gray-500">Bağlantı metni planı: @foreach ((array) $t->anchor_plan as $slot)<span class="mr-2">“{{ $slot['text'] }}” %{{ $slot['share'] }}</span>@endforeach</p>
                        </div>
                    @empty
                        <p class="mt-3 text-sm text-gray-500">Şu an link isteyen sayfa yok: ya Search Console verisi yok ya da sayfalar ilk 3'te veya içerikleri önce düzelmeli. Künye ve geri kazanım satırları yine gelir.</p>
                    @endforelse
                </div>
            @endforeach
        </section>
    @else
        <section class="mt-6" data-links-pool>
            <form wire:submit="addSource" class="card grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4" data-links-add-source>
                <label class="block text-xs text-gray-500">Ad<input wire:model="source.name" type="text" placeholder="Ankara Diş Hekimleri Odası" class="mt-1 {{ $input }}" /></label>
                <label class="block text-xs text-gray-500">Başvuru adresi<input wire:model="source.url" type="url" placeholder="https://…" class="mt-1 {{ $input }}" /></label>
                <label class="block text-xs text-gray-500">Tür<select wire:model="source.kind" class="mt-1 {{ $input }}">@foreach (LinkSource::KINDS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
                <label class="block text-xs text-gray-500">Şehir (boş = ulusal)<input wire:model="source.city" type="text" placeholder="Ankara" class="mt-1 {{ $input }}" /></label>
                <label class="block text-xs text-gray-500">Sektör<select wire:model="source.sector_id" class="mt-1 {{ $input }}"><option value="">Tüm sektörler</option>@foreach ($sectors as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></label>
                <label class="block text-xs text-gray-500">Nasıl başvurulur<select wire:model="source.apply_via" class="mt-1 {{ $input }}">@foreach (LinkSource::APPLY_VIA as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
                <label class="block text-xs text-gray-500">E-posta (varsa)<input wire:model="source.contact_email" type="email" class="mt-1 {{ $input }}" /></label>
                <div class="flex items-end"><button type="submit" class="btn-dark">Havuza ekle</button></div>
                @error('source.*')<p class="text-sm text-error-600 sm:col-span-2 lg:col-span-4">{{ $message }}</p>@enderror
            </form>
            <input wire:model.live.debounce.400ms="q" type="search" placeholder="Ara: ad, alan adı, şehir" class="mt-5 {{ $input }} max-w-sm" aria-label="Havuzda ara" />
            <div class="card mt-3 divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($pool as $s)
                    <div class="card-row py-2.5!" wire:key="pool-{{ $s->id }}" data-links-source="{{ $s->id }}">
                        <span class="min-w-0 flex-1">
                            <a href="{{ $s->apply_url ?: $s->url }}" target="_blank" rel="noopener" class="block truncate text-sm text-gray-900 hover:underline dark:text-white">{{ $s->name }} <span class="text-gray-500">· {{ $s->domain }}</span></a>
                            <span class="block truncate text-xs text-gray-500">{{ $s->kindLabel() }} · {{ $s->sector?->name ?? 'tüm sektörler' }} · {{ $s->city ? mb_convert_case($s->city, MB_CASE_TITLE) : 'ulusal' }} · {{ LinkSource::APPLY_VIA[$s->apply_via] ?? $s->apply_via }}@if ($s->applied_count > 0) · {{ $s->applied_count }} başvuru, {{ $s->won_count }} link @if ($s->averageDays() !== null)(ort. {{ $s->averageDays() }} gün)@endif @endif @if ($s->quality_note) · {{ $s->quality_note }}@endif</span>
                        </span>
                        <span @class(['chip-flat text-xs', 'chip-up' => $s->quality === 'uygun', 'chip-down' => $s->quality === 'red'])>{{ LinkSource::QUALITY[$s->quality] ?? $s->quality }}</span>
                        @if ($s->quality !== 'red')
                            <button type="button" wire:click="setQuality({{ $s->id }}, 'red')" wire:confirm="Bu kaynak reddedilsin mi? Gönderilmemiş tüm başvurulardan kalkar." class="btn-quiet text-xs">Reddet</button>
                        @else
                            <button type="button" wire:click="setQuality({{ $s->id }}, 'uygun')" class="btn-quiet text-xs">Geri al</button>
                        @endif
                    </div>
                @empty
                    <p class="px-4 py-3 text-sm text-gray-500">Havuz boş. Sitelerin Backlinkler sekmesindeki "AI ile kaynak öner", Rakipler'e yapıştırılan aramalar ve buradan eklediklerin havuzu doldurur.</p>
                @endforelse
            </div>
        </section>
    @endif
</div>
