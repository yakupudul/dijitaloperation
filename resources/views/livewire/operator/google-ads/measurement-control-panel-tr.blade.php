@php
    $summary = $control['summary'] ?? [];
    $health = $control['health'] ?? [];
    $actions = collect($control['actions'] ?? []);
    $primaryActions = collect($control['optimization_actions'] ?? []);
    $decisions = collect($control['decision_inbox'] ?? []);
    $goals = collect($control['goals'] ?? []);
    $mappings = collect($control['business_mappings'] ?? []);
    $mappedStages = collect($control['mapped_stages'] ?? []);
    $readiness = $control['readiness'] ?? [];
    $attribution = $control['attribution'] ?? [];
    $windows = $control['windows'] ?? [];
    $currency = $control['currency'] ?? '';

    $num = fn ($value, $decimals = 2) => is_numeric($value) ? number_format((float) $value, $decimals, ',', '.') : '—';
    $money = fn ($value) => is_numeric($value) ? number_format((float) $value, 2, ',', '.').' '.$currency : '—';

    $stageLabel = fn (string $stage): string => match ($stage) {
        'engagement' => 'Etkileşim',
        'lead' => 'Potansiyel müşteri',
        'phone_lead' => 'Telefonla gelen potansiyel müşteri',
        'qualified_lead' => 'Nitelikli potansiyel müşteri',
        'appointment' => 'Randevu',
        'sale' => 'Satış',
        'purchase' => 'Satın alma',
        'revenue' => 'Gelir',
        default => 'Diğer',
    };

    $roleLabel = fn (?string $role): string => match ($role) {
        'Primary' => 'Birincil',
        'Secondary' => 'İkincil',
        default => 'Bilinmiyor',
    };

    $sourceLabel = fn (?string $source): string => match ($source) {
        'GA4 import' => 'GA4 aktarımı',
        'Offline / upload' => 'Çevrimdışı / yükleme',
        'Calls' => 'Telefon aramaları',
        'Website' => 'Web sitesi',
        'Google Hosted' => 'Google üzerinde',
        'App' => 'Uygulama',
        default => $source ?: 'Bilinmiyor',
    };

    $categoryLabel = fn (?string $category): string => match ($category) {
        'CONTACT' => 'İletişim',
        'PHONE_CALL_LEAD' => 'Telefonla gelen potansiyel müşteri',
        'OUTBOUND_CLICK' => 'Dış bağlantı tıklaması',
        'GET_DIRECTIONS' => 'Yol tarifi alma',
        'DOWNLOAD' => 'İndirme',
        'PAGE_VIEW' => 'Sayfa görüntüleme',
        'PURCHASE' => 'Satın alma',
        'SUBMIT_LEAD_FORM' => 'Form gönderimi',
        'REQUEST_QUOTE' => 'Teklif talebi',
        'QUALIFIED_LEAD' => 'Nitelikli potansiyel müşteri',
        'CONVERTED_LEAD' => 'Satışa dönüşen potansiyel müşteri',
        'IMPORTED_LEAD' => 'Aktarılan potansiyel müşteri',
        'STORE_SALE' => 'Mağaza satışı',
        'ENGAGEMENT' => 'Etkileşim',
        'ADD_TO_CART' => 'Sepete ekleme',
        'BEGIN_CHECKOUT' => 'Ödeme sürecini başlatma',
        'BOOK_APPOINTMENT' => 'Randevu oluşturma',
        'SIGNUP' => 'Kayıt olma',
        'SUBSCRIBE_PAID' => 'Ücretli abonelik',
        'UNKNOWN', null, '' => 'Bilinmiyor',
        default => \Illuminate\Support\Str::headline(strtolower((string) $category)),
    };

    $statusLabel = fn (?string $status): string => match ($status) {
        'ENABLED' => 'Etkin',
        'HIDDEN' => 'Gizli',
        'PAUSED' => 'Duraklatılmış',
        'REMOVED' => 'Kaldırılmış',
        'UNKNOWN', null, '' => 'Bilinmiyor',
        default => \Illuminate\Support\Str::headline(strtolower((string) $status)),
    };

    $countingLabel = fn (?string $counting): string => match ($counting) {
        'ONE_PER_CLICK' => 'Her reklam etkileşiminde bir kez',
        'MANY_PER_CLICK' => 'Her reklam etkileşiminde birden fazla',
        'UNKNOWN', null, '' => 'Bilinmiyor',
        default => \Illuminate\Support\Str::headline(strtolower((string) $counting)),
    };

    $stateLabel = fn (?string $state): string => match ($state) {
        'Observed' => 'Sinyal var',
        'No recent signal' => 'Seçili dönemde sinyal yok',
        default => 'Bilinmiyor',
    };

    $severityLabel = fn (?string $severity): string => match ($severity) {
        'critical' => 'Kritik',
        'review' => 'İncele',
        'opportunity' => 'Fırsat',
        default => 'Bilgi',
    };

    $healthCheckLabel = fn (array $check): string => match ($check['code'] ?? '') {
        'actions_available' => 'Dönüşüm aksiyonu envanteri kullanılabilir',
        'primary_available' => 'En az bir Birincil teklif sinyali tanımlı',
        'primary_observed' => 'Birincil aksiyonlar seçili dönemde dönüşüm sinyali üretiyor',
        'primary_mapped' => 'Birincil aksiyonlar MOXDOP İş Aksiyonlarıyla eşleştirilmiş',
        'duplicate_review' => 'Belirgin bir mükerrer dönüşüm adayı görünmüyor',
        default => (string) ($check['label'] ?? 'Kontrol'),
    };

    $decisionTitle = fn (array $decision): string => match ($decision['code'] ?? '') {
        'primary_not_enabled' => 'Birincil dönüşüm aksiyonu etkin değil',
        'primary_no_signal' => 'Birincil dönüşüm seçili dönemde sinyal üretmemiş',
        'low_intent_primary' => 'Kolay gerçekleşen bir aksiyon teklif sinyali olarak kullanılıyor',
        'business_mapping_missing' => 'Birincil dönüşümün iş anlamı tanımlanmalı',
        'lead_many_per_click' => 'Potansiyel müşteri aksiyonu aynı etkileşimde birden fazla sayılabiliyor',
        'possible_duplicate' => 'Olası mükerrer dönüşüm sinyali',
        default => 'Ölçüm kararı',
    };

    $decisionMessage = function (array $decision) use ($actions, $statusLabel, $categoryLabel): string {
        $action = filled($decision['action_id'] ?? null)
            ? $actions->firstWhere('id', (string) $decision['action_id'])
            : null;
        $name = $action['action'] ?? 'Bu dönüşüm aksiyonu';

        return match ($decision['code'] ?? '') {
            'primary_not_enabled' => $name.' teklif optimizasyonunda Birincil olarak kullanılıyor ancak Google Ads durumu “'.$statusLabel($action['status'] ?? null).'”. Bu yapı kontrol edilmeli.',
            'primary_no_signal' => $name.' teklif optimizasyonunda kullanılıyor ancak seçili dönemde bu aksiyon için sağlayıcı dönüşüm sinyali görülmedi. Etiketleme, kampanya kullanımı ve tarih aralığı kontrol edilmeli.',
            'low_intent_primary' => $name.' Birincil teklif sinyali olarak kullanılıyor ancak kategorisi “'.$categoryLabel($action['category'] ?? null).'”. Google’ın gerçekten işletme açısından değerli bir sonuca mı optimize olması gerektiği kontrol edilmeli.',
            'business_mapping_missing' => $name.' teklif optimizasyonunda kullanılıyor fakat MOXDOP bunun potansiyel müşteri, nitelikli potansiyel müşteri, satış, gelir veya başka hangi iş aşamasını temsil ettiğini henüz bilmiyor.',
            'lead_many_per_click' => $name.' aynı reklam etkileşiminden birden fazla dönüşüm sayabilecek şekilde yapılandırılmış. Hizmet/lead hesabında bunun bilinçli bir tercih olup olmadığı kontrol edilmeli.',
            'possible_duplicate' => 'Aynı iş sonucunu temsil ediyor olabilecek benzer Birincil dönüşüm sinyalleri tespit edildi. Aynı gerçek olayın iki kez sayılıp sayılmadığı incelenmeli.',
            default => 'Bu ölçüm konusu operatör incelemesi gerektiriyor.',
        };
    };

    $readinessTitle = fn (string $key): string => match ($key) {
        'enhanced_conversions' => 'Geliştirilmiş Dönüşümler',
        'consent_modeling' => 'İzin Modu ve Modellemesi',
        'offline_feedback' => 'Çevrimdışı / CRM geri beslemesi',
        'ga4_reconciliation' => 'GA4 tutarlılık kontrolü',
        'business_outcomes' => 'Gerçek İş Sonuçları',
        default => 'Ölçüm alanı',
    };

    $readinessState = fn (?string $state): string => match ($state) {
        'provider_action_observed' => 'Aksiyon görüldü',
        'semantic_mapping_available' => 'İş anlamı eşleşmiş',
        'unavailable', null, '' => 'Henüz kullanılamıyor',
        default => \Illuminate\Support\Str::headline(strtolower((string) $state)),
    };

    $readinessNote = function (string $key, array $item): string {
        return match ($key) {
            'enhanced_conversions' => 'Google Ads Geliştirilmiş Dönüşümler tanılama verisi henüz MOXDOP’un kanonik veri havuzunda toplanmıyor. Bu yüzden sistem burada sağlıklı/bozuk şeklinde tahmin üretmiyor.',
            'consent_modeling' => 'İzin Modu ve modellenmiş dönüşüm katkısı henüz toplanmıyor. Bu nedenle dönüşümler gözlemlenen ve modellenen olarak ayrıştırılmıyor.',
            'offline_feedback' => ($item['state'] ?? 'unavailable') === 'provider_action_observed'
                ? 'Google Ads hesabında çevrimdışı/yükleme türünde dönüşüm aksiyonu görülüyor; ancak CRM’den Google’a çalışan bir geri besleme döngüsü olduğu henüz doğrulanmış değil.'
                : 'Mevcut veride çevrimdışı/yükleme türünde dönüşüm aksiyonu kanıtlanmadı. CRM → Google geri besleme durumu henüz bilinmiyor.',
            'ga4_reconciliation' => ($item['state'] ?? 'unavailable') === 'provider_action_observed'
                ? 'GA4 kaynaklı Google Ads dönüşüm aksiyonları görülüyor; ancak Google Ads aksiyonu ile GA4 eventi arasında kanonik birebir tutarlılık kontrolü henüz kurulmadı.'
                : 'Mevcut veride GA4 kaynaklı dönüşüm aksiyonu görülmedi. Kaynaklar arası tutarlılık kontrolü henüz kullanılamıyor.',
            'business_outcomes' => ($item['state'] ?? 'unavailable') === 'semantic_mapping_available'
                ? 'İş Aksiyonu anlamları eşlenmiş durumda; fakat CRM’den nitelikli potansiyel müşteri, satış ve doğrulanmış gelir adetleri henüz bağlı değil.'
                : 'Henüz İş Aksiyonu eşlemesi yok. Google Ads dönüşümleri nitelikli potansiyel müşteri, satış veya doğrulanmış gelir olarak kabul edilemez.',
            default => 'Bu alan için kanonik veri henüz hazır değil.',
        };
    };

    $attributionModelLabel = fn (?string $model): string => match ($model) {
        'GOOGLE_ADS_LAST_CLICK' => 'Google Ads son tıklama',
        'LAST_CLICK' => 'Son tıklama',
        'DATA_DRIVEN' => 'Veriye dayalı',
        'EXTERNAL' => 'Harici',
        'CROSS_CHANNEL_DATA_DRIVEN' => 'Kanallar arası veriye dayalı',
        null, '' => '—',
        default => \Illuminate\Support\Str::headline(strtolower((string) $model)),
    };

    $healthState = $health['state'] ?? 'unavailable';
    $healthLabel = match ($healthState) {
        'healthy' => 'Sağlıklı',
        'review' => 'İnceleme gerekli',
        'critical' => 'Kritik',
        default => 'Kullanılamıyor',
    };

    $healthClasses = match ($healthState) {
        'healthy' => 'bg-emerald-50 text-emerald-800 ring-emerald-100 dark:bg-emerald-500/10 dark:text-emerald-200 dark:ring-emerald-500/20',
        'critical' => 'bg-rose-50 text-rose-800 ring-rose-100 dark:bg-rose-500/10 dark:text-rose-200 dark:ring-rose-500/20',
        'review' => 'bg-amber-50 text-amber-800 ring-amber-100 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20',
        default => 'bg-gray-50 text-gray-700 ring-gray-200 dark:bg-white/[0.03] dark:text-gray-300 dark:ring-gray-800',
    };
