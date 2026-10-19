@php
    $card = 'surface';
    $chip = ['ok' => 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-300', 'warn' => 'bg-warning-50 text-warning-800 dark:bg-warning-500/10 dark:text-warning-300', 'bad' => 'bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-300', 'muted' => 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300'];
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
<div class="space-y-5 text-sm dark:text-gray-200" x-data="{ moreOpen: false }" data-customer-detail>
    @include('livewire.demo.partials.flash')

    <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <a href="{{ route('operator.brands') }}" wire:navigate class="text-xs text-gray-500 hover:text-gray-900 dark:hover:text-white" aria-label="Markalar">←</a>
                <h1 class="break-words text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ $customer['name'] }}</h1>
                @if (($customer['status'] ?? '') !== 'active')<x-ta.pill tone="info">{{ $statusLabel }}</x-ta.pill>@endif
            </div>
            <p class="mt-1 text-xs text-gray-500" data-customer-meta>
                {{ collect([$typeLabel, $industryLabel !== '—' ? $industryLabel : null, $hqDisplay !== '—' ? $hqDisplay : null])->filter()->implode(' · ') }}
                · {{ count($brands) }} marka · {{ $digitalAssetsCount }} varlık
                · <a href="{{ route('operator.assets', ['customer' => $customer['id']]) }}" wire:navigate class="link-neutral font-normal">varlıkları gör</a>
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <x-ta.button size="sm" :href="route('operator.brand.create', ['customerId' => $customer['id']])" wire:navigate>Marka ekle</x-ta.button>
            <x-ta.button variant="outline" size="sm" wire:click="openContactForm">Kişi ekle</x-ta.button>
            <x-ta.button variant="outline" size="sm" :href="route('operator.customer.edit', ['customerId' => $customer['id']])" wire:navigate>Düzenle</x-ta.button>
            <div class="relative">
                <button type="button" @click="moreOpen = !moreOpen" class="inline-flex h-8 items-center rounded-lg px-2.5 text-sm text-gray-500 ring-1 ring-inset ring-gray-300 dark:ring-gray-700" aria-label="Diğer">⋯</button>
                <div x-show="moreOpen" @click.outside="moreOpen = false" x-cloak class="absolute right-0 z-20 mt-1 w-48 rounded-lg border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-900">
                    <a href="{{ route('operator.files', ['scope' => 'customer', 'customer' => $customer['id']]) }}" wire:navigate class="block px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-white/5">Dosyalar</a>
                    @if (($customer['status'] ?? '') === 'archived')
                        <button type="button" wire:click="restoreCustomer" class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/5">Müşteriyi geri al</button>
                    @else
                        <button type="button" wire:click="archiveCustomer" wire:confirm="Müşteri arşivlensin mi? Veriler silinmez." class="block w-full px-3 py-2 text-left text-sm text-error-600 hover:bg-gray-50 dark:hover:bg-white/5">Müşteriyi arşivle</button>
                    @endif
                </div>
            </div>
        </div>
    </header>

    @if ($attentionBrands->isNotEmpty())
        <section class="tone-look rounded-xl p-3 text-sm" data-customer-attention>
            <p class="font-medium">{{ $attentionBrands->count() }} marka dikkat istiyor</p>
            <ul class="mt-1 space-y-0.5 text-xs">
                @foreach ($attentionBrands as $brand)
                    <li class="break-words"><a href="{{ route('operator.brand', ['brand' => $brand['id']]) }}" wire:navigate class="font-medium hover:underline">{{ $brand['name'] }}</a> · {{ $brand['attention_reason'] }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Brands first: this is where the work happens --}}
    <section class="{{ $card }}" data-customer-brands>
        <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-800">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Markalar</h2>
            <a href="{{ route('operator.brand.create', ['customerId' => $customer['id']]) }}" wire:navigate class="link-neutral text-xs">+ Marka ekle</a>
        </div>
        @if ($brands === [])
            <div class="px-4 py-6 text-sm text-gray-500">
                Bu müşterinin markası yok.
                <a href="{{ route('operator.brand.create', ['customerId' => $customer['id']]) }}" wire:navigate class="link-neutral">İlk markayı ekle</a>; web sitesini girersen hesapları ve hizmetleri "Otomatik kur" bulur.
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-gray-100 text-xs text-gray-500 dark:border-gray-800">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">Marka</th>
                            <th class="hidden px-4 py-2.5 font-medium sm:table-cell">Kanallar</th>
                            <th class="px-4 py-2.5 text-right font-medium">Açık iş</th>
                            <th class="px-4 py-2.5 font-medium">Durum</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($brands as $brand)
                            <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.03]" wire:key="customer-brand-{{ $brand['id'] }}" data-customer-brand="{{ $brand['id'] }}">
                                <td class="px-4 py-3">
                                    <div class="flex min-w-0 items-start gap-2.5">
                                    <x-ta.brand-avatar :name="$brand['name']" :logo="$brand['logo_url'] ?? null" size="sm" class="mt-0.5" />
                                    <div class="min-w-0">
                                    <a href="{{ route('operator.brand', ['brand' => $brand['id']]) }}" wire:navigate class="break-words font-medium text-gray-900 hover:text-brand-600 dark:text-white">{{ $brand['name'] }}</a>
                                    <p class="break-all text-xs text-gray-500">{{ collect([$brand['website'], $brand['sector_label'] !== '—' ? $brand['sector_label'] : null])->filter()->implode(' · ') ?: 'Web sitesi yok' }}</p>
                                    <div class="mt-1 sm:hidden">@include('livewire.demo.portfolio.partials.channel-dots', ['channels' => $brand['channels']])</div>
                                    </div>
                                    </div>
                                </td>
                                <td class="hidden px-4 py-3 sm:table-cell">@include('livewire.demo.portfolio.partials.channel-dots', ['channels' => $brand['channels']])</td>
                                <td class="px-4 py-3 text-right tabular-nums" data-open-work>
                                    <span class="font-medium text-gray-900 dark:text-white">{{ $brand['open_work'] }}</span>
                                    @if ($brand['open_by_channel'] !== [])<p class="mt-0.5 flex flex-wrap justify-end gap-x-1.5 text-[11px] leading-4 text-gray-500">@foreach ($brand['open_by_channel'] as $label => $n)<span class="whitespace-nowrap">{{ $label }} {{ $n }}</span>@endforeach</p>@endif
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        @if ($brand['needs_attention'] && ($brand['reconnect'] > 0 || ($brand['critical'] ?? 0) > 0))
                                            <x-ta.pill :tone="$brand['reconnect'] > 0 ? 'critical' : 'look'">Dikkat</x-ta.pill>
                                        @endif
                                        @if ($brand['setup']['complete'])
                                            <span class="text-xs text-gray-400">Kurulum tamam</span>
                                        @else
                                            <x-ta.pill tone="look" :dot="false" class="tabular-nums">Kurulum {{ $brand['setup']['done'] }}/{{ $brand['setup']['total'] }}</x-ta.pill>
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

    <section class="{{ $card }} p-4" data-customer-commercial>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Ücret ve reklam bütçesi</h2>
                <p class="mt-1 text-xs text-gray-500">Bu ay {{ $commercialSummary['days']['elapsed'] }}/{{ $commercialSummary['days']['total'] }} gün · ay sonu tahmini bugüne kadarki harcamanın doğrusal uzantısıdır.</p>
            </div>
            <button type="button" wire:click="editCommercial" class="link-neutral text-xs">Düzenle</button>
        </div>
        @if ($editingCommercial)
            <form wire:submit="saveCommercial" class="mt-4 grid gap-3 sm:grid-cols-4">
                @foreach (['monthly_fee' => 'Aylık ücret (₺)', 'ad_budget_google' => 'Google Ads aylık bütçe (₺)', 'ad_budget_meta' => 'Meta aylık bütçe (₺)'] as $field => $label)
                    <label class="block text-sm"><span class="text-gray-500">{{ $label }}</span>
                        <input type="text" inputmode="decimal" wire:model="commercial.{{ $field }}" class="mt-1 w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 dark:border-gray-700 dark:text-white" />
                        @error('commercial.'.$field) <span class="text-xs text-error-600">{{ $message }}</span> @enderror
                    </label>
                @endforeach
                <div class="flex items-end"><x-ta.button type="submit" size="md" class="w-full sm:w-auto">Kaydet</x-ta.button></div>
            </form>
        @endif
        @if ($commercialSummary['fee'] !== null || $commercialSummary['channels'] !== [])
        <div class="mt-4 grid gap-3 sm:grid-cols-3">
            @if ($commercialSummary['fee'] !== null)
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]"><p class="text-xs text-gray-500">Aylık ücret</p><p class="mt-1 text-lg font-semibold tabular-nums text-gray-900 dark:text-white">{{ $money($commercialSummary['fee']) }}</p></div>
            @endif
            @foreach ($commercialSummary['channels'] as $channel)
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                    <div class="flex items-center justify-between gap-2"><p class="text-xs text-gray-500">{{ $channel['label'] }}</p>@if ($channel['state'] !== 'no_budget')<span class="rounded-full px-2 py-0.5 text-[11px] {{ $stateLabel[$channel['state']][1] }}">{{ $stateLabel[$channel['state']][0] }}</span>@endif</div>
                    <p class="mt-1 text-sm text-gray-800 dark:text-gray-200"><strong class="tabular-nums">{{ $money($channel['spent']) }}</strong> harcandı · ay sonu ~<span class="tabular-nums">{{ $money($channel['projected']) }}</span></p>
                    @if ($channel['budget'] !== null)<p class="text-xs text-gray-500">Bütçe <span class="tabular-nums">{{ $money($channel['budget']) }}</span>@if ($channel['share'] !== null) · %{{ number_format($channel['share'] * 100, 0) }}@endif</p>@endif
                    @if (($channel['accounts'] ?? []) !== [])
                        <ul class="mt-2 space-y-0.5 text-xs text-gray-500" data-channel-accounts="{{ $channel['key'] }}">
                            @foreach ($channel['accounts'] as $account)
                                <li class="flex justify-between gap-2"><span class="truncate">{{ $account['name'] }}</span><span class="shrink-0 tabular-nums">{{ number_format($account['spent'], 0, ',', '.') }} {{ $account['currency'] ?? '' }}</span></li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endforeach
        </div>
        @endif
        @if ($missingCommercial !== [])
            <p class="mt-3 text-xs text-gray-400" data-missing-commercial>Eksik bilgi: {{ implode(' · ', $missingCommercial) }}</p>
        @endif
        @if ($healthReasons !== null || count($commercialSummary['health']) > 1)
            <div class="mt-4 border-t border-gray-100 pt-3 dark:border-gray-800" data-health-reasons>
                <p class="text-xs text-gray-500">
                    Sağlık puanı
                    @if ($healthReasons !== null)<span class="ml-1 rounded-full px-1.5 py-0.5 font-semibold tabular-nums {{ $chip[$band[$healthReasons['band']] ?? 'warn'] }}">{{ $healthReasons['score'] }}</span>@endif
                    @if (count($commercialSummary['health']) > 1)
                        <span class="ml-2">geçmiş:
                            @foreach ($commercialSummary['health'] as $point)<span class="ml-1 tabular-nums" title="{{ $point['date'] }}">{{ $point['score'] }}</span>@if (! $loop->last)<span class="text-gray-300">→</span>@endif @endforeach
                        </span>
                    @endif
                </p>
                @if ($healthReasons !== null)
                    <p class="mt-2 text-xs font-medium text-gray-700 dark:text-gray-300">Puanı düşürenler</p>
                    <ul class="mt-1 space-y-0.5 text-xs text-gray-600 dark:text-gray-400">
                        @forelse ($healthReasons['reasons'] as $reason)
                            <li><span class="tabular-nums text-error-600">−{{ $reason['points'] }}</span> {{ $reason['text'] }}</li>
                        @empty
                            <li>Puanı düşüren bir şey yok.</li>
                        @endforelse
                    </ul>
                @endif
            </div>
        @endif
    </section>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="{{ $card }}">
            <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Kişiler</h2>
                <button type="button" wire:click="openContactForm" class="link-neutral text-xs">+ Kişi ekle</button>
            </div>
            @if (($customer['primary_email'] ?? null) || ($customer['primary_phone'] ?? null))
                <p class="break-all border-b border-gray-100 px-4 py-2.5 text-xs text-gray-500 dark:border-gray-800">Genel iletişim: {{ collect([$customer['primary_email'] ?? null, $customer['primary_phone'] ?? null])->filter()->implode(' · ') }}</p>
            @endif
            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($contacts as $contact)
                    <div class="flex items-start justify-between gap-3 px-4 py-3">
                        <div class="min-w-0">
                            <p class="font-medium text-gray-900 dark:text-white">{{ $contact['name'] }}@if (! empty($contact['title'])) <span class="font-normal text-gray-500">· {{ $contact['title'] }}</span>@endif</p>
                            @if (filled($contact['email'] ?? null) || filled($contact['phone'] ?? null))<p class="break-all text-xs text-gray-500">{{ collect([$contact['email'] ?? null, $contact['phone'] ?? null])->filter()->implode(' · ') }}</p>@endif
                        </div>
                        <div class="flex shrink-0 gap-2 text-xs">
                            <button type="button" wire:click="openContactForm('{{ $contact['id'] }}')" class="link-neutral">Düzenle</button>
                            <button type="button" wire:click="deleteContact('{{ $contact['id'] }}')" wire:confirm="Kişi silinsin mi?" class="text-gray-500 hover:text-error-600">Sil</button>
                        </div>
                    </div>
                @empty
                    <p class="px-4 py-4 text-sm text-gray-500">Kişi eklenmedi.</p>
                @endforelse
            </div>
        </section>

        <section class="{{ $card }} p-4" data-customer-team>
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Sorumlular ve hizmetler</h2>
            <dl class="mt-3 space-y-3 text-sm">
                @if ($responsibleUsers !== [])
                    <div><dt class="text-xs text-gray-500">Hesap sorumlusu ve ekip</dt><dd class="mt-0.5 text-gray-900 dark:text-white">
                        @foreach ($responsibleUsers as $index => $user)
                            {{ $user['name'] }}@if ($index === 0) <span class="rounded-full bg-gray-100 px-1.5 py-0.5 text-[11px] text-gray-600 dark:bg-white/10 dark:text-gray-300">Hesap sorumlusu</span>@endif@if (! $loop->last), @endif
                        @endforeach
                    </dd></div>
                @endif
                @if ($serviceLabels !== [])
                    <div><dt class="text-xs text-gray-500">Verilen hizmetler</dt><dd class="mt-0.5 text-gray-900 dark:text-white">{{ implode(', ', $serviceLabels) }}</dd></div>
                @endif
                @if (! empty($customer['service_started_at']))
                    <div><dt class="text-xs text-gray-500">Hizmet başlangıcı</dt><dd class="mt-0.5 tabular-nums text-gray-900 dark:text-white">{{ $customer['service_started_at'] }}</dd></div>
                @endif
                @if (! empty($customer['legal_name']))
                    <div><dt class="text-xs text-gray-500">Ticari unvan</dt><dd class="mt-0.5 break-words text-gray-900 dark:text-white">{{ $customer['legal_name'] }}</dd></div>
                @endif
            </dl>
            @if ($serviceScope !== [])
                <h3 class="mt-5 text-xs font-medium text-gray-500">Hizmet kapsamı</h3>
                <ul class="mt-1 space-y-1 text-sm">
                    @foreach ($serviceScope as $scope)
                        <li class="text-gray-700 dark:text-gray-300">{{ $scope['service_label'] ?? '—' }}@if (! empty($scope['brand_name'])) <span class="text-xs text-gray-500">· {{ $scope['brand_name'] }}</span>@endif @if (! empty($scope['owner_name']))<span class="text-xs text-gray-500">· {{ $scope['owner_name'] }}</span>@endif</li>
                    @endforeach
                </ul>
            @endif
            @if ($missingTeam !== [])
                <p class="mt-3 text-xs text-gray-400" data-missing-team>Eksik bilgi: {{ implode(' · ', $missingTeam) }} — <a href="{{ route('operator.customer.edit', ['customerId' => $customer['id']]) }}" wire:navigate class="link-neutral font-normal">düzenle</a></p>
            @endif
        </section>
    </div>

    <div x-data="{ open: @entangle('showContactForm') }">
        <x-ta.modal>
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $editingContactId ? 'Kişiyi düzenle' : 'Kişi ekle' }}</h3>
            <div class="mt-4 space-y-3">
                <x-ta.form.field label="Ad soyad" :required="true" :error="$errors->first('contact_name')">
                    <input wire:model="contact_name" type="text" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                </x-ta.form.field>
                <x-ta.form.field label="Rol / unvan" :error="$errors->first('contact_role')">
                    <x-ta.form.select wire:model.live="contact_role" :options="$roleOptions" placeholder="Rol seç…" />
                </x-ta.form.field>
                @if ($contact_role === 'other')
                    <x-ta.form.field label="Unvan" :error="$errors->first('contact_title_custom')">
                        <input wire:model="contact_title_custom" type="text" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm dark:border-gray-700 dark:bg-gray-900" />
                    </x-ta.form.field>
                @endif
                <x-ta.form.field label="E-posta" :error="$errors->first('contact_email')">
                    <input wire:model="contact_email" type="email" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm dark:border-gray-700 dark:bg-gray-900" />
                </x-ta.form.field>
                <x-ta.form.field label="Telefon" :error="$errors->first('contact_phone')">
                    <input wire:model="contact_phone" type="tel" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm dark:border-gray-700 dark:bg-gray-900" />
                </x-ta.form.field>
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" wire:click="closeContactForm" class="rounded-lg px-3 py-2 text-sm ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Vazgeç</button>
                <x-ta.button size="md" wire:click="saveContact">Kaydet</x-ta.button>
            </div>
        </x-ta.modal>
    </div>
</div>
