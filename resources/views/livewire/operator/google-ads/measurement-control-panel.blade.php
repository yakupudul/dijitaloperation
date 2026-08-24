@php
    $isTr = app()->getLocale() === 'tr';
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
    $stageLabel = function (string $stage) use ($isTr): string {
        return match ($stage) {
            'engagement' => $isTr ? 'Etkileşim' : 'Engagement',
            'lead' => 'Lead',
            'phone_lead' => $isTr ? 'Telefon lead' : 'Phone lead',
            'qualified_lead' => $isTr ? 'Nitelikli lead' : 'Qualified lead',
            'appointment' => $isTr ? 'Randevu' : 'Appointment',
            'sale' => $isTr ? 'Satış' : 'Sale',
            'purchase' => $isTr ? 'Satın alma' : 'Purchase',
            'revenue' => $isTr ? 'Gelir' : 'Revenue',
            default => $isTr ? 'Diğer' : 'Other',
        };
    };
    $healthState = $health['state'] ?? 'unavailable';
    $healthLabel = match ($healthState) {
        'healthy' => $isTr ? 'Sağlıklı' : 'Healthy',
        'review' => $isTr ? 'İnceleme gerekli' : 'Needs review',
        'critical' => $isTr ? 'Kritik' : 'Critical',
        default => $isTr ? 'Kullanılamıyor' : 'Unavailable',
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
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-blue-600 dark:text-blue-300">Measurement Control Center</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Dönüşüm & ölçüm zekâsı' : 'Conversion & measurement intelligence' }}</h2>
            <p class="mt-1 max-w-4xl text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Google’ın neyi başarı saydığını, hangi sinyallere teklif verdiğini, ölçüm risklerini ve provider dönüşümlerinin gerçek iş anlamını tek yerde denetleyin.' : 'Audit what Google counts as success, which signals feed bidding, measurement risks, and the real business meaning behind provider conversions.' }}</p>
        </div>
        <span class="inline-flex w-fit items-center rounded-full px-3 py-1.5 text-xs font-semibold ring-1 ring-inset {{ $healthClasses }}">{{ $isTr ? 'Ölçüm sağlığı' : 'Measurement health' }} · {{ $healthLabel }}</span>
    </div>

    @if(!($control['connected'] ?? false))
        <div class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 px-5 py-10 text-center dark:border-gray-700 dark:bg-white/[0.02]">
            <p class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Dönüşüm verisi henüz kullanılabilir değil.' : 'Conversion data is not available yet.' }}</p>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Google Ads conversion action snapshotı ve günlük conversion datasetleri geldikten sonra bu kontrol merkezi otomatik açılır.' : 'This control center activates automatically once conversion-action snapshot and daily conversion datasets are available.' }}</p>
        </div>
    @else
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4 2xl:grid-cols-8">
            <x-ta.metric-card :label="$isTr ? 'Conversion action' : 'Conversion actions'" :value="(string)($summary['actions'] ?? 0)" />
            <x-ta.metric-card label="Primary" :value="(string)($summary['primary'] ?? 0)" />
            <x-ta.metric-card label="Secondary" :value="(string)($summary['secondary'] ?? 0)" />
            <x-ta.metric-card :label="$isTr ? 'Sinyal üreten Primary' : 'Observed Primary'" :value="(string)($summary['observed_primary'] ?? 0)" :tone="($summary['observed_primary'] ?? 0) > 0 ? 'positive' : 'neutral'" />
            <x-ta.metric-card :label="$isTr ? 'Business eşleşmiş' : 'Business mapped'" :value="(string)($summary['mapped_primary'] ?? 0).' / '.(string)($summary['primary'] ?? 0)" />
            <x-ta.metric-card :label="$isTr ? 'Değer üreten aksiyon' : 'Value-bearing'" :value="(string)($summary['value_bearing'] ?? 0)" />
            <x-ta.metric-card :label="$isTr ? 'GA4 kaynaklı' : 'GA4-origin'" :value="(string)($summary['ga4_actions'] ?? 0)" />
            <x-ta.metric-card :label="$isTr ? 'Offline / upload' : 'Offline / upload'" :value="(string)($summary['offline_actions'] ?? 0)" />
        </div>

        <section class="rounded-2xl p-4 ring-1 ring-inset {{ $healthClasses }}">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                <div>
                    <h3 class="font-semibold">{{ $isTr ? 'Ölçüm sağlığı' : 'Measurement health' }} · {{ $healthLabel }}</h3>
                    <p class="mt-1 text-sm opacity-90">{{ $isTr ? 'Bu durum keyfi bir skor değildir; aşağıdaki gerçek kontrollerin sonucudur.' : 'This is not an arbitrary score; it is the result of the explicit checks below.' }}</p>
                </div>
                <div class="flex flex-wrap gap-2 text-xs font-medium">
                    <span class="rounded-full bg-white/70 px-2.5 py-1 dark:bg-black/10">{{ $isTr ? 'Kritik' : 'Critical' }}: {{ $health['critical'] ?? 0 }}</span>
                    <span class="rounded-full bg-white/70 px-2.5 py-1 dark:bg-black/10">{{ $isTr ? 'İncele' : 'Review' }}: {{ $health['review'] ?? 0 }}</span>
                    <span class="rounded-full bg-white/70 px-2.5 py-1 dark:bg-black/10">{{ $isTr ? 'Fırsat' : 'Opportunity' }}: {{ $health['opportunities'] ?? 0 }}</span>
                </div>
            </div>
            <div class="mt-4 grid gap-2 md:grid-cols-2 xl:grid-cols-5">
                @foreach(($health['checks'] ?? []) as $check)
                    @php
                        $checkState = $check['state'] ?? 'unknown';
                        $checkIcon = match($checkState) { 'pass' => '✓', 'review' => '!', 'opportunity' => '→', default => '?' };
                    @endphp
                    <div class="rounded-xl bg-white/70 px-3 py-2.5 text-xs dark:bg-black/10">
                        <span class="mr-1 font-bold">{{ $checkIcon }}</span>{{ $check['label'] ?? '—' }}
                    </div>
                @endforeach
            </div>
        </section>

        <div class="grid gap-4 xl:grid-cols-[1.05fr_1.6fr]">
            <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Google şu anda neye optimize oluyor?' : 'What is Google optimizing for?' }}</h3>
                        <p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Primary = Smart Bidding için kullanılan provider conversion action.' : 'Primary = provider conversion action eligible to feed bidding.' }}</p>
                    </div>
                    <x-ta.badge color="success" size="sm">Primary {{ $primaryActions->count() }}</x-ta.badge>
                </div>
                <div class="mt-4 space-y-2">
                    @forelse($primaryActions as $row)
                        <button type="button" wire:click="selectMappingAction('{{ $row['id'] }}')" class="w-full rounded-xl border border-gray-200 px-3 py-3 text-left transition hover:border-blue-300 hover:bg-blue-50/50 dark:border-gray-800 dark:hover:border-blue-700 dark:hover:bg-blue-500/5">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $row['action'] }}</p>
                                    <p class="mt-1 text-xs text-gray-500">{{ $row['source_label'] }} · {{ $row['category'] }} · {{ $row['counting_type'] }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-semibold tabular-nums text-gray-900 dark:text-white">{{ $num($row['conversions']) }}</p>
                                    <p class="text-[11px] text-gray-400">{{ $isTr ? 'dönüşüm' : 'conversions' }}</p>
                                </div>
                            </div>
                            <div class="mt-2 flex flex-wrap items-center gap-1.5 text-[11px]">
                                <span class="rounded-full px-2 py-0.5 {{ $row['status'] === 'ENABLED' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' }}">{{ $row['status'] }}</span>
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-gray-600 dark:bg-white/5 dark:text-gray-300">{{ $row['observed'] ? ($isTr ? 'Sinyal var' : 'Observed') : ($isTr ? 'Sinyal yok' : 'No signal') }}</span>
                                <span class="rounded-full px-2 py-0.5 {{ $row['business_mapped'] ? 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300' : 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' }}">{{ $row['business_mapped'] ? $stageLabel(data_get($row,'business_mapping.business_stage','other')) : ($isTr ? 'Business Action eşlenmemiş' : 'Business Action unmapped') }}</span>
                            </div>
                        </button>
                    @empty
                        <div class="rounded-xl bg-gray-50 px-4 py-8 text-center text-sm text-gray-400 dark:bg-white/[0.02]">{{ $isTr ? 'Primary bidding action bulunamadı.' : 'No Primary bidding action found.' }}</div>
                    @endforelse
                </div>
            </section>

            <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Measurement Decision Inbox' : 'Measurement Decision Inbox' }}</h3>
                    <p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Önce müdahale edilmesi gereken ölçüm kararları.' : 'Measurement decisions that deserve operator attention first.' }}</p>
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
                            <span class="mt-0.5 h-fit rounded-full px-2 py-0.5 text-[10px] font-bold uppercase {{ $sevClasses }}">{{ $severity }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $decision['title'] ?? '—' }}</p>
                                <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $decision['message'] ?? '' }}</p>
                                @if(filled($decision['action_id'] ?? null))
                                    <button type="button" wire:click="selectMappingAction('{{ $decision['action_id'] }}')" class="mt-2 text-xs font-semibold text-blue-600 hover:text-blue-700 dark:text-blue-300">{{ $isTr ? 'Aksiyonu aç →' : 'Open action →' }}</button>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="px-4 py-10 text-center text-sm text-gray-400">{{ $isTr ? 'Aktif ölçüm kararı yok.' : 'No active measurement decisions.' }}</div>
                    @endforelse
                </div>
            </section>
        </div>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
                <div>
                    <h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Conversion architecture' : 'Conversion architecture' }}</h3>
                    <p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Goal kategorilerine göre Primary / Secondary yapı ve provider sinyali.' : 'Primary / Secondary structure and provider signal grouped by goal category.' }}</p>
                </div>
                <p class="text-xs text-gray-400">{{ $isTr ? 'Primary rolü primary_for_goal alanından gelir.' : 'Primary role comes from primary_for_goal.' }}</p>
            </div>
            <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @forelse($goals as $goal)
                    <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-800">
                        <div class="flex items-start justify-between gap-2">
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $goal['category'] }}</p>
                            <span class="text-xs text-gray-400">{{ $goal['actions'] }} {{ $isTr ? 'aksiyon' : 'actions' }}</span>
                        </div>
                        <div class="mt-3 grid grid-cols-2 gap-2 text-xs">
                            <div class="rounded-lg bg-emerald-50 px-2.5 py-2 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">Primary <strong class="float-right">{{ $goal['primary'] }}</strong></div>
                            <div class="rounded-lg bg-gray-50 px-2.5 py-2 text-gray-600 dark:bg-white/[0.03] dark:text-gray-300">Secondary <strong class="float-right">{{ $goal['secondary'] }}</strong></div>
                        </div>
                        <div class="mt-3 flex items-center justify-between text-xs text-gray-500"><span>{{ $isTr ? 'Primary dönüşüm' : 'Primary conv.' }}</span><strong class="text-gray-800 dark:text-gray-200">{{ $num($goal['primary_conversions']) }}</strong></div>
                        <div class="mt-1 flex items-center justify-between text-xs text-gray-500"><span>{{ $isTr ? 'Business mapped' : 'Business mapped' }}</span><strong class="text-gray-800 dark:text-gray-200">{{ $goal['mapped_primary'] }}/{{ $goal['primary'] }}</strong></div>
                    </div>
                @empty
                    <div class="col-span-full py-6 text-center text-sm text-gray-400">{{ $isTr ? 'Goal mimarisi verisi yok.' : 'No goal architecture data.' }}</div>
                @endforelse
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Conversion action kontrol matrisi' : 'Conversion action control matrix' }}</h3>
                <p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Provider konfigürasyonu + seçili dönem performansı + MOXDOP Business Action anlamı.' : 'Provider configuration + selected-period performance + MOXDOP Business Action semantics.' }}</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-[1500px] w-full text-sm">
                    <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-400 dark:bg-white/[0.02]"><tr>
                        <th class="px-4 py-2.5 text-left">{{ $isTr ? 'Aksiyon' : 'Action' }}</th>
                        <th class="px-3 py-2.5 text-left">{{ $isTr ? 'Kaynak' : 'Source' }}</th>
                        <th class="px-3 py-2.5 text-left">Role</th>
                        <th class="px-3 py-2.5 text-left">Category</th>
                        <th class="px-3 py-2.5 text-left">Status</th>
                        <th class="px-3 py-2.5 text-left">Count</th>
                        <th class="px-3 py-2.5 text-right">Conv.</th>
                        <th class="px-3 py-2.5 text-right">All conv.</th>
                        <th class="px-3 py-2.5 text-right">Value</th>
                        <th class="px-3 py-2.5 text-left">Attribution</th>
                        <th class="px-3 py-2.5 text-left">Window</th>
                        <th class="px-4 py-2.5 text-left">Business Action</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach($actions as $row)
                            <tr class="align-top">
                                <td class="px-4 py-3"><button type="button" wire:click="selectMappingAction('{{ $row['id'] }}')" class="text-left"><p class="font-semibold text-gray-900 hover:text-blue-600 dark:text-white dark:hover:text-blue-300">{{ $row['action'] }}</p><p class="mt-1 text-[11px] text-gray-400">ID {{ $row['id'] }} · {{ $row['type'] }}</p></button></td>
                                <td class="px-3 py-3 text-xs text-gray-600 dark:text-gray-300"><p>{{ $row['source_label'] }}</p><p class="mt-1 text-[10px] text-gray-400">{{ $row['origin'] }}</p>@if(filled($row['ga4_event_name']))<p class="mt-1 text-[10px] text-blue-600 dark:text-blue-300">{{ $row['ga4_event_name'] }}</p>@endif</td>
                                <td class="px-3 py-3"><x-ta.badge :color="$row['role'] === 'Primary' ? 'success' : 'info'" size="sm">{{ $row['role'] }}</x-ta.badge></td>
                                <td class="px-3 py-3 text-xs text-gray-600 dark:text-gray-300">{{ $row['category'] }}</td>
                                <td class="px-3 py-3 text-xs"><span class="font-medium {{ $row['status'] === 'ENABLED' ? 'text-emerald-600 dark:text-emerald-300' : 'text-rose-600 dark:text-rose-300' }}">{{ $row['status'] }}</span><p class="mt-1 text-[10px] text-gray-400">{{ $row['state'] }}</p></td>
                                <td class="px-3 py-3 text-xs text-gray-600 dark:text-gray-300">{{ $row['counting_type'] }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ $num($row['conversions']) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ $num($row['all_conversions']) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ $num($row['conversions_value']) }}</td>
                                <td class="px-3 py-3 text-xs text-gray-600 dark:text-gray-300">{{ $row['attribution_model'] ?? '—' }}@if(filled($row['data_driven_model_status']))<p class="mt-1 text-[10px] text-gray-400">DDA {{ $row['data_driven_model_status'] }}</p>@endif</td>
                                <td class="px-3 py-3 text-xs text-gray-600 dark:text-gray-300"><p>Click: {{ $row['click_window_days'] !== null ? $row['click_window_days'].'d' : '—' }}</p><p class="mt-1">View: {{ $row['view_window_days'] !== null ? $row['view_window_days'].'d' : '—' }}</p></td>
                                <td class="px-4 py-3 text-xs">@if($row['business_mapped'])<p class="font-semibold text-blue-700 dark:text-blue-300">{{ $stageLabel(data_get($row,'business_mapping.business_stage','other')) }}</p><p class="mt-1 text-gray-500">{{ data_get($row,'business_mapping.business_action_label') ?: '—' }}</p>@else<button type="button" wire:click="selectMappingAction('{{ $row['id'] }}')" class="font-semibold text-amber-600 hover:text-amber-700 dark:text-amber-300">{{ $isTr ? 'Eşle →' : 'Map →' }}</button>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <div class="grid gap-4 xl:grid-cols-[1.1fr_1.4fr]">
            <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                <div>
                    <h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Business Action eşlemesi' : 'Business Action mapping' }}</h3>
                    <p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Google conversion action’a işletme anlamı verin. Bu ayar Google Ads’e yazılmaz.' : 'Give a Google conversion action a business meaning. This never writes to Google Ads.' }}</p>
                </div>

                <div class="mt-4 space-y-3">
                    <label class="block"><span class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Conversion action</span><select wire:model="mapping_action_id" wire:change="selectMappingAction($event.target.value)" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900"><option value="">{{ $isTr ? 'Aksiyon seçin' : 'Select action' }}</option>@foreach($actions as $row)<option value="{{ $row['id'] }}">{{ $row['action'] }} · {{ $row['role'] }}</option>@endforeach</select></label>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block"><span class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">{{ $isTr ? 'İş aşaması' : 'Business stage' }}</span><select wire:model="mapping_stage" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900">@foreach($stageOptions as $stage)<option value="{{ $stage }}">{{ $stageLabel($stage) }}</option>@endforeach</select></label>
                        <label class="block"><span class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">{{ $isTr ? 'Aksiyon etiketi' : 'Action label' }}</span><input wire:model="mapping_label" type="text" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900" placeholder="{{ $isTr ? 'Örn. Teklif formu' : 'e.g. Quote form' }}"></label>
                    </div>
                    <label class="block"><span class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">{{ $isTr ? 'Nominal iş değeri (opsiyonel)' : 'Nominal business value (optional)' }}</span><input wire:model="mapping_value" type="number" step="0.01" min="0" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900" placeholder="0.00"><span class="mt-1 block text-[11px] text-gray-400">{{ $isTr ? 'Gerçek revenue değildir; yalnız açıkça tanımladığınız planlama değeridir.' : 'Not verified revenue; only an explicitly configured planning value.' }}</span></label>
                    <label class="flex items-start gap-2 rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]"><input wire:model="mapping_quality_signal" type="checkbox" class="mt-0.5 rounded border-gray-300"><span class="text-xs text-gray-600 dark:text-gray-300">{{ $isTr ? 'Bu Business Action nitelik/kalite sinyali olarak değerlendirilsin.' : 'Treat this Business Action as a quality signal.' }}</span></label>
                    <label class="block"><span class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">{{ $isTr ? 'Not' : 'Notes' }}</span><textarea wire:model="mapping_notes" rows="2" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900"></textarea></label>
                    @error('mapping_action_id')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                    @error('mapping_stage')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                    @error('mapping_value')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                    <div class="flex flex-wrap gap-2"><button type="button" wire:click="saveMapping" class="rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700">{{ $isTr ? 'Eşlemeyi kaydet' : 'Save mapping' }}</button><button type="button" wire:click="clearMappingForm" class="rounded-lg border border-gray-300 px-3 py-2 text-xs font-semibold text-gray-600 dark:border-gray-700 dark:text-gray-300">{{ $isTr ? 'Temizle' : 'Clear' }}</button></div>
                </div>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="flex items-start justify-between gap-3"><div><h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Google → Business anlamı' : 'Google → business semantics' }}</h3><p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Bu henüz CRM funnel değildir; provider conversion’ların açık semantik eşlemesidir.' : 'This is not a CRM funnel yet; it is explicit semantic mapping of provider conversions.' }}</p></div><x-ta.badge color="info" size="sm">{{ $mappings->count() }} mapped</x-ta.badge></div>
                <div class="mt-4 space-y-2">
                    @forelse($mappings as $mapping)
                        @php $action = $actions->firstWhere('id', $mapping['conversion_action_id']); @endphp
                        <div class="flex items-start justify-between gap-3 rounded-xl border border-gray-200 px-3 py-3 dark:border-gray-800">
                            <div class="min-w-0"><p class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $action['action'] ?? ('Action '.$mapping['conversion_action_id']) }}</p><p class="mt-1 text-xs text-gray-500">{{ $stageLabel($mapping['business_stage']) }}@if(filled($mapping['business_action_label'])) · {{ $mapping['business_action_label'] }}@endif</p>@if(is_numeric($mapping['nominal_value']))<p class="mt-1 text-[11px] text-blue-600 dark:text-blue-300">{{ $money($mapping['nominal_value']) }} {{ $isTr ? 'nominal değer' : 'nominal value' }}</p>@endif</div>
                            <div class="flex shrink-0 gap-2"><button type="button" wire:click="selectMappingAction('{{ $mapping['conversion_action_id'] }}')" class="text-xs font-semibold text-blue-600 dark:text-blue-300">{{ $isTr ? 'Düzenle' : 'Edit' }}</button><button type="button" wire:click="deleteMapping({{ $mapping['id'] }})" wire:confirm="{{ $isTr ? 'Bu eşleme kaldırılsın mı?' : 'Remove this mapping?' }}" class="text-xs font-semibold text-rose-600 dark:text-rose-300">{{ $isTr ? 'Sil' : 'Delete' }}</button></div>
                        </div>
                    @empty
                        <div class="rounded-xl bg-gray-50 px-4 py-8 text-center text-sm text-gray-400 dark:bg-white/[0.02]">{{ $isTr ? 'Henüz Business Action eşlemesi yok.' : 'No Business Action mappings yet.' }}</div>
                    @endforelse
                </div>
                @if($mappedStages->isNotEmpty())
                    <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-800"><p class="text-xs font-semibold uppercase tracking-wide text-gray-400">{{ $isTr ? 'Semantik aşamalar' : 'Semantic stages' }}</p><div class="mt-2 grid gap-2 sm:grid-cols-2">@foreach($mappedStages as $stage)<div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]"><div class="flex items-center justify-between text-xs"><span class="font-medium text-gray-700 dark:text-gray-300">{{ $stageLabel($stage['stage']) }}</span><strong class="text-gray-900 dark:text-white">{{ $num($stage['provider_conversions']) }}</strong></div><p class="mt-1 text-[10px] text-gray-400">{{ $stage['actions'] }} action · provider conversions</p></div>@endforeach</div></div>
                @endif
            </section>
        </div>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Measurement readiness' : 'Measurement readiness' }}</h3>
            <p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Verisi olmayan alanlarda MOXDOP sağlıklı/aktif gibi bir iddia üretmez.' : 'MOXDOP makes no healthy/active claim when canonical evidence is unavailable.' }}</p>
            <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                @foreach([
                    'enhanced_conversions' => 'Enhanced Conversions',
                    'consent_modeling' => 'Consent & Modeling',
                    'offline_feedback' => $isTr ? 'Offline / CRM feedback' : 'Offline / CRM feedback',
                    'ga4_reconciliation' => 'GA4 reconciliation',
                    'business_outcomes' => $isTr ? 'Business Outcomes' : 'Business Outcomes',
                ] as $key => $title)
                    @php $item = $readiness[$key] ?? ['state' => 'unavailable', 'note' => '']; $availableState = !in_array($item['state'] ?? 'unavailable', ['unavailable'], true); @endphp
                    <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-800"><div class="flex items-start justify-between gap-2"><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $title }}</p><span class="rounded-full px-2 py-0.5 text-[10px] font-semibold {{ $availableState ? 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300' : 'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400' }}">{{ Str::headline($item['state'] ?? 'unavailable') }}</span></div><p class="mt-2 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $item['note'] ?? '' }}</p></div>
                @endforeach
            </div>
        </section>

        <div class="grid gap-4 xl:grid-cols-2">
            <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="flex items-start justify-between gap-3"><div><h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Attribution sağlığı' : 'Attribution health' }}</h3><p class="mt-1 text-xs text-gray-500">{{ $attribution['note'] ?? '' }}</p></div><span class="text-xs font-semibold text-gray-500">{{ $attribution['known'] ?? 0 }}/{{ $actions->count() }}</span></div>
                @if($attribution['available'] ?? false)
                    <div class="mt-4 space-y-2">@foreach(($attribution['models'] ?? []) as $model => $count)<div class="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2 text-xs dark:bg-white/[0.03]"><span>{{ $model }}</span><strong>{{ $count }}</strong></div>@endforeach</div>
                @else
                    <div class="mt-4 rounded-xl bg-gray-50 px-3 py-6 text-center text-xs text-gray-400 dark:bg-white/[0.02]">{{ $isTr ? 'Attribution alanları mevcut snapshotta henüz yok.' : 'Attribution fields are not present in the current snapshot yet.' }}</div>
                @endif
            </section>
            <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="flex items-start justify-between gap-3"><div><h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Conversion window denetimi' : 'Conversion window audit' }}</h3><p class="mt-1 text-xs text-gray-500">{{ $windows['note'] ?? '' }}</p></div><span class="text-xs font-semibold text-gray-500">{{ $windows['known'] ?? 0 }}/{{ $actions->count() }}</span></div>
                @if($windows['available'] ?? false)
                    <div class="mt-4 max-h-64 space-y-2 overflow-auto">@foreach(($windows['rows'] ?? []) as $row)<div class="flex items-center justify-between gap-3 rounded-lg bg-gray-50 px-3 py-2 text-xs dark:bg-white/[0.03]"><span class="truncate">{{ $row['action'] }}</span><span class="shrink-0 text-gray-500">Click {{ $row['click_window_days'] ?? '—' }}d · View {{ $row['view_window_days'] ?? '—' }}d</span></div>@endforeach</div>
                @else
                    <div class="mt-4 rounded-xl bg-gray-50 px-3 py-6 text-center text-xs text-gray-400 dark:bg-white/[0.02]">{{ $isTr ? 'Lookback-window alanları mevcut snapshotta henüz yok.' : 'Lookback-window fields are not present in the current snapshot yet.' }}</div>
                @endif
            </section>
        </div>

        <div class="rounded-xl bg-blue-50 px-4 py-3 text-sm text-blue-800 ring-1 ring-inset ring-blue-100 dark:bg-blue-500/10 dark:text-blue-200 dark:ring-blue-500/20">
            <strong>{{ $isTr ? 'Veri sınırı:' : 'Data boundary:' }}</strong>
            {{ $isTr ? 'Google Ads conversion = provider fact. Qualified lead, satış, CRM sonucu, modeled conversion payı, Enhanced Conversions sağlığı ve doğrulanmış gelir ancak ilgili kanonik kaynak bağlandığında gösterilir. Bu ekran Google Ads’e otomatik değişiklik yazmaz.' : 'A Google Ads conversion is a provider fact. Qualified lead, sale, CRM outcome, modeled contribution, Enhanced Conversions health and verified revenue appear only when the corresponding canonical source is connected. This screen never auto-writes changes to Google Ads.' }}
        </div>
    @endif
</div>
