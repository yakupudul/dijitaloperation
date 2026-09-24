@php
    $card = 'rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $tabs = ['overview' => __('operator.customer.tabs.overview'), 'reports' => __('operator.customer.tabs.reports')];
@endphp
<div class="space-y-6" x-data="{ moreOpen: false }">
    @include('livewire.demo.partials.flash')

    {{-- Header --}}
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="min-w-0">
            <a href="{{ route('operator.customers') }}" wire:navigate class="text-sm text-gray-500 hover:text-brand-600">← {{ __('operator.nav.customers') }}</a>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">{{ $customer['name'] }}</h1>
                <x-ta.badge :color="match($customer['status'] ?? '') { 'active' => 'success', 'inactive' => 'warning', default => 'light' }" size="sm">{{ $statusLabel }}</x-ta.badge>
            </div>
            <p class="mt-1 text-sm text-gray-500">{{ collect([$typeLabel, $industryLabel !== '—' ? $industryLabel : null, $hqDisplay !== '—' ? $hqDisplay : null])->filter()->implode(' · ') }}</p>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-2">
            <a href="{{ route('operator.brand.create', ['customerId' => $customer['id']]) }}" wire:navigate class="inline-flex rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600">{{ __('operator.customer.actions.add_brand') }}</a>
            <button type="button" wire:click="openContactForm" class="inline-flex rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">{{ __('operator.customer.actions.add_contact') }}</button>
            <a href="{{ route('operator.customer.edit', ['customerId' => $customer['id']]) }}" wire:navigate class="inline-flex rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">{{ __('operator.customer.actions.edit') }}</a>
            <div class="relative">
                <button type="button" @click="moreOpen = !moreOpen" class="inline-flex rounded-lg px-2.5 py-2 text-sm text-gray-500 ring-1 ring-inset ring-gray-300 dark:ring-gray-700" aria-label="Diğer">⋯</button>
                <div x-show="moreOpen" @click.outside="moreOpen = false" x-cloak class="absolute right-0 z-20 mt-1 w-48 rounded-lg border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-900">
                    <a href="{{ route('operator.files', ['scope' => 'customer', 'customer' => $customer['id']]) }}" wire:navigate class="block px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-white/5">{{ __('operator.customer.actions.open_files') }}</a>
                    <a href="{{ route('operator.activity', ['customer' => $customer['id']]) }}" wire:navigate class="block px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-white/5">{{ __('operator.customer.actions.view_activity') }}</a>
                    @if (($customer['status'] ?? '') === 'archived')
                        <button type="button" wire:click="restoreCustomer" class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/5">Müşteriyi geri al</button>
                    @else
                        <button type="button" wire:click="archiveCustomer" wire:confirm="Müşteri arşivlensin mi? Veriler silinmez." class="block w-full px-3 py-2 text-left text-sm text-error-600 hover:bg-gray-50 dark:hover:bg-white/5">Müşteriyi arşivle</button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="-mx-1 overflow-x-auto">
        <div class="flex min-w-max gap-1 border-b border-gray-200 px-1 dark:border-gray-800" role="tablist">
            @foreach ($tabs as $key => $label)
                <button type="button" role="tab" wire:click="setTab('{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" @class(['border-b-2 px-3 py-2 text-sm font-medium', 'border-brand-500 text-brand-600 dark:text-brand-400' => $tab === $key, 'border-transparent text-gray-600 hover:text-gray-900 dark:text-gray-400' => $tab !== $key])>{{ $label }}</button>
            @endforeach
        </div>
    </div>

    {{-- ============================================================ OVERVIEW --}}
    @if ($tab === 'overview')
        @php
            $money = static fn (?float $v): string => $v !== null ? '₺'.number_format($v, 0, ',', '.') : '—';
            $stateLabel = ['over' => ['Bütçeyi aşacak', 'text-rose-700 bg-rose-50'], 'under' => ['Bütçenin altında', 'text-amber-700 bg-amber-50'], 'on_track' => ['Hedefte', 'text-emerald-700 bg-emerald-50'], 'no_budget' => ['Bütçe girilmemiş', 'text-gray-600 bg-gray-100']];
        @endphp
        @if ($briefInsight)<x-operator.ai-insight :insight="$briefInsight" />@endif
        <section class="{{ $card }} p-5" data-customer-commercial>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Ücret ve reklam bütçesi</h2>
                    <p class="mt-1 text-xs text-gray-500">Bu ay {{ $commercialSummary['days']['elapsed'] }}/{{ $commercialSummary['days']['total'] }} gün · ay sonu tahmini bugüne kadarki harcamanın doğrusal uzantısıdır.</p>
                </div>
                <button type="button" wire:click="editCommercial" class="text-xs font-medium text-brand-600 hover:underline">Düzenle</button>
            </div>
            @if ($editingCommercial)
                <form wire:submit="saveCommercial" class="mt-4 grid gap-3 sm:grid-cols-4">
                    @foreach (['monthly_fee' => 'Aylık ücret (₺)', 'ad_budget_google' => 'Google Ads aylık bütçe (₺)', 'ad_budget_meta' => 'Meta aylık bütçe (₺)'] as $field => $label)
                        <label class="block text-sm"><span class="text-gray-500">{{ $label }}</span>
                            <input type="text" inputmode="decimal" wire:model="commercial.{{ $field }}" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700 dark:text-white" />
                            @error('commercial.'.$field) <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                        </label>
                    @endforeach
                    <div class="flex items-end"><button type="submit" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white">Kaydet</button></div>
                </form>
            @endif
            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/[0.03]"><p class="text-xs text-gray-500">Aylık ücret</p><p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ $money($commercialSummary['fee']) }}</p></div>
                @foreach ($commercialSummary['channels'] as $channel)
                    <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/[0.03]">
                        <div class="flex items-center justify-between gap-2"><p class="text-xs text-gray-500">{{ $channel['label'] }}</p><span class="rounded-full px-2 py-0.5 text-[11px] {{ $stateLabel[$channel['state']][1] }}">{{ $stateLabel[$channel['state']][0] }}</span></div>
                        <p class="mt-1 text-sm text-gray-800 dark:text-gray-200"><strong>{{ $money($channel['spent']) }}</strong> harcandı · ay sonu ~{{ $money($channel['projected']) }}</p>
                        <p class="text-xs text-gray-500">Bütçe {{ $money($channel['budget']) }}@if ($channel['share'] !== null) · %{{ number_format($channel['share'] * 100, 0) }}@endif</p>
                    </div>
                @endforeach
            </div>
            @if (count($commercialSummary['health']) > 1)
                <p class="mt-3 text-xs text-gray-500">Sağlık puanı geçmişi:
                    @foreach ($commercialSummary['health'] as $point)
                        <span class="ml-1 tabular-nums" title="{{ $point['date'] }}">{{ $point['score'] }}</span>@if (! $loop->last)<span class="text-gray-300">→</span>@endif
                    @endforeach
                </p>
            @endif
        </section>

        {{-- Brands first: this is where the work happens --}}
        <section class="{{ $card }}">
            <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3 dark:border-gray-800">
                <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Markalar</h2>
                <a href="{{ route('operator.brand.create', ['customerId' => $customer['id']]) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">+ {{ __('operator.customer.actions.add_brand') }}</a>
            </div>
            @forelse ($brands as $brand)
                <a href="{{ route('operator.brand', ['brand' => $brand['id']]) }}" wire:navigate class="flex flex-wrap items-center gap-4 border-b border-gray-100 px-5 py-3 last:border-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03]">
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold text-gray-800 dark:text-white/90">{{ $brand['name'] }}</span>
                        <span class="block text-xs text-gray-500">{{ $brand['sector_label'] !== '—' ? $brand['sector_label'].' · ' : '' }}{{ $brand['accounts'] !== [] ? implode(', ', $brand['accounts']) : 'Bağlı hesap yok' }}</span>
                    </span>
                    <span @class(['rounded-full px-2 py-0.5 text-xs', 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400' => $brand['setup']['complete'], 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400' => ! $brand['setup']['complete']])>
                        {{ $brand['setup']['complete'] ? 'Kurulum tamam' : 'Kurulum '.$brand['setup']['done'].'/'.$brand['setup']['total'] }}
                    </span>
                    <span class="w-28 text-right text-xs text-gray-500">{{ $brand['open_findings'] }} bulgu · {{ $brand['open_tasks'] }} görev</span>
                </a>
            @empty
                <div class="px-5 py-6 text-sm text-gray-500">
                    Bu müşterinin markası yok.
                    <a href="{{ route('operator.brand.create', ['customerId' => $customer['id']]) }}" wire:navigate class="font-medium text-brand-600 hover:underline">İlk markayı ekle</a> — web sitesini girersen hesapları ve hizmetleri "Otomatik kur" bulur.
                </div>
            @endforelse
        </section>

        @if ($overdueTasks !== [] || $attentionFindings !== [])
            <section class="{{ $card }}">
                <h2 class="border-b border-gray-100 px-5 py-3 text-base font-semibold text-gray-800 dark:border-gray-800 dark:text-white/90">Dikkat gerektirenler</h2>
                @foreach ($overdueTasks as $task)
                    <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-2.5 text-sm last:border-0 dark:border-gray-800"><span><span class="text-error-500">●</span> Geciken görev: {{ $task['title'] }}</span><a href="{{ route('operator.work.show', ['workId' => $task['id'], 'type' => 'task']) }}" wire:navigate class="text-xs text-brand-600 hover:underline">Aç →</a></div>
                @endforeach
                @foreach ($attentionFindings as $finding)
                    <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-2.5 text-sm last:border-0 dark:border-gray-800"><span><span class="text-warning-500">●</span> {{ $finding['title'] }}</span><span class="text-xs text-gray-500">{{ $finding['brand'] ?? '' }}</span></div>
                @endforeach
            </section>
        @endif

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="{{ $card }}">
                <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3 dark:border-gray-800">
                    <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Kişiler</h2>
                    <button type="button" wire:click="openContactForm" class="text-xs font-medium text-brand-600 hover:underline">+ {{ __('operator.customer.actions.add_contact') }}</button>
                </div>
                @if (($customer['primary_email'] ?? null) || ($customer['primary_phone'] ?? null))
                    <p class="border-b border-gray-100 px-5 py-2.5 text-xs text-gray-500 dark:border-gray-800">Genel iletişim: {{ collect([$customer['primary_email'] ?? null, $customer['primary_phone'] ?? null])->filter()->implode(' · ') }}</p>
                @endif
                @forelse ($contacts as $contact)
                    <div class="flex items-start justify-between gap-3 border-b border-gray-100 px-5 py-3 last:border-0 dark:border-gray-800">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $contact['name'] }}@if (! empty($contact['title'])) <span class="font-normal text-gray-500">· {{ $contact['title'] }}</span>@endif</p>
                            <p class="text-xs text-gray-500">{{ collect([$contact['email'] ?? null, $contact['phone'] ?? null])->filter()->implode(' · ') ?: '—' }}</p>
                        </div>
                        <div class="flex shrink-0 gap-2 text-xs">
                            <button type="button" wire:click="openContactForm('{{ $contact['id'] }}')" class="text-brand-600 hover:underline">Düzenle</button>
                            <button type="button" wire:click="deleteContact('{{ $contact['id'] }}')" wire:confirm="Kişi silinsin mi?" class="text-gray-500 hover:text-error-600">Sil</button>
                        </div>
                    </div>
                @empty
                    <p class="px-5 py-4 text-sm text-gray-500">Kişi eklenmedi.</p>
                @endforelse
            </section>

            <section class="{{ $card }} p-5">
                <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ __('operator.portfolio.account_owner_responsible') }}</h2>
                <dl class="mt-3 space-y-3 text-sm">
                    <div><dt class="text-xs text-gray-500">Sorumlu ekip</dt><dd class="mt-0.5 text-gray-800 dark:text-white/90">
                        @forelse ($responsibleUsers as $index => $user)
                            {{ $user['name'] }}@if ($index === 0) <x-ta.badge color="light" size="sm">Account Owner</x-ta.badge>@endif@if (! $loop->last), @endif
                        @empty — @endforelse
                    </dd></div>
                    <div><dt class="text-xs text-gray-500">Verilen hizmetler</dt><dd class="mt-0.5 text-gray-800 dark:text-white/90">{{ $serviceLabels !== [] ? implode(', ', $serviceLabels) : '—' }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Hizmet başlangıcı</dt><dd class="mt-0.5 text-gray-800 dark:text-white/90">{{ $customer['service_started_at'] ?? '—' }}</dd></div>
                    @if (! empty($customer['legal_name']))
                        <div><dt class="text-xs text-gray-500">Ticari unvan</dt><dd class="mt-0.5 text-gray-800 dark:text-white/90">{{ $customer['legal_name'] }}</dd></div>
                    @endif
                </dl>
                <h3 class="mt-5 text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.service_scope.title') }}</h3>
                <ul class="mt-2 space-y-1 text-sm">
                    @forelse ($serviceScope as $scope)
                        <li class="text-gray-700 dark:text-gray-300">{{ $scope['service_label'] ?? '—' }}@if (! empty($scope['brand_name'])) <span class="text-xs text-gray-500">· {{ $scope['brand_name'] }}</span>@endif @if (! empty($scope['owner_name']))<span class="text-xs text-gray-500">· {{ $scope['owner_name'] }}</span>@endif</li>
                    @empty
                        <li class="text-gray-500">—</li>
                    @endforelse
                </ul>
            </section>
        </div>
    @endif

    {{-- ============================================================ REPORTS --}}
    @if ($tab === 'reports')
        <section class="{{ $card }} p-5">
            <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ __('operator.reports.customer_title') }}</h2>
            <p class="mt-1 text-sm text-gray-500">{{ __('operator.reports.customer_subtitle') }}</p>
            <p class="mt-1 text-xs text-gray-400">{{ $customerReports['aggregation_note'] ?? '' }}</p>
            <ul class="mt-4 divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($customerReports['snapshots'] ?? [] as $snap)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                        <div>
                            <p class="font-medium text-gray-800 dark:text-white/90">{{ $snap['title'] }}</p>
                            <p class="text-xs text-gray-500">{{ $snap['brand_name'] }} · {{ $snap['period_start'] }} → {{ $snap['period_end'] }} · {{ __('operator.reports.generated_at') }} {{ $snap['generated_at'] }}</p>
                        </div>
                        <a href="{{ $snap['view_url'] }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">{{ __('operator.reports.view_snapshot') }}</a>
                    </li>
                @empty
                    <li class="py-4 text-sm text-gray-500">{{ __('operator.reports.empty_snapshots') }}</li>
                @endforelse
            </ul>
        </section>
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ($customerReports['brands'] ?? [] as $reportCard)
                <div class="{{ $card }} p-4">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ $reportCard['brand_name'] }}</h3>
                    <p class="mt-1 text-xs text-gray-400">{{ __('operator.reports.brand_scoped_note') }}</p>
                    <a href="{{ $reportCard['report_url'] }}" wire:navigate class="mt-3 inline-block text-sm font-medium text-brand-600 hover:underline">{{ __('operator.reports.open_brand_report') }}</a>
                </div>
            @endforeach
        </div>
    @endif

    <div x-data="{ open: @entangle('showContactForm') }">
        <x-ta.modal>
            <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ $editingContactId ? 'Kişiyi düzenle' : 'Kişi ekle' }}</h3>
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
                <button type="button" wire:click="saveContact" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white hover:bg-brand-600">Kaydet</button>
            </div>
        </x-ta.modal>
    </div>
</div>
