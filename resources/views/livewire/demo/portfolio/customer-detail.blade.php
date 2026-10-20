@php
    $chip = ['ok' => 'text-success-700 dark:text-success-400', 'warn' => 'text-warning-800 dark:text-warning-400', 'bad' => 'text-error-700 dark:text-error-400', 'muted' => 'text-gray-500 dark:text-gray-400'];
    $statusTone = ['active' => 'ok', 'inactive' => 'muted', 'archived' => 'muted'];
    $band = ['good' => 'ok', 'watch' => 'warn', 'risk' => 'bad'];
    $money = static fn (?float $v): string => $v !== null ? '₺'.number_format($v, 0, ',', '.') : '—';
    $stateLabel = ['over' => ['Bütçeyi aşacak', $chip['bad']], 'under' => ['Bütçenin altında', $chip['warn']], 'on_track' => ['Hedefte', $chip['ok']], 'no_budget' => ['Bütçe girilmemiş', $chip['muted']], 'mixed_currency' => ['Farklı para birimleri', $chip['warn']]];
    $attentionBrands = collect($brands)->where('needs_attention', true);
    // Empty fields are hidden (2026-10-19); one quiet "Eksik bilgi" line names what is missing instead of a card of "—".
    $missingTeam = array_values(array_filter([
        $responsibleUsers === [] ? 'Hesap sorumlusu' : null,
        $serviceLabels === [] ? 'Verilen hizmetler' : null,
        empty($customer['service_started_at']) ? 'Hizmet başlangıcı' : null,
        $serviceScope === [] ? 'Hizmet kapsamı' : null,
    ]));
    $missingCommercial = array_values(array_filter([
        $commercialSummary['fee'] === null ? 'Aylık ücret' : null,
        (collect($commercialSummary['channels'])->firstWhere('key', 'google')['budget'] ?? null) === null ? 'Google Ads bütçesi' : null,
        (collect($commercialSummary['channels'])->firstWhere('key', 'meta')['budget'] ?? null) === null ? 'Meta bütçesi' : null,
    ]));
