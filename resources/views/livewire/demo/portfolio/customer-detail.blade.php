<div class="space-y-5" x-data="{ archiveOpen: false }">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('demo.customers') }}" wire:navigate class="text-sm font-medium text-gray-500 hover:text-brand-600 dark:text-gray-400">← Customers</a>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">{{ $customer['name'] }}</h1>
                <x-ta.badge color="light" size="sm">{{ $typeLabel }}</x-ta.badge>
                <x-ta.badge :color="match($customer['status'] ?? '') { 'active' => 'success', 'inactive' => 'warning', 'archived' => 'light', default => 'light' }" size="sm">
                    {{ $statusLabel }}
                </x-ta.badge>
            </div>
            <p class="mt-1 text-sm text-gray-500">{{ $industryLabel }} · {{ $hqDisplay }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @include('livewire.demo.partials.demo-badge')
            <button type="button" wire:click="openContactForm" class="inline-flex rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Add contact</button>
            <a href="{{ route('demo.customer.edit', ['customerId' => $customer['id']]) }}" wire:navigate class="inline-flex rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Edit customer</a>
            <a href="{{ route('demo.brand.create', ['customerId' => $customer['id']]) }}" wire:navigate class="inline-flex rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white hover:bg-brand-600">Add brand</a>
            <div class="relative">
                <button type="button" @click="archiveOpen = !archiveOpen" class="inline-flex rounded-lg px-2 py-2 text-sm text-gray-500 ring-1 ring-inset ring-gray-300 dark:ring-gray-700" aria-label="More actions">⋯</button>
                <div x-show="archiveOpen" @click.outside="archiveOpen = false" x-cloak class="absolute right-0 z-20 mt-1 w-44 rounded-lg border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-900">
                    @if (($customer['status'] ?? '') === 'archived')
                        <button type="button" wire:click="restoreCustomer" class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/5">Restore customer</button>
                    @else
                        <button type="button" wire:click="archiveCustomer" class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/5">Archive customer</button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <a href="#" wire:click.prevent="setTab('brands')" class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 hover:ring-brand-300 dark:bg-gray-800 dark:ring-gray-700">
            <p class="text-xs font-medium uppercase text-gray-400">Brands</p>
            <p class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ count($brands) }}</p>
        </a>
        <a href="{{ route('demo.assets') }}" wire:navigate class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 hover:ring-brand-300 dark:bg-gray-800 dark:ring-gray-700">
            <p class="text-xs font-medium uppercase text-gray-400">Digital assets</p>
            <p class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $digitalAssetsCount }}</p>
        </a>
        <a href="{{ route('demo.findings') }}" wire:navigate class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 hover:ring-brand-300 dark:bg-gray-800 dark:ring-gray-700">
            <p class="text-xs font-medium uppercase text-gray-400">Open findings</p>
            <p class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $openFindingsCount }}</p>
        </a>
        <a href="{{ route('demo.tasks') }}" wire:navigate class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 hover:ring-brand-300 dark:bg-gray-800 dark:ring-gray-700">
            <p class="text-xs font-medium uppercase text-gray-400">Open tasks</p>
            <p class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $openTasksCount }}</p>
        </a>
    </div>

    <div class="flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800">
        @foreach (['overview' => 'Overview', 'brands' => 'Brands', 'contacts' => 'Contacts', 'operations' => 'Operations', 'activity' => 'Activity'] as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')"
                @class([
                    'shrink-0 border-b-2 px-3 py-2.5 text-sm font-medium',
                    'border-brand-500 text-brand-600 dark:text-brand-400' => $tab === $key,
                    'border-transparent text-gray-500 hover:text-gray-800 dark:hover:text-white/90' => $tab !== $key,
                ])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'overview')
        <div class="grid gap-4 xl:grid-cols-3">
            <x-ta.card class="xl:col-span-2">
                <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Customer profile</h2>
                <dl class="mt-4 grid gap-3 sm:grid-cols-2 text-sm">
                    <div><dt class="text-gray-400">Customer name</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $customer['name'] }}</dd></div>
                    <div><dt class="text-gray-400">Legal name</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $customer['legal_name'] ?? '—' }}</dd></div>
                    <div><dt class="text-gray-400">Type</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $typeLabel }}</dd></div>
                    <div><dt class="text-gray-400">Status</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $statusLabel }}</dd></div>
                    <div><dt class="text-gray-400">Industry</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $industryLabel }}</dd></div>
                    <div><dt class="text-gray-400">HQ</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $hqDisplay }}</dd></div>
                    <div><dt class="text-gray-400">Service start</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $customer['service_started_at'] ?? '—' }}</dd></div>
                    <div class="sm:col-span-2">
                        <dt class="text-gray-400">Services received</dt>
                        <dd class="mt-1 flex flex-wrap gap-1.5">
                            @forelse ($serviceLabels as $label)
                                <span class="rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-200">{{ $label }}</span>
                            @empty
                                <span class="text-gray-500">—</span>
                            @endforelse
                        </dd>
                    </div>
                </dl>
            </x-ta.card>

            <div class="space-y-4">
                <x-ta.card>
                    <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Primary communication</h2>
                    <dl class="mt-3 space-y-2 text-sm">
                        <div>
                            <dt class="text-gray-400">Email</dt>
                            <dd class="font-medium text-gray-800 dark:text-white/90">
                                @if (!empty($customer['primary_email']))
                                    <a class="text-brand-600 hover:underline" href="mailto:{{ $customer['primary_email'] }}">{{ $customer['primary_email'] }}</a>
                                @else —
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-gray-400">Phone</dt>
                            <dd class="font-medium text-gray-800 dark:text-white/90">
                                @if (!empty($customer['primary_phone']))
                                    <a class="text-brand-600 hover:underline" href="tel:{{ $customer['primary_phone'] }}">{{ $customer['primary_phone'] }}</a>
                                @else —
                                @endif
                            </dd>
                        </div>
                    </dl>
                </x-ta.card>
                <x-ta.card>
                    <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Account Owner / Responsible users</h2>
                    <p class="mt-1 text-xs text-gray-500">Portfolio responsibility for Dashboard routing — separate from authorization.</p>
                    <ul class="mt-3 space-y-2">
                        @forelse ($responsibleUsers as $index => $user)
                            <li class="flex items-center gap-2 text-sm">
                                <span class="flex h-7 w-7 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">{{ $user['initials'] }}</span>
                                <span class="text-gray-800 dark:text-white/90">{{ $user['name'] }}</span>
                                @if ($index === 0)
                                    <x-ta.badge color="info" size="sm">Account Owner</x-ta.badge>
                                @endif
                            </li>
                        @empty
                            <li class="text-sm text-gray-500">No responsible team assigned.</li>
                        @endforelse
                    </ul>
                </x-ta.card>
            </div>
        </div>

        <x-ta.card>
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Needs attention</h2>
                <button type="button" wire:click="setTab('operations')" class="text-sm font-medium text-brand-600 hover:underline">View all operations</button>
            </div>
            @php
                $attentionItems = collect($overdueTasks)->map(fn ($t) => ['tone' => 'amber', 'title' => $t['title'] ?? 'Task', 'meta' => 'Overdue / high-priority task'])->take(2)
                    ->concat(collect($attentionFindings)->map(fn ($f) => ['tone' => 'rose', 'title' => $f['title'] ?? 'Finding', 'meta' => strtoupper($f['severity'] ?? '').' finding']))
                    ->take(5);
            @endphp
            @if ($attentionItems->isEmpty())
                <p class="mt-3 text-sm text-gray-500">Nothing requires immediate attention.</p>
            @else
                <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($attentionItems as $item)
                        <li class="flex items-start justify-between gap-3 py-3">
                            <div>
                                <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $item['title'] }}</p>
                                <p class="text-xs text-gray-500">{{ $item['meta'] }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ta.card>

        <x-ta.card>
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Brands</h2>
                <div class="flex gap-2">
                    <button type="button" wire:click="setTab('brands')" class="text-sm font-medium text-brand-600 hover:underline">View all brands</button>
                    <a href="{{ route('demo.brand.create', ['customerId' => $customer['id']]) }}" wire:navigate class="text-sm font-medium text-brand-600 hover:underline">Add brand</a>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase text-gray-400">
                            <th class="py-2 pr-3">Brand</th>
                            <th class="hidden py-2 pr-3 md:table-cell">Sector</th>
                            <th class="hidden py-2 pr-3 lg:table-cell">Market</th>
                            <th class="py-2 pr-3">Assets</th>
                            <th class="py-2 pr-3">Findings</th>
                            <th class="py-2 pr-3">Tasks</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($brands as $brand)
                            <tr>
                                <td class="py-3 pr-3 font-medium text-gray-800 dark:text-white/90">{{ $brand['name'] }}</td>
                                <td class="hidden py-3 pr-3 text-gray-500 md:table-cell">{{ \App\Support\Options\IndustryOptions::label($brand['sector'] ?? null) }}</td>
                                <td class="hidden py-3 pr-3 text-gray-500 lg:table-cell">{{ \App\Support\Options\CountryOptions::label($brand['primary_country'] ?? null) }}</td>
                                <td class="py-3 pr-3 text-gray-700 dark:text-gray-300">{{ $brand['assets_count'] ?? 0 }}</td>
                                <td class="py-3 pr-3 text-gray-700 dark:text-gray-300">{{ $brand['open_findings'] ?? 0 }}</td>
                                <td class="py-3 pr-3 text-gray-700 dark:text-gray-300">{{ $brand['open_tasks'] ?? 0 }}</td>
                                <td class="py-3 text-right">
                                    <a href="{{ route('demo.brand', ['brand' => $brand['id']]) }}" wire:navigate class="text-sm font-medium text-brand-600 hover:underline">Open</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ta.card>

        <x-ta.card>
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Recent activity</h2>
                <button type="button" wire:click="setTab('activity')" class="text-sm font-medium text-brand-600 hover:underline">View activity</button>
            </div>
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse (array_slice($activity, 0, 6) as $item)
                    <li class="flex items-center justify-between gap-3 py-2.5 text-sm">
                        <span class="text-gray-800 dark:text-white/90">{{ $item['title'] }}</span>
                        <span class="shrink-0 text-xs text-gray-400">{{ $item['when'] }}</span>
                    </li>
                @empty
                    <li class="py-3 text-sm text-gray-500">No recent activity for this customer.</li>
                @endforelse
            </ul>
        </x-ta.card>
    @endif

    @if ($tab === 'brands')
        <x-ta.card>
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Brands</h2>
                    <p class="text-sm text-gray-500">Brands managed under this customer.</p>
                </div>
                <a href="{{ route('demo.brand.create', ['customerId' => $customer['id']]) }}" wire:navigate class="inline-flex rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white hover:bg-brand-600">Add brand</a>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase text-gray-400">
                            <th class="py-2 pr-3">Brand</th>
                            <th class="hidden py-2 pr-3 md:table-cell">Sector</th>
                            <th class="hidden py-2 pr-3 lg:table-cell">Primary market</th>
                            <th class="hidden py-2 pr-3 xl:table-cell">Target markets</th>
                            <th class="hidden py-2 pr-3 xl:table-cell">Languages</th>
                            <th class="py-2 pr-3">Assets</th>
                            <th class="py-2 pr-3">Findings</th>
                            <th class="py-2 pr-3">Tasks</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($brands as $brand)
                            <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="py-3 pr-3 font-medium text-gray-800 dark:text-white/90">{{ $brand['name'] }}</td>
                                <td class="hidden py-3 pr-3 text-gray-500 md:table-cell">{{ \App\Support\Options\IndustryOptions::label($brand['sector'] ?? null) }}</td>
                                <td class="hidden py-3 pr-3 text-gray-500 lg:table-cell">{{ \App\Support\Options\CountryOptions::label($brand['primary_country'] ?? null) }}</td>
                                <td class="hidden py-3 pr-3 text-gray-500 xl:table-cell">{{ collect($brand['target_markets'] ?? [])->map(fn ($c) => \App\Support\Options\CountryOptions::label($c))->implode(', ') ?: '—' }}</td>
                                <td class="hidden py-3 pr-3 text-gray-500 xl:table-cell">{{ collect($brand['languages'] ?? [])->map(fn ($c) => \App\Support\Options\LanguageOptions::label($c))->implode(', ') ?: '—' }}</td>
                                <td class="py-3 pr-3">{{ $brand['assets_count'] ?? 0 }}</td>
                                <td class="py-3 pr-3">{{ $brand['open_findings'] ?? 0 }}</td>
                                <td class="py-3 pr-3">{{ $brand['open_tasks'] ?? 0 }}</td>
                                <td class="py-3 text-right"><a href="{{ route('demo.brand', ['brand' => $brand['id']]) }}" wire:navigate class="font-medium text-brand-600 hover:underline">Open</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ta.card>
    @endif

    @if ($tab === 'contacts')
        <x-ta.card>
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Contacts</h2>
                    <p class="text-sm text-gray-500">People at the customer organization your team works with.</p>
                </div>
                <button type="button" wire:click="openContactForm" class="inline-flex rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white hover:bg-brand-600">Add contact</button>
            </div>
            @if (count($contacts) === 0)
                @include('livewire.demo.partials.empty-panel', [
                    'title' => 'No contacts yet',
                    'message' => 'Add the people your team works with at this customer.',
                ])
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase text-gray-400">
                                <th class="py-2 pr-3">Name</th>
                                <th class="py-2 pr-3">Role / title</th>
                                <th class="hidden py-2 pr-3 md:table-cell">Email</th>
                                <th class="hidden py-2 pr-3 lg:table-cell">Phone</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($contacts as $contact)
                                <tr>
                                    <td class="py-3 pr-3 font-medium text-gray-800 dark:text-white/90">{{ $contact['name'] }}</td>
                                    <td class="py-3 pr-3 text-gray-500">{{ $contact['title'] ?? '—' }}</td>
                                    <td class="hidden py-3 pr-3 text-gray-500 md:table-cell">{{ $contact['email'] ?? '—' }}</td>
                                    <td class="hidden py-3 pr-3 text-gray-500 lg:table-cell">{{ $contact['phone'] ?? '—' }}</td>
                                    <td class="py-3 text-right space-x-2">
                                        <button type="button" wire:click="openContactForm('{{ $contact['id'] }}')" class="text-sm font-medium text-brand-600 hover:underline">Edit</button>
                                        <button type="button" wire:click="deleteContact('{{ $contact['id'] }}')" wire:confirm="Remove this contact?" class="text-sm font-medium text-error-500 hover:underline">Remove</button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ta.card>
    @endif

    @if ($tab === 'operations')
        <div class="grid gap-4 lg:grid-cols-3">
            <x-ta.card>
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Findings</h2>
                    <a href="{{ route('demo.findings') }}" wire:navigate class="text-sm text-brand-600 hover:underline">View all findings</a>
                </div>
                <ul class="space-y-2 text-sm">
                    @forelse (array_slice($findings, 0, 5) as $finding)
                        <li class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">
                            <p class="font-medium text-gray-800 dark:text-white/90">{{ $finding['title'] ?? 'Finding' }}</p>
                            <p class="text-xs text-gray-500">{{ strtoupper($finding['severity'] ?? '') }} · {{ $finding['asset'] ?? '' }}</p>
                        </li>
                    @empty
                        <li class="text-gray-500">No open findings.</li>
                    @endforelse
                </ul>
            </x-ta.card>
            <x-ta.card>
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Recommendations</h2>
                    <a href="{{ route('demo.recommendations') }}" wire:navigate class="text-sm text-brand-600 hover:underline">View all</a>
                </div>
                <ul class="space-y-2 text-sm">
                    @forelse (array_slice($recommendations, 0, 5) as $rec)
                        <li class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">
                            <p class="font-medium text-gray-800 dark:text-white/90">{{ $rec['title'] }}</p>
                            <p class="text-xs text-gray-500">{{ ucfirst($rec['status'] ?? '') }}</p>
                        </li>
                    @empty
                        <li class="text-gray-500">No recommendations.</li>
                    @endforelse
                </ul>
            </x-ta.card>
            <x-ta.card>
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Tasks</h2>
                    <a href="{{ route('demo.tasks') }}" wire:navigate class="text-sm text-brand-600 hover:underline">View all tasks</a>
                </div>
                <ul class="space-y-2 text-sm">
                    @forelse (array_slice($openTasks, 0, 5) as $task)
                        <li class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">
                            <p class="font-medium text-gray-800 dark:text-white/90">{{ $task['title'] }}</p>
                            <p class="text-xs text-gray-500">{{ ucfirst($task['status'] ?? '') }} · {{ $task['due'] ?? '' }}</p>
                        </li>
                    @empty
                        <li class="text-gray-500">No open tasks.</li>
                    @endforelse
                </ul>
            </x-ta.card>
        </div>
    @endif

    @if ($tab === 'activity')
        <x-ta.card>
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Activity</h2>
                <select wire:model.live="activityFilter" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900" aria-label="Activity filter">
                    <option value="all">All</option>
                    <option value="portfolio">Portfolio</option>
                    <option value="findings">Findings</option>
                    <option value="recommendations">Recommendations</option>
                    <option value="tasks">Tasks</option>
                    <option value="connections">Connections</option>
                </select>
            </div>
            @if (count($activity) === 0)
                @include('livewire.demo.partials.empty-panel', [
                    'title' => 'No activity yet',
                    'message' => 'Customer-scoped events will appear here as work happens.',
                ])
            @else
                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($activity as $item)
                        <li class="flex items-center justify-between gap-3 py-3 text-sm">
                            <div>
                                <p class="font-medium text-gray-800 dark:text-white/90">{{ $item['title'] }}</p>
                                <p class="text-xs uppercase tracking-wide text-gray-400">{{ $item['category'] ?? '' }}</p>
                            </div>
                            <span class="text-xs text-gray-400">{{ $item['when'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ta.card>
    @endif

    <div x-data="{ open: @entangle('showContactForm') }">
        <x-ta.modal>
            <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ $editingContactId ? 'Edit contact' : 'Add contact' }}</h3>
            <div class="mt-4 space-y-3">
                <x-ta.form.field label="Name" :required="true" :error="$errors->first('contact_name')">
                    <input wire:model="contact_name" type="text" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                </x-ta.form.field>
                <x-ta.form.field label="Role / title" :error="$errors->first('contact_role')">
                    <x-ta.form.select wire:model.live="contact_role" :options="$roleOptions" placeholder="Select role…" />
                </x-ta.form.field>
                @if ($contact_role === 'other')
                    <x-ta.form.field label="Custom title" :error="$errors->first('contact_title_custom')">
                        <input wire:model="contact_title_custom" type="text" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm dark:border-gray-700 dark:bg-gray-900" />
                    </x-ta.form.field>
                @endif
                <x-ta.form.field label="Email" :error="$errors->first('contact_email')">
                    <input wire:model="contact_email" type="email" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm dark:border-gray-700 dark:bg-gray-900" />
                </x-ta.form.field>
                <x-ta.form.field label="Phone" :error="$errors->first('contact_phone')">
                    <input wire:model="contact_phone" type="tel" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm dark:border-gray-700 dark:bg-gray-900" />
                </x-ta.form.field>
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" wire:click="closeContactForm" class="rounded-lg px-3 py-2 text-sm ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Cancel</button>
                <button type="button" wire:click="saveContact" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white hover:bg-brand-600">Save contact</button>
            </div>
        </x-ta.modal>
    </div>
</div>
