<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => 'Portfolio',
        'title' => 'Digital Assets',
        'subtitle' => 'Connected, detected, and infrastructure assets — filter by role and health.',
        'actions' => '<button type="button" wire:click="openWizard" class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">Add asset</button>',
    ])

    <div class="flex flex-wrap items-end gap-3 rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500">Brand</label>
            <select wire:model.live="filterBrand" class="rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700">
                <option value="">All brands</option>
                @foreach ($brandOptions as $id => $label)
                    <option value="{{ $id }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500">Type</label>
            <select wire:model.live="filterType" class="rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700">
                <option value="">All types</option>
                @foreach ($typeOptions as $id => $label)
                    <option value="{{ $id }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500">Health</label>
            <select wire:model.live="filterHealth" class="rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700">
                <option value="">All health</option>
                @foreach ($healthOptions as $id => $label)
                    <option value="{{ $id }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500">Role</label>
            <select wire:model.live="filterRole" class="rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700">
                <option value="">All roles</option>
                @foreach ($roleOptions as $id => $label)
                    <option value="{{ $id }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <x-ta.button wire:click="clearFilters" size="sm" variant="outline">Clear</x-ta.button>
            <div class="flex rounded-lg ring-1 ring-inset ring-gray-200 dark:ring-gray-700">
                <button type="button" wire:click="setViewMode('cards')"
                    @class([
                        'rounded-l-lg px-3 py-2 text-xs font-medium',
                        'bg-brand-500 text-white' => $viewMode === 'cards',
                        'text-gray-600 dark:text-gray-300' => $viewMode !== 'cards',
                    ])>Cards</button>
                <button type="button" wire:click="setViewMode('table')"
                    @class([
                        'rounded-r-lg px-3 py-2 text-xs font-medium',
                        'bg-brand-500 text-white' => $viewMode === 'table',
                        'text-gray-600 dark:text-gray-300' => $viewMode !== 'table',
                    ])>Table</button>
            </div>
        </div>
    </div>

    @if (count($assets) === 0)
        @include('livewire.demo.partials.empty-panel', [
            'title' => 'No assets match',
            'message' => 'Adjust Brand / Type / Health / Role filters to see assets.',
        ])
    @elseif ($viewMode === 'cards')
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($assets as $asset)
                <x-ta.card>
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <p class="text-xs text-gray-400">{{ $asset['role_label'] ?? 'Asset' }}</p>
                            <h3 class="mt-1 font-semibold text-gray-800 dark:text-white/90">{{ $asset['name'] }}</h3>
                            <p class="text-sm text-gray-500">{{ $asset['type_label'] }}</p>
                        </div>
                        <x-ta.badge :color="match($asset['health'] ?? '') { 'healthy' => 'success', 'needs_attention' => 'warning', 'warning' => 'warning', default => 'info' }" size="sm">
                            {{ $asset['health_label'] }}
                        </x-ta.badge>
                    </div>

                    <div class="mt-4 rounded-lg bg-gray-50 px-3 py-3 dark:bg-white/[0.02]">
                        <p class="text-xs text-gray-400">{{ $asset['primary_metric_label'] ?? 'Primary metric' }}</p>
                        <p class="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">{{ $asset['primary_metric'] ?? '—' }}</p>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                        @include('livewire.demo.partials.provenance-badge', ['label' => $asset['provenance'] ?? 'Detected'])
                        <span>{{ $asset['open_findings'] ?? 0 }} findings</span>
                        <span>·</span>
                        <span>{{ $asset['last_update'] ?? '—' }}</span>
                    </div>

                    <div class="mt-4">
                        <x-ta.button :href="route($asset['route'])" size="sm">Open workspace</x-ta.button>
                    </div>
                </x-ta.card>
            @endforeach
        </div>
    @else
        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Name</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Type</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Role</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Primary metric</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Health</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Findings</th>
                <th class="px-5 py-3"></th>
            </x-slot:head>
            @foreach ($assets as $asset)
                <tr>
                    <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $asset['name'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $asset['type_label'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $asset['role_label'] ?? '—' }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">
                        <span class="font-medium text-gray-800 dark:text-white/90">{{ $asset['primary_metric'] ?? '—' }}</span>
                        <span class="block text-xs text-gray-400">{{ $asset['primary_metric_label'] ?? '' }}</span>
                    </td>
                    <td class="px-5 py-4">
                        <x-ta.badge :color="match($asset['health'] ?? '') { 'healthy' => 'success', 'needs_attention' => 'warning', 'warning' => 'warning', default => 'info' }" size="sm">
                            {{ $asset['health_label'] }}
                        </x-ta.badge>
                    </td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $asset['open_findings'] ?? 0 }}</td>
                    <td class="px-5 py-4 text-right">
                        <x-ta.button :href="route($asset['route'])" size="sm" variant="outline">Open</x-ta.button>
                    </td>
                </tr>
            @endforeach
        </x-ta.table>
    @endif

    <div x-data="{ open: @entangle('showWizard') }">
        <x-ta.modal>
            <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">Add asset wizard</h3>
            <p class="mt-1 text-sm text-gray-500">Demo Mode · step {{ $step }} of 3 · no provider API calls</p>

            <div class="mt-4">
                <x-ta.progress-bar :value="$step" :max="3" label="Wizard progress" />
            </div>

            @if ($step === 1)
                <div class="mt-4 space-y-2">
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Choose asset type</p>
                    @foreach ($wizardTypes as $key => $label)
                        <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-gray-200 px-4 py-3 dark:border-gray-700">
                            <input type="radio" wire:model="assetType" value="{{ $key }}" />
                            <span class="text-sm text-gray-800 dark:text-white/90">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            @elseif ($step === 2)
                <div class="mt-4 space-y-3">
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Asset name / URL</label>
                        <input wire:model="assetName" type="text" class="w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700" />
                        @error('assetName') <p class="mt-1 text-xs text-error-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Connection mode</label>
                        <select wire:model="connectionMode" class="w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700">
                            <option value="public">Public / Detected</option>
                            <option value="connected">Connected provider (simulated)</option>
                            <option value="manual">Manual</option>
                        </select>
                    </div>
                </div>
            @else
                <div class="mt-4 rounded-xl bg-gray-50 p-4 text-sm dark:bg-white/[0.02]">
                    <p><span class="text-gray-400">Type:</span> {{ $wizardTypes[$assetType] ?? $assetType }}</p>
                    <p class="mt-1"><span class="text-gray-400">Name:</span> {{ $assetName }}</p>
                    <p class="mt-1"><span class="text-gray-400">Connection:</span> {{ $connectionMode }}</p>
                    <p class="mt-3 text-xs text-gray-400">Confirming stores nothing in the operator DB — Demo Mode session flash only.</p>
                </div>
            @endif

            <div class="mt-6 flex justify-between gap-2">
                <div>
                    @if ($step > 1)
                        <x-ta.button wire:click="prevStep" variant="outline" size="sm">Back</x-ta.button>
                    @else
                        <x-ta.button wire:click="closeWizard" variant="outline" size="sm">Cancel</x-ta.button>
                    @endif
                </div>
                <x-ta.button wire:click="nextStep" size="sm">{{ $step === 3 ? 'Confirm add' : 'Continue' }}</x-ta.button>
            </div>
        </x-ta.modal>
    </div>
</div>