@endphp
<div class="space-y-10 text-sm dark:text-gray-200" x-data="{ moreOpen: false }" data-customer-detail>
    @include('livewire.demo.partials.flash')

    <header class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
        <div class="flex min-w-0 items-start gap-2">
            <a href="{{ route('operator.brands') }}" wire:navigate class="-ml-1 mt-1 hidden h-6 w-6 shrink-0 items-center justify-center rounded-md text-gray-400 hover:bg-gray-100 hover:text-gray-900 sm:flex dark:hover:bg-white/5 dark:hover:text-white" aria-label="Markalar"><svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M12.5 15 7.5 10l5-5" stroke-linecap="round" stroke-linejoin="round"/></svg></a>
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                    <h1 class="page-title break-words">{{ $customer['name'] }}</h1>
                    @if (($customer['status'] ?? '') !== 'active')<x-ta.pill tone="info">{{ $statusLabel }}</x-ta.pill>@endif
                </div>
                <p class="page-sub flex flex-wrap gap-x-3 gap-y-0.5" data-customer-meta>
                    @if (collect([$typeLabel, $industryLabel !== '—' ? $industryLabel : null, $hqDisplay !== '—' ? $hqDisplay : null])->filter()->isNotEmpty())<span>{{ collect([$typeLabel, $industryLabel !== '—' ? $industryLabel : null, $hqDisplay !== '—' ? $hqDisplay : null])->filter()->implode(', ') }}</span>@endif
                    <span>{{ count($brands) }} marka, {{ $digitalAssetsCount }} varlık</span>
                    <a href="{{ route('operator.assets', ['customer' => $customer['id']]) }}" wire:navigate class="link-neutral font-normal">varlıkları gör</a>
                </p>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <x-ta.button variant="outline" size="sm" :href="route('operator.customer.edit', ['customerId' => $customer['id']])" wire:navigate>Düzenle</x-ta.button>
            <x-ta.button variant="outline" size="sm" wire:click="openContactForm">Kişi ekle</x-ta.button>
            <x-ta.button size="sm" :href="route('operator.brand.create', ['customerId' => $customer['id']])" wire:navigate>Marka ekle</x-ta.button>
            <div class="relative">
                <button type="button" @click="moreOpen = !moreOpen" class="btn w-8 px-0!" aria-label="Diğer"><svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M4 11.5a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm6 0a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm6 0a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Z"/></svg></button>
                <div x-show="moreOpen" @click.outside="moreOpen = false" x-cloak class="card absolute right-0 z-20 mt-1 w-48 py-1 shadow-theme-lg">
                    <a href="{{ route('operator.files', ['scope' => 'customer', 'customer' => $customer['id']]) }}" wire:navigate class="block px-3 py-2 text-[13px] hover:bg-gray-50 dark:hover:bg-white/5">Dosyalar</a>
                    @if (($customer['status'] ?? '') === 'archived')
                        <button type="button" wire:click="restoreCustomer" class="block w-full px-3 py-2 text-left text-[13px] hover:bg-gray-50 dark:hover:bg-white/5">Müşteriyi geri al</button>
                    @else
                        <button type="button" wire:click="archiveCustomer" wire:confirm="Müşteri arşivlensin mi? Veriler silinmez." class="block w-full px-3 py-2 text-left text-[13px] text-error-600 hover:bg-gray-50 dark:hover:bg-white/5">Müşteriyi arşivle</button>
                    @endif
                </div>
            </div>
        </div>
    </header>

    @if ($attentionBrands->isNotEmpty())
        <section data-customer-attention>
            <div class="section-head">
                <h2 class="section-title">{{ $attentionBrands->count() }} marka dikkat istiyor</h2>
            </div>
            <ul class="card">
                @foreach ($attentionBrands as $brand)
                    <li class="card-row">
                        <x-ta.brand-avatar :name="$brand['name']" :logo="$brand['logo_url'] ?? null" />
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('operator.brand', ['brand' => $brand['id']]) }}" wire:navigate class="break-words text-sm font-medium text-gray-900 hover:underline dark:text-white">{{ $brand['name'] }}</a>
                            <p class="mt-0.5 flex items-center gap-1.5 text-[13px] text-gray-500"><span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $brand['reconnect'] > 0 ? 'bg-error-500' : 'bg-warning-500' }}" aria-hidden="true"></span>{{ $brand['attention_reason'] }}</p>
                        </div>
                        <a href="{{ route('operator.brand', ['brand' => $brand['id']]) }}" wire:navigate class="btn hidden sm:inline-flex">Aç</a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Brands first: this is where the work happens --}}
    <section data-customer-brands>
        <div class="section-head">
            <h2 class="section-title">Markalar <span class="section-count">{{ count($brands) }}</span></h2>
        </div>
        @if ($brands === [])
            <div class="card px-4 py-3.5 text-[13px] text-gray-500">
                Bu müşterinin markası yok.
                <a href="{{ route('operator.brand.create', ['customerId' => $customer['id']]) }}" wire:navigate class="link-neutral">İlk markayı ekle</a>; web sitesini girersen hesapları ve hizmetleri "Otomatik kur" bulur.
            </div>
        @else
            <div class="card relative overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Marka</th>
                            <th class="hidden sm:table-cell">Kanallar</th>
                            <th class="text-right!">Açık iş</th>
                            <th class="hidden sm:table-cell">Durum</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($brands as $brand)
                            <tr wire:key="customer-brand-{{ $brand['id'] }}" class="max-sm:[&>td]:align-top" data-customer-brand="{{ $brand['id'] }}">
                                <td>
                                    <div class="flex min-w-0 items-center gap-2.5">
                                        <x-ta.brand-avatar :name="$brand['name']" :logo="$brand['logo_url'] ?? null" />
                                        <div class="min-w-0">
                                            <a href="{{ route('operator.brand', ['brand' => $brand['id']]) }}" wire:navigate class="break-words font-medium text-gray-900 hover:underline dark:text-white">{{ $brand['name'] }}</a>
                                            <p class="break-words text-[13px] text-gray-500">{{ collect([$brand['website'], $brand['sector_label'] !== '—' ? $brand['sector_label'] : null])->filter()->implode(', ') ?: 'Web sitesi yok' }}</p>
                                            <div class="mt-1 sm:hidden">@include('livewire.demo.portfolio.partials.channel-dots', ['channels' => $brand['channels']])</div>
                                            @unless ($brand['setup']['complete'])<x-ta.pill tone="look" class="mt-1 tabular-nums sm:hidden!">Kurulum {{ $brand['setup']['done'] }}/{{ $brand['setup']['total'] }}</x-ta.pill>@endunless
                                        </div>
                                    </div>
                                </td>
                                <td class="hidden sm:table-cell">@include('livewire.demo.portfolio.partials.channel-dots', ['channels' => $brand['channels']])</td>
                                <td class="text-right tabular-nums" data-open-work>
                                    <span class="font-medium text-gray-900 dark:text-white">{{ $brand['open_work'] }}</span>
                                    @if ($brand['open_by_channel'] !== [])<p class="mt-0.5 flex flex-wrap justify-end gap-x-1.5 text-xs leading-4 text-gray-500">@foreach ($brand['open_by_channel'] as $label => $n)<span class="whitespace-nowrap">{{ $label }} {{ $n }}</span>@endforeach</p>@endif
                                </td>
                                <td class="hidden sm:table-cell">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        @if ($brand['needs_attention'] && ($brand['reconnect'] > 0 || ($brand['critical'] ?? 0) > 0))
                                            <x-ta.pill :tone="$brand['reconnect'] > 0 ? 'critical' : 'look'">Dikkat</x-ta.pill>
                                        @endif
                                        @if ($brand['setup']['complete'])
                                            <span class="text-[13px] text-gray-500">Kurulum tamam</span>
                                        @else
                                            <x-ta.pill tone="look" class="tabular-nums">Kurulum {{ $brand['setup']['done'] }}/{{ $brand['setup']['total'] }}</x-ta.pill>
                                        @endif
                                    </div>
                                    @if ($brand['attention_reason'])<p class="mt-0.5 max-w-xs break-words text-xs text-gray-500">{{ $brand['attention_reason'] }}</p>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section data-customer-commercial>
        <div class="section-head">
            <h2 class="section-title" title="Ay sonu tahmini bugüne kadarki harcamanın doğrusal uzantısıdır.">Ücret ve reklam bütçesi <span class="section-count">bu ay {{ $commercialSummary['days']['elapsed'] }}/{{ $commercialSummary['days']['total'] }} gün</span></h2>
            <button type="button" wire:click="editCommercial" class="btn-quiet ml-auto h-7! px-2!">Düzenle</button>
        </div>
        @if ($editingCommercial)
            <form wire:submit="saveCommercial" class="card mb-3 grid gap-4 p-5 sm:grid-cols-4">
                @foreach (['monthly_fee' => 'Aylık ücret (₺)', 'ad_budget_google' => 'Google Ads aylık bütçe (₺)', 'ad_budget_meta' => 'Meta aylık bütçe (₺)'] as $field => $label)
                    <label class="block"><span class="text-[13px] text-gray-600 dark:text-gray-400">{{ $label }}</span>
                        <input type="text" inputmode="decimal" wire:model="commercial.{{ $field }}" class="mt-1 h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white" />
                        @error('commercial.'.$field) <span class="text-xs text-error-600">{{ $message }}</span> @enderror
                    </label>
                @endforeach
                <div class="flex items-end"><button type="submit" class="btn-primary h-9! w-full sm:w-auto">Kaydet</button></div>
            </form>
        @endif
        @if ($commercialSummary['fee'] !== null || $commercialSummary['channels'] !== [])
            <div class="metric-strip sm:grid-cols-[repeat(auto-fit,minmax(14rem,1fr))]">
                @if ($commercialSummary['fee'] !== null)
                    <x-ta.metric-card label="Aylık ücret" :value="$money($commercialSummary['fee'])" />
                @endif
                @foreach ($commercialSummary['channels'] as $channel)
                    <div class="flex min-w-0 flex-col bg-white px-[18px] py-4 dark:bg-gray-900">
                        <div class="flex items-center justify-between gap-2"><span class="text-[13px] text-gray-500 dark:text-gray-400">{{ $channel['label'] }}</span>@if ($channel['state'] !== 'no_budget')<span class="text-xs font-medium {{ $stateLabel[$channel['state']][1] }}">{{ $stateLabel[$channel['state']][0] }}</span>@endif</div>
                        <p class="mt-1.5 text-sm text-gray-700 dark:text-gray-300"><strong class="text-[22px] leading-8 font-semibold tabular-nums tracking-[-0.02em] text-gray-900 dark:text-white">{{ $money($channel['spent']) }}</strong> harcandı, ay sonu ~<span class="tabular-nums">{{ $money($channel['projected']) }}</span></p>
                        @if ($channel['budget'] !== null)<p class="mt-0.5 text-xs text-gray-500">Bütçe <span class="tabular-nums">{{ $money($channel['budget']) }}</span>@if ($channel['share'] !== null) · %{{ number_format($channel['share'] * 100, 0) }}@endif</p>@endif
                        @if (($channel['accounts'] ?? []) !== [])
                            <ul class="mt-2 space-y-0.5 border-t border-gray-100 pt-2 text-xs text-gray-500 dark:border-gray-800" data-channel-accounts="{{ $channel['key'] }}">
                                @foreach ($channel['accounts'] as $account)
                                    <li class="flex justify-between gap-2"><span class="truncate">{{ $account['name'] }}</span><span class="shrink-0 tabular-nums">{{ number_format($account['spent'], 0, ',', '.') }} {{ $account['currency'] ?? '' }}</span></li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
        @if ($missingCommercial !== [] && $commercialSummary['fee'] === null && $commercialSummary['channels'] === [] && ! $editingCommercial)
            <div class="card-row card">
                <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span>
                <p class="min-w-0 flex-1 text-[13px] text-gray-500" data-missing-commercial>Eksik bilgi: {{ implode(', ', $missingCommercial) }}</p>
                <button type="button" wire:click="editCommercial" class="btn">Gir</button>
            </div>
        @elseif ($missingCommercial !== [])
            <p class="mt-2 text-[13px] text-gray-500" data-missing-commercial>Eksik bilgi: {{ implode(', ', $missingCommercial) }}</p>
        @endif
        @if ($healthReasons !== null || count($commercialSummary['health']) > 1)
            <div class="card mt-3" data-health-reasons>
                <p class="px-4 py-3 text-[13px] text-gray-500">
                    Sağlık puanı
                    @if ($healthReasons !== null)<span class="ml-1 text-sm font-semibold tabular-nums {{ $chip[$band[$healthReasons['band']] ?? 'warn'] }}">{{ $healthReasons['score'] }}</span>@endif
                    @if (count($commercialSummary['health']) > 1)
                        <span class="ml-2">geçmiş:
                            @foreach ($commercialSummary['health'] as $point)<span class="ml-1 tabular-nums" title="{{ $point['date'] }}">{{ $point['score'] }}</span>@if (! $loop->last), @endif @endforeach
                        </span>
                    @endif
                </p>
                @if ($healthReasons !== null)
                    <div class="border-t border-gray-200 px-4 py-3 dark:border-gray-800">
                        <p class="text-[13px] font-medium text-gray-700 dark:text-gray-300">Puanı düşürenler</p>
                        <ul class="mt-1 space-y-0.5 text-[13px] text-gray-600 dark:text-gray-400">
                            @forelse ($healthReasons['reasons'] as $reason)
                                <li><span class="tabular-nums text-error-600">−{{ $reason['points'] }}</span> {{ $reason['text'] }}</li>
                            @empty
                                <li>Puanı düşüren bir şey yok.</li>
                            @endforelse
                        </ul>
                    </div>
                @endif
            </div>
        @endif
    </section>

    <div class="grid gap-x-6 gap-y-10 lg:grid-cols-2">
        <section>
            <div class="section-head">
                <h2 class="section-title">Kişiler <span class="section-count">{{ count($contacts) }}</span></h2>
                <button type="button" wire:click="openContactForm" class="btn-quiet ml-auto h-7! px-2!">Ekle</button>
            </div>
            <ul class="card">
                @if (($customer['primary_email'] ?? null) || ($customer['primary_phone'] ?? null))
                    <li class="border-b border-gray-200 bg-gray-50/60 px-4 py-2.5 break-all text-[13px] text-gray-500 dark:border-gray-800 dark:bg-white/[0.02]">Genel iletişim: {{ collect([$customer['primary_email'] ?? null, $customer['primary_phone'] ?? null])->filter()->implode(', ') }}</li>
                @endif
                @forelse ($contacts as $contact)
                    <li class="card-row">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-medium text-gray-600 dark:bg-white/5 dark:text-gray-300" aria-hidden="true">{{ mb_strtoupper(mb_substr((string) $contact['name'], 0, 1)) }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $contact['name'] }}@if (! empty($contact['title'])) <span class="font-normal text-gray-500">{{ $contact['title'] }}</span>@endif</p>
                            @if (filled($contact['email'] ?? null) || filled($contact['phone'] ?? null))<p class="mt-0.5 break-all text-[13px] text-gray-500">{{ collect([$contact['email'] ?? null, $contact['phone'] ?? null])->filter()->implode(', ') }}</p>@endif
                        </div>
                        <div class="flex shrink-0 gap-1">
                            <button type="button" wire:click="openContactForm('{{ $contact['id'] }}')" class="btn-quiet h-7! px-2!">Düzenle</button>
                            <button type="button" wire:click="deleteContact('{{ $contact['id'] }}')" wire:confirm="Kişi silinsin mi?" class="btn-quiet h-7! px-2! hover:text-error-600!">Sil</button>
                        </div>
                    </li>
                @empty
                    <li class="px-4 py-3.5 text-[13px] text-gray-500">Kişi eklenmedi.</li>
                @endforelse
            </ul>
        </section>

        <section data-customer-team>
            <div class="section-head">
                <h2 class="section-title">Sorumlular ve hizmetler</h2>
                <a href="{{ route('operator.customer.edit', ['customerId' => $customer['id']]) }}" wire:navigate class="btn-quiet ml-auto h-7! px-2!">Düzenle</a>
            </div>
            <div class="card">
                @if ($responsibleUsers !== [] || $serviceLabels !== [] || ! empty($customer['service_started_at']) || ! empty($customer['legal_name']))
                    <dl class="text-sm">
                        @if ($responsibleUsers !== [])
                            <div class="border-t border-gray-200 px-4 py-3 first:border-t-0 dark:border-gray-800"><dt class="text-[13px] text-gray-500">Hesap sorumlusu ve ekip</dt><dd class="mt-0.5 text-gray-900 dark:text-white">
                                @foreach ($responsibleUsers as $index => $user)
                                    {{ $user['name'] }}@if ($index === 0) <span class="text-xs text-gray-500">(Hesap sorumlusu)</span>@endif@if (! $loop->last), @endif
                                @endforeach
                            </dd></div>
                        @endif
                        @if ($serviceLabels !== [])
                            <div class="border-t border-gray-200 px-4 py-3 first:border-t-0 dark:border-gray-800"><dt class="text-[13px] text-gray-500">Verilen hizmetler</dt><dd class="mt-0.5 text-gray-900 dark:text-white">{{ implode(', ', $serviceLabels) }}</dd></div>
                        @endif
                        @if (! empty($customer['service_started_at']))
                            <div class="border-t border-gray-200 px-4 py-3 first:border-t-0 dark:border-gray-800"><dt class="text-[13px] text-gray-500">Hizmet başlangıcı</dt><dd class="mt-0.5 tabular-nums text-gray-900 dark:text-white">{{ $customer['service_started_at'] }}</dd></div>
                        @endif
                        @if (! empty($customer['legal_name']))
                            <div class="border-t border-gray-200 px-4 py-3 first:border-t-0 dark:border-gray-800"><dt class="text-[13px] text-gray-500">Ticari unvan</dt><dd class="mt-0.5 break-words text-gray-900 dark:text-white">{{ $customer['legal_name'] }}</dd></div>
                        @endif
                    </dl>
                @endif
                @if ($serviceScope !== [])
                    <div class="border-t border-gray-200 px-4 py-3 first:border-t-0 dark:border-gray-800">
                        <h3 class="text-[13px] text-gray-500">Hizmet kapsamı</h3>
                        <ul class="mt-1 space-y-1 text-sm">
                            @foreach ($serviceScope as $scope)
                                <li class="text-gray-900 dark:text-white">{{ $scope['service_label'] ?? '—' }}@if (! empty($scope['brand_name'])) <span class="text-xs text-gray-500">{{ $scope['brand_name'] }}</span>@endif @if (! empty($scope['owner_name']))<span class="text-xs text-gray-500">, {{ $scope['owner_name'] }}</span>@endif</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if ($missingTeam !== [])
                    <p class="border-t border-gray-200 bg-gray-50/60 px-4 py-2.5 text-[13px] text-gray-500 first:border-t-0 dark:border-gray-800 dark:bg-white/[0.02]" data-missing-team>Eksik bilgi: {{ implode(', ', $missingTeam) }}. <a href="{{ route('operator.customer.edit', ['customerId' => $customer['id']]) }}" wire:navigate class="link-neutral font-normal">düzenle</a></p>
                @endif
            </div>
        </section>
    </div>

    <div x-data="{ open: @entangle('showContactForm') }">
        <x-ta.modal>
            <h3 class="text-[15px] font-semibold text-gray-900 dark:text-white">{{ $editingContactId ? 'Kişiyi düzenle' : 'Kişi ekle' }}</h3>
            <div class="mt-4 space-y-3">
                <x-ta.form.field label="Ad soyad" :required="true" :error="$errors->first('contact_name')">
                    <input wire:model="contact_name" type="text" class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                </x-ta.form.field>
                <x-ta.form.field label="Rol / unvan" :error="$errors->first('contact_role')">
                    <x-ta.form.select wire:model.live="contact_role" :options="$roleOptions" placeholder="Rol seç…" />
                </x-ta.form.field>
                @if ($contact_role === 'other')
                    <x-ta.form.field label="Unvan" :error="$errors->first('contact_title_custom')">
                        <input wire:model="contact_title_custom" type="text" class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                    </x-ta.form.field>
                @endif
                <x-ta.form.field label="E-posta" :error="$errors->first('contact_email')">
                    <input wire:model="contact_email" type="email" class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                </x-ta.form.field>
                <x-ta.form.field label="Telefon" :error="$errors->first('contact_phone')">
                    <input wire:model="contact_phone" type="tel" class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                </x-ta.form.field>
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" wire:click="closeContactForm" class="btn">Vazgeç</button>
                <button type="button" wire:click="saveContact" class="btn-primary">Kaydet</button>
            </div>
        </x-ta.modal>
    </div>
</div>