@endphp

<div class="space-y-5">
    <div class="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-blue-600 dark:text-blue-300">ÖLÇÜM KONTROL MERKEZİ</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900 dark:text-white">Dönüşüm ve ölçüm zekâsı</h2>
            <p class="mt-1 max-w-4xl text-sm text-gray-500 dark:text-gray-400">Google’ın neyi başarı saydığını, hangi dönüşümlere göre teklif verdiğini, ölçüm risklerini ve bu dönüşümlerin işletme açısından gerçek anlamını tek yerde denetleyin.</p>
        </div>
        <span class="inline-flex w-fit items-center rounded-full px-3 py-1.5 text-xs font-semibold ring-1 ring-inset {{ $healthClasses }}">Ölçüm sağlığı · {{ $healthLabel }}</span>
    </div>

    @if(!($control['connected'] ?? false))
        <div class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 px-5 py-10 text-center dark:border-gray-700 dark:bg-white/[0.02]">
            <p class="font-semibold text-gray-900 dark:text-white">Dönüşüm verisi henüz kullanılabilir değil.</p>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Google Ads dönüşüm aksiyonu envanteri ve günlük dönüşüm verileri geldikten sonra bu kontrol merkezi otomatik olarak açılır.</p>
        </div>
    @else
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4 2xl:grid-cols-8">
            <x-ta.metric-card label="Dönüşüm aksiyonu" :value="(string)($summary['actions'] ?? 0)" />
            <x-ta.metric-card label="Birincil" :value="(string)($summary['primary'] ?? 0)" />
            <x-ta.metric-card label="İkincil" :value="(string)($summary['secondary'] ?? 0)" />
            <x-ta.metric-card label="Sinyal üreten Birincil" :value="(string)($summary['observed_primary'] ?? 0)" :tone="($summary['observed_primary'] ?? 0) > 0 ? 'positive' : 'neutral'" />
            <x-ta.metric-card label="İş anlamı eşleşmiş" :value="(string)($summary['mapped_primary'] ?? 0).' / '.(string)($summary['primary'] ?? 0)" />
            <x-ta.metric-card label="Değer üreten aksiyon" :value="(string)($summary['value_bearing'] ?? 0)" />
            <x-ta.metric-card label="GA4 kaynaklı" :value="(string)($summary['ga4_actions'] ?? 0)" />
            <x-ta.metric-card label="Çevrimdışı / yükleme" :value="(string)($summary['offline_actions'] ?? 0)" />
        </div>

        <section class="rounded-2xl p-4 ring-1 ring-inset {{ $healthClasses }}">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                <div>
                    <h3 class="font-semibold">Ölçüm sağlığı · {{ $healthLabel }}</h3>
                    <p class="mt-1 text-sm opacity-90">Bu durum keyfi bir puan değildir; aşağıdaki gerçek kontrollerin sonucudur.</p>
                </div>
                <div class="flex flex-wrap gap-2 text-xs font-medium">
                    <span class="rounded-full bg-white/70 px-2.5 py-1 dark:bg-black/10">Kritik: {{ $health['critical'] ?? 0 }}</span>
                    <span class="rounded-full bg-white/70 px-2.5 py-1 dark:bg-black/10">İncele: {{ $health['review'] ?? 0 }}</span>
                    <span class="rounded-full bg-white/70 px-2.5 py-1 dark:bg-black/10">Fırsat: {{ $health['opportunities'] ?? 0 }}</span>
                </div>
            </div>
            <div class="mt-4 grid gap-2 md:grid-cols-2 xl:grid-cols-5">
                @foreach(($health['checks'] ?? []) as $check)
                    @php
                        $checkState = $check['state'] ?? 'unknown';
                        $checkIcon = match($checkState) { 'pass' => '✓', 'review' => '!', 'opportunity' => '→', default => '?' };
                    @endphp
                    <div class="rounded-xl bg-white/70 px-3 py-2.5 text-xs dark:bg-black/10">
                        <span class="mr-1 font-bold">{{ $checkIcon }}</span>{{ $healthCheckLabel($check) }}
                    </div>
                @endforeach
            </div>
        </section>

        <div class="grid gap-4 xl:grid-cols-[1.05fr_1.6fr]">
            <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="font-semibold text-gray-900 dark:text-white">Google şu anda neye göre teklif veriyor?</h3>
                        <p class="mt-1 text-xs text-gray-500">Birincil = Google’ın Akıllı Teklif sistemini besleyebilen dönüşüm aksiyonu.</p>
                    </div>
                    <x-ta.badge color="success" size="sm">Birincil {{ $primaryActions->count() }}</x-ta.badge>
                </div>
                <div class="mt-4 space-y-2">
                    @forelse($primaryActions as $row)
                        <button type="button" wire:click="selectMappingAction('{{ $row['id'] }}')" class="w-full rounded-xl border border-gray-200 px-3 py-3 text-left transition hover:border-blue-300 hover:bg-blue-50/50 dark:border-gray-800 dark:hover:border-blue-700 dark:hover:bg-blue-500/5">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $row['action'] }}</p>
                                    <p class="mt-1 text-xs text-gray-500">{{ $sourceLabel($row['source_label'] ?? null) }} · {{ $categoryLabel($row['category'] ?? null) }} · {{ $countingLabel($row['counting_type'] ?? null) }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-semibold tabular-nums text-gray-900 dark:text-white">{{ $num($row['conversions']) }}</p>
                                    <p class="text-[11px] text-gray-400">dönüşüm</p>
                                </div>
                            </div>
                            <div class="mt-2 flex flex-wrap items-center gap-1.5 text-[11px]">
                                <span class="rounded-full px-2 py-0.5 {{ $row['status'] === 'ENABLED' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' }}">{{ $statusLabel($row['status'] ?? null) }}</span>
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-gray-600 dark:bg-white/5 dark:text-gray-300">{{ $row['observed'] ? 'Sinyal var' : 'Sinyal yok' }}</span>
                                <span class="rounded-full px-2 py-0.5 {{ $row['business_mapped'] ? 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300' : 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' }}">{{ $row['business_mapped'] ? $stageLabel(data_get($row,'business_mapping.business_stage','other')) : 'İş anlamı eşlenmemiş' }}</span>
                            </div>
                        </button>
                    @empty
                        <div class="rounded-xl bg-gray-50 px-4 py-8 text-center text-sm text-gray-400 dark:bg-white/[0.02]">Birincil teklif sinyali bulunamadı.</div>
                    @endforelse
                </div>
            </section>

            <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-white">Ölçüm Karar Kutusu</h3>
                    <p class="mt-1 text-xs text-gray-500">Önce müdahale edilmesi gereken ölçüm konuları.</p>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse($decisions as $decision)
                        @php
                            $severity = $decision['severity'] ?? 'info';
                            $sevClasses = match($severity) {
                                'critical' => 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
                                'review' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
                                'opportunity' => 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300',
                                default => 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300',
                            };
                        @endphp
                        <div class="flex gap-3 px-4 py-3">
                            <span class="mt-0.5 h-fit rounded-full px-2 py-0.5 text-[10px] font-bold uppercase {{ $sevClasses }}">{{ $severityLabel($severity) }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $decisionTitle($decision) }}</p>
                                <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $decisionMessage($decision) }}</p>
                                @if(filled($decision['action_id'] ?? null))
                                    <button type="button" wire:click="selectMappingAction('{{ $decision['action_id'] }}')" class="mt-2 text-xs font-semibold text-blue-600 hover:text-blue-700 dark:text-blue-300">Aksiyonu aç →</button>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="px-4 py-10 text-center text-sm text-gray-400">Aktif ölçüm kararı yok.</div>
                    @endforelse
                </div>
            </section>
        </div>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
                <div>
                    <h3 class="font-semibold text-gray-900 dark:text-white">Dönüşüm mimarisi</h3>
                    <p class="mt-1 text-xs text-gray-500">Hedef kategorilerine göre Birincil / İkincil yapı ve Google Ads dönüşüm sinyali.</p>
                </div>
                <p class="text-xs text-gray-400">Birincil rolü Google Ads’in teklif optimizasyonuna dahil ettiği dönüşüm aksiyonunu ifade eder.</p>
            </div>
            <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @forelse($goals as $goal)
                    <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-800">
                        <div class="flex items-start justify-between gap-2">
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $categoryLabel($goal['category'] ?? null) }}</p>
                            <span class="text-xs text-gray-400">{{ $goal['actions'] }} aksiyon</span>
                        </div>
                        <div class="mt-3 grid grid-cols-2 gap-2 text-xs">
                            <div class="rounded-lg bg-emerald-50 px-2.5 py-2 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">Birincil <strong class="float-right">{{ $goal['primary'] }}</strong></div>
                            <div class="rounded-lg bg-gray-50 px-2.5 py-2 text-gray-600 dark:bg-white/[0.03] dark:text-gray-300">İkincil <strong class="float-right">{{ $goal['secondary'] }}</strong></div>
                        </div>
                        <div class="mt-3 flex items-center justify-between text-xs text-gray-500"><span>Birincil dönüşüm</span><strong class="text-gray-800 dark:text-gray-200">{{ $num($goal['primary_conversions']) }}</strong></div>
                        <div class="mt-1 flex items-center justify-between text-xs text-gray-500"><span>İş anlamı eşleşmiş</span><strong class="text-gray-800 dark:text-gray-200">{{ $goal['mapped_primary'] }}/{{ $goal['primary'] }}</strong></div>
                    </div>
                @empty
                    <div class="col-span-full py-6 text-center text-sm text-gray-400">Dönüşüm hedefi mimarisi verisi yok.</div>
                @endforelse
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-white">Dönüşüm aksiyonu kontrol matrisi</h3>
                <p class="mt-1 text-xs text-gray-500">Google Ads yapılandırması + seçili dönem performansı + MOXDOP iş anlamı.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-[1500px] w-full text-sm">
                    <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-400 dark:bg-white/[0.02]"><tr>
                        <th class="px-4 py-2.5 text-left">Aksiyon</th>
                        <th class="px-3 py-2.5 text-left">Kaynak</th>
                        <th class="px-3 py-2.5 text-left">Rol</th>
                        <th class="px-3 py-2.5 text-left">Kategori</th>
                        <th class="px-3 py-2.5 text-left">Durum</th>
                        <th class="px-3 py-2.5 text-left">Sayım şekli</th>
                        <th class="px-3 py-2.5 text-right">Dönüşüm</th>
                        <th class="px-3 py-2.5 text-right">Tüm dönüşümler</th>
                        <th class="px-3 py-2.5 text-right">Değer</th>
                        <th class="px-3 py-2.5 text-left">Atıf modeli</th>
                        <th class="px-3 py-2.5 text-left">Dönüşüm penceresi</th>
                        <th class="px-4 py-2.5 text-left">İş Aksiyonu</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach($actions as $row)
                            <tr class="align-top">
                                <td class="px-4 py-3"><button type="button" wire:click="selectMappingAction('{{ $row['id'] }}')" class="text-left"><p class="font-semibold text-gray-900 hover:text-blue-600 dark:text-white dark:hover:text-blue-300">{{ $row['action'] }}</p><p class="mt-1 text-[11px] text-gray-400">ID {{ $row['id'] }} · Teknik tür: {{ $row['type'] }}</p></button></td>
                                <td class="px-3 py-3 text-xs text-gray-600 dark:text-gray-300"><p>{{ $sourceLabel($row['source_label'] ?? null) }}</p>@if(filled($row['ga4_event_name']))<p class="mt-1 text-[10px] text-blue-600 dark:text-blue-300">GA4 olayı: {{ $row['ga4_event_name'] }}</p>@endif</td>
                                <td class="px-3 py-3"><x-ta.badge :color="$row['role'] === 'Primary' ? 'success' : 'info'" size="sm">{{ $roleLabel($row['role'] ?? null) }}</x-ta.badge></td>
                                <td class="px-3 py-3 text-xs text-gray-600 dark:text-gray-300">{{ $categoryLabel($row['category'] ?? null) }}</td>
                                <td class="px-3 py-3 text-xs"><span class="font-medium {{ $row['status'] === 'ENABLED' ? 'text-emerald-600 dark:text-emerald-300' : 'text-rose-600 dark:text-rose-300' }}">{{ $statusLabel($row['status'] ?? null) }}</span><p class="mt-1 text-[10px] text-gray-400">{{ $stateLabel($row['state'] ?? null) }}</p></td>
                                <td class="px-3 py-3 text-xs text-gray-600 dark:text-gray-300">{{ $countingLabel($row['counting_type'] ?? null) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ $num($row['conversions']) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ $num($row['all_conversions']) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ $num($row['conversions_value']) }}</td>
                                <td class="px-3 py-3 text-xs text-gray-600 dark:text-gray-300">{{ $attributionModelLabel($row['attribution_model'] ?? null) }}@if(filled($row['data_driven_model_status']))<p class="mt-1 text-[10px] text-gray-400">Veriye dayalı model durumu: {{ $row['data_driven_model_status'] }}</p>@endif</td>
                                <td class="px-3 py-3 text-xs text-gray-600 dark:text-gray-300"><p>Tıklama: {{ $row['click_window_days'] !== null ? $row['click_window_days'].' gün' : '—' }}</p><p class="mt-1">Görüntüleme: {{ $row['view_window_days'] !== null ? $row['view_window_days'].' gün' : '—' }}</p></td>
                                <td class="px-4 py-3 text-xs">@if($row['business_mapped'])<p class="font-semibold text-blue-700 dark:text-blue-300">{{ $stageLabel(data_get($row,'business_mapping.business_stage','other')) }}</p><p class="mt-1 text-gray-500">{{ data_get($row,'business_mapping.business_action_label') ?: '—' }}</p>@else<button type="button" wire:click="selectMappingAction('{{ $row['id'] }}')" class="font-semibold text-amber-600 hover:text-amber-700 dark:text-amber-300">Eşle →</button>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <div class="grid gap-4 xl:grid-cols-[1.1fr_1.4fr]">
            <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                <div>
                    <h3 class="font-semibold text-gray-900 dark:text-white">İş Aksiyonu eşlemesi</h3>
                    <p class="mt-1 text-xs text-gray-500">Google Ads dönüşüm aksiyonuna işletme açısından gerçek bir anlam verin. Bu ayar Google Ads hesabına yazılmaz.</p>
                </div>

                <div class="mt-4 space-y-3">
                    <label class="block"><span class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Dönüşüm aksiyonu</span><select wire:model="mapping_action_id" wire:change="selectMappingAction($event.target.value)" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900"><option value="">Aksiyon seçin</option>@foreach($actions as $row)<option value="{{ $row['id'] }}">{{ $row['action'] }} · {{ $roleLabel($row['role'] ?? null) }}</option>@endforeach</select></label>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block"><span class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">İş aşaması</span><select wire:model="mapping_stage" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900">@foreach($stageOptions as $stage)<option value="{{ $stage }}">{{ $stageLabel($stage) }}</option>@endforeach</select></label>
                        <label class="block"><span class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Aksiyon etiketi</span><input wire:model="mapping_label" type="text" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900" placeholder="Örn. Teklif formu"></label>
                    </div>
                    <label class="block"><span class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Nominal iş değeri (opsiyonel)</span><input wire:model="mapping_value" type="number" step="0.01" min="0" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900" placeholder="0.00"><span class="mt-1 block text-[11px] text-gray-400">Bu gerçek/doğrulanmış gelir değildir; yalnızca sizin tanımladığınız planlama değeridir.</span></label>
                    <label class="flex items-start gap-2 rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]"><input wire:model="mapping_quality_signal" type="checkbox" class="mt-0.5 rounded border-gray-300"><span class="text-xs text-gray-600 dark:text-gray-300">Bu İş Aksiyonu nitelik/kalite sinyali olarak değerlendirilsin.</span></label>
                    <label class="block"><span class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Not</span><textarea wire:model="mapping_notes" rows="2" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900"></textarea></label>
                    @error('mapping_action_id')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                    @error('mapping_stage')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                    @error('mapping_value')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                    <div class="flex flex-wrap gap-2"><button type="button" wire:click="saveMapping" class="rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700">Eşlemeyi kaydet</button><button type="button" wire:click="clearMappingForm" class="rounded-lg border border-gray-300 px-3 py-2 text-xs font-semibold text-gray-600 dark:border-gray-700 dark:text-gray-300">Temizle</button></div>
                </div>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="flex items-start justify-between gap-3"><div><h3 class="font-semibold text-gray-900 dark:text-white">Google dönüşümü → işletme anlamı</h3><p class="mt-1 text-xs text-gray-500">Bu henüz CRM satış hunisi değildir; Google Ads dönüşümlerine açık iş anlamı verilmesini sağlar.</p></div><x-ta.badge color="info" size="sm">{{ $mappings->count() }} eşleşme</x-ta.badge></div>
                <div class="mt-4 space-y-2">
                    @forelse($mappings as $mapping)
                        @php $action = $actions->firstWhere('id', $mapping['conversion_action_id']); @endphp
                        <div class="flex items-start justify-between gap-3 rounded-xl border border-gray-200 px-3 py-3 dark:border-gray-800">
                            <div class="min-w-0"><p class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $action['action'] ?? ('Aksiyon '.$mapping['conversion_action_id']) }}</p><p class="mt-1 text-xs text-gray-500">{{ $stageLabel($mapping['business_stage']) }}@if(filled($mapping['business_action_label'])) · {{ $mapping['business_action_label'] }}@endif</p>@if(is_numeric($mapping['nominal_value']))<p class="mt-1 text-[11px] text-blue-600 dark:text-blue-300">{{ $money($mapping['nominal_value']) }} nominal değer</p>@endif</div>
                            <div class="flex shrink-0 gap-2"><button type="button" wire:click="selectMappingAction('{{ $mapping['conversion_action_id'] }}')" class="text-xs font-semibold text-blue-600 dark:text-blue-300">Düzenle</button><button type="button" wire:click="deleteMapping({{ $mapping['id'] }})" wire:confirm="Bu eşleme kaldırılsın mı?" class="text-xs font-semibold text-rose-600 dark:text-rose-300">Sil</button></div>
                        </div>
                    @empty
                        <div class="rounded-xl bg-gray-50 px-4 py-8 text-center text-sm text-gray-400 dark:bg-white/[0.02]">Henüz İş Aksiyonu eşlemesi yok.</div>
                    @endforelse
                </div>
                @if($mappedStages->isNotEmpty())
                    <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-800"><p class="text-xs font-semibold uppercase tracking-wide text-gray-400">İş aşamaları</p><div class="mt-2 grid gap-2 sm:grid-cols-2">@foreach($mappedStages as $stage)<div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]"><div class="flex items-center justify-between text-xs"><span class="font-medium text-gray-700 dark:text-gray-300">{{ $stageLabel($stage['stage']) }}</span><strong class="text-gray-900 dark:text-white">{{ $num($stage['provider_conversions']) }}</strong></div><p class="mt-1 text-[10px] text-gray-400">{{ $stage['actions'] }} aksiyon · Google Ads dönüşümü</p></div>@endforeach</div></div>
                @endif
            </section>
        </div>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="font-semibold text-gray-900 dark:text-white">Ölçüm altyapısı hazırlık durumu</h3>
            <p class="mt-1 text-xs text-gray-500">Verisi olmayan alanlarda MOXDOP “sağlıklı” veya “aktif” gibi bir iddia üretmez.</p>
            <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                @foreach(['enhanced_conversions','consent_modeling','offline_feedback','ga4_reconciliation','business_outcomes'] as $key)
                    @php
                        $item = $readiness[$key] ?? ['state' => 'unavailable', 'note' => ''];
                        $availableState = !in_array($item['state'] ?? 'unavailable', ['unavailable'], true);
                    @endphp
                    <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-800"><div class="flex items-start justify-between gap-2"><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $readinessTitle($key) }}</p><span class="rounded-full px-2 py-0.5 text-[10px] font-semibold {{ $availableState ? 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300' : 'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400' }}">{{ $readinessState($item['state'] ?? 'unavailable') }}</span></div><p class="mt-2 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $readinessNote($key, $item) }}</p></div>
                @endforeach
            </div>
        </section>

        <div class="grid gap-4 xl:grid-cols-2">
            <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="flex items-start justify-between gap-3"><div><h3 class="font-semibold text-gray-900 dark:text-white">Atıf modeli sağlığı</h3><p class="mt-1 text-xs text-gray-500">{{ ($attribution['available'] ?? false) ? 'Atıf modeli bilgileri Google Ads dönüşüm aksiyonu yapılandırmasından geliyor.' : 'Atıf modeli alanları mevcut veri kopyasında henüz bulunmuyor.' }}</p></div><span class="text-xs font-semibold text-gray-500">{{ $attribution['known'] ?? 0 }}/{{ $actions->count() }}</span></div>
                @if($attribution['available'] ?? false)
                    <div class="mt-4 space-y-2">@foreach(($attribution['models'] ?? []) as $model => $count)<div class="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2 text-xs dark:bg-white/[0.03]"><span>{{ $attributionModelLabel($model) }}</span><strong>{{ $count }}</strong></div>@endforeach</div>
                @else
                    <div class="mt-4 rounded-xl bg-gray-50 px-3 py-6 text-center text-xs text-gray-400 dark:bg-white/[0.02]">Atıf modeli alanları mevcut veri kopyasında henüz yok.</div>
                @endif
            </section>
            <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="flex items-start justify-between gap-3"><div><h3 class="font-semibold text-gray-900 dark:text-white">Dönüşüm penceresi denetimi</h3><p class="mt-1 text-xs text-gray-500">{{ ($windows['available'] ?? false) ? 'Dönüşüm pencereleri Google Ads yapılandırma bilgisidir; MOXDOP bunlardan dönüşüm gecikmesi tahmin etmez.' : 'Dönüşüm geriye bakış penceresi alanları mevcut veri kopyasında henüz bulunmuyor.' }}</p></div><span class="text-xs font-semibold text-gray-500">{{ $windows['known'] ?? 0 }}/{{ $actions->count() }}</span></div>
                @if($windows['available'] ?? false)
                    <div class="mt-4 max-h-64 space-y-2 overflow-auto">@foreach(($windows['rows'] ?? []) as $row)<div class="flex items-center justify-between gap-3 rounded-lg bg-gray-50 px-3 py-2 text-xs dark:bg-white/[0.03]"><span class="truncate">{{ $row['action'] }}</span><span class="shrink-0 text-gray-500">Tıklama {{ $row['click_window_days'] ?? '—' }} gün · Görüntüleme {{ $row['view_window_days'] ?? '—' }} gün</span></div>@endforeach</div>
                @else
                    <div class="mt-4 rounded-xl bg-gray-50 px-3 py-6 text-center text-xs text-gray-400 dark:bg-white/[0.02]">Dönüşüm penceresi alanları mevcut veri kopyasında henüz yok.</div>
                @endif
            </section>
        </div>

        <div class="rounded-xl bg-blue-50 px-4 py-3 text-sm text-blue-800 ring-1 ring-inset ring-blue-100 dark:bg-blue-500/10 dark:text-blue-200 dark:ring-blue-500/20">
            <strong>Veri sınırı:</strong>
            Google Ads dönüşümü yalnızca Google’ın raporladığı bir dönüşüm gerçeğidir. Nitelikli potansiyel müşteri, satış, CRM sonucu, modellenmiş dönüşüm payı, Geliştirilmiş Dönüşümler sağlığı ve doğrulanmış gelir ancak ilgili kanonik kaynak bağlandığında gösterilir. Bu ekran Google Ads hesabında otomatik değişiklik yapmaz.
        </div>
    @endif
</div>
