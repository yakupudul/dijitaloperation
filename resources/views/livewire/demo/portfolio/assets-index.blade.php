@php
    $types = [
        'website' => 'Website',
        'meta_ads' => 'Meta Ads',
        'google_ads' => 'Google Ads',
        'gbp' => 'Google Business Profile',
        'ga4' => 'Google Analytics',
        'gsc' => 'Search Console',
    ];
@endphp

<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">Digital Assets</h1>
            <p class="mt-1 text-sm text-gray-500">Connected and detected assets for Atlas Dental Ankara.</p>
        </div>
        <x-ta.button wire:click="openWizard" size="sm">Add asset</x-ta.button>
    </div>

    <x-ta.table>
        <x-slot:head>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Name</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Type</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Connection</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Health</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Findings</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Updated</th>
            <th class="px-5 py-3"></th>
        </x-slot:head>
        @foreach ($assets as $asset)
            <tr>
                <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $asset['name'] }}</td>
                <td class="px-5 py-4 text-sm text-gray-500">{{ $asset['type_label'] }}</td>
                <td class="px-5 py-4 text-sm text-gray-500">{{ $asset['provenance'] }}</td>
                <td class="px-5 py-4">
                    <x-ta.badge :color="match($asset['health'] ?? '') { 'healthy' => 'success', 'needs_attention' => 'warning', 'warning' => 'warning', default => 'info' }" size="sm">
                        {{ $asset['health_label'] }}
                    </x-ta.badge>
                </td>
                <td class="px-5 py-4 text-sm text-gray-500">{{ $asset['open_findings'] ?? 0 }}</td>
                <td class="px-5 py-4 text-sm text-gray-500">{{ $asset['last_update'] ?? '—' }}</td>
                <td class="px-5 py-4 text-right">
                    <x-ta.button :href="route($asset['route'])" size="sm" variant="outline">Open</x-ta.button>
                </td>
            </tr>
        @endforeach
    </x-ta.table>

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
                    @foreach ($types as $key => $label)
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
                    <p><span class="text-gray-400">Type:</span> {{ $types[$assetType] ?? $assetType }}</p>
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
