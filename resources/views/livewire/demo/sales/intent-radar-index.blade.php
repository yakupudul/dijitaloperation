<div class="space-y-5" wire:poll.15s>
    @include('livewire.demo.sales.partials.sales-subnav', ['current' => 'intent-radar'])
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('free_radar.title') }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ __('free_radar.subtitle') }}</p>
        </div>
        <span class="rounded-full bg-emerald-50 px-3 py-1 text-sm text-emerald-800">{{ __('free_radar.no_fees') }}</span>
    </div>
    @if ($message)<div role="status" class="rounded-lg bg-blue-50 p-3 text-sm text-blue-800">{{ $message }}</div>@endif
    @if ($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-800">{{ $errors->first() }}</div>@endif

    <x-ta.card>
        <h2 class="font-semibold">{{ __('free_radar.services') }}</h2>
        <form wire:submit="start" class="mt-3 space-y-3">
            <input wire:model.live.debounce.400ms="serviceSearch" placeholder="{{ __('free_radar.search_services') }}" aria-label="{{ __('free_radar.search_services') }}" class="w-full rounded-lg border-gray-300 dark:bg-gray-900" />
            <div class="grid max-h-48 gap-2 overflow-y-auto sm:grid-cols-2 lg:grid-cols-3">
                @forelse ($services as $service)
                    @if ($service->primaryName)
                        <label class="flex items-center gap-2 rounded-lg border border-gray-100 p-2 text-sm dark:border-gray-700" wire:key="service-{{ $service->id }}">
                            <input type="checkbox" wire:model="serviceIds" value="{{ $service->id }}" />
                            {{ $service->primaryName->raw_label }}
                        </label>
                    @endif
                @empty
                    <p class="text-sm text-gray-500">{{ __('free_radar.no_services') }}</p>
                @endforelse
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="text-sm">{{ __('free_radar.market') }}
                    <input wire:model="market" maxlength="150" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-900" placeholder="{{ __('free_radar.market_example') }}" />
                </label>
                <label class="text-sm">{{ __('free_radar.frequency') }}
                    <select wire:model="interval" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-900">
                        <option value="60">{{ __('free_radar.hourly') }}</option><option value="1440">{{ __('free_radar.daily') }}</option>
                    </select>
                </label>
            </div>
            <details>
                <summary class="cursor-pointer text-sm text-gray-500">{{ __('free_radar.matching_options') }}</summary>
                <div class="mt-2 grid gap-3 sm:grid-cols-2">
                    <label class="text-sm">{{ __('free_radar.extra_terms') }}<textarea wire:model="extraTerms" maxlength="2000" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-900"></textarea></label>
                    <label class="text-sm">{{ __('free_radar.exclude_terms') }}<textarea wire:model="excludedTerms" maxlength="2000" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-900"></textarea></label>
                </div>
            </details>
            <p class="text-xs text-gray-500">{{ __('free_radar.market_note') }}</p>
            <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">{{ __('free_radar.start') }}</button>
        </form>
    </x-ta.card>

    @if ($profiles->isNotEmpty())
        <div class="flex flex-wrap gap-3">
            @foreach ($profiles as $profile)
                <div wire:key="profile-{{ $profile->id }}" class="rounded-xl border border-gray-200 bg-white p-3 text-sm dark:border-gray-700 dark:bg-gray-800">
                    <a href="{{ route('operator.search-profile', ['profileId' => $profile->id]) }}" class="font-medium hover:underline">{{ $profile->name }}</a>
                    <p class="mt-1 text-xs text-gray-500">{{ $profile->active && $profile->free_radar_enabled ? __('free_radar.active') : __('free_radar.paused') }}
                        · {{ __('free_radar.next') }}: {{ $profile->radar_next_at?->diffForHumans() ?? '—' }}</p>
                    <div class="mt-2 flex gap-3">
                        <button wire:click="toggleProfile({{ $profile->id }})" class="text-brand-600">{{ $profile->free_radar_enabled ? __('free_radar.pause') : __('free_radar.resume') }}</button>
                        <button wire:click="refreshProfile({{ $profile->id }})" wire:loading.attr="disabled" class="text-brand-600">{{ __('free_radar.check') }}</button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="flex flex-wrap gap-3">
        <input wire:model.live.debounce.400ms="q" aria-label="{{ __('free_radar.search') }}" placeholder="{{ __('free_radar.search') }}" class="rounded-lg border-gray-300 dark:bg-gray-900" />
        <select wire:model.live="status" aria-label="{{ __('free_radar.status') }}" class="rounded-lg border-gray-300 dark:bg-gray-900">
            <option value="">{{ __('free_radar.all') }}</option>
            @foreach (['new', 'reviewed', 'dismissed', 'converted_to_prospect'] as $value)<option value="{{ $value }}">{{ __('free_radar.status_'.$value) }}</option>@endforeach
        </select>
        <select wire:model.live="profileFilter" aria-label="{{ __('free_radar.services') }}" class="rounded-lg border-gray-300 dark:bg-gray-900">
            <option value="">{{ __('free_radar.all_services') }}</option>
            @foreach ($profiles as $profile)<option value="{{ $profile->id }}">{{ $profile->name }}</option>@endforeach
        </select>
        <select wire:model.live="stage" aria-label="{{ __('free_radar.intent') }}" class="rounded-lg border-gray-300 dark:bg-gray-900">
            <option value="">{{ __('free_radar.all_intents') }}</option>
            <option value="high_intent">{{ __('free_radar.high_intent') }}</option>
            <option value="unknown">{{ __('free_radar.needs_review') }}</option>
        </select>
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
        <table class="min-w-full text-left text-sm">
            <thead class="bg-gray-50 text-gray-500 dark:bg-gray-900"><tr>
                <th class="p-4">{{ __('free_radar.demand') }}</th><th class="p-4">{{ __('free_radar.date') }}</th>
                <th class="p-4">{{ __('free_radar.intent') }}</th><th class="p-4">{{ __('free_radar.actions') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($signals as $signal)
                <tr wire:key="signal-{{ $signal->id }}" class="border-t border-gray-100 dark:border-gray-700">
                    <td class="max-w-lg p-4">
                        <a href="{{ route('operator.intent-signal', ['signalId' => $signal->id]) }}" class="font-semibold text-brand-600">{{ $signal->source_title }}</a>
                        <p class="mt-1 text-xs text-gray-500">{{ $signal->searchProfile?->name }} · {{ parse_url($signal->source_url, PHP_URL_HOST) }}</p>
                        @if ($signal->source_type !== 'public_source')<p class="text-xs text-gray-500">{{ __('free_radar.historical') }}</p>@endif
                        <p class="mt-2 text-gray-600 dark:text-gray-300">{{ \Illuminate\Support\Str::limit($signal->fetched_source_excerpt ?: $signal->observed_snippet, 200) }}</p>
                        <a href="{{ $signal->source_url }}" target="_blank" rel="noopener noreferrer" class="mt-2 inline-block text-xs text-brand-600">{{ __('free_radar.open_source') }} ↗</a>
                    </td>
                    <td class="whitespace-nowrap p-4 text-xs">
                        {{ $signal->published_at?->format('d.m.Y') ?? __('free_radar.date_unknown') }}
                        <p class="mt-1 text-gray-500">{{ __('free_radar.found') }}: {{ $signal->discovered_at?->diffForHumans() }}</p>
                    </td>
                    <td class="max-w-xs p-4">
                        <p class="font-medium">{{ $signal->purchase_stage?->value === 'high_intent' ? __('free_radar.high_intent') : __('free_radar.needs_review') }}</p>
                        <p class="mt-1 text-xs text-gray-500">{{ $signal->classification_reason }}</p>
                        <p class="mt-2 text-xs">{{ __('free_radar.status_'.$signal->status->value) }}</p>
                    </td>
                    <td class="p-4">
                        @if ($signal->prospect_id)
                            <a href="{{ route('operator.prospect', ['prospectId' => $signal->prospect_id]) }}" class="text-brand-600">{{ __('free_radar.prospect') }}</a>
                        @else
                            <div class="flex flex-col items-start gap-2">
                                <button wire:click="mark({{ $signal->id }}, 'reviewed')" class="text-brand-600">{{ __('free_radar.follow') }}</button>
                                @if ($signal->status->value === 'dismissed')
                                    <button wire:click="mark({{ $signal->id }}, 'new')" class="text-brand-600">{{ __('free_radar.restore') }}</button>
                                @else
                                    <button wire:click="mark({{ $signal->id }}, 'dismissed')" class="text-gray-500">{{ __('free_radar.dismiss') }}</button>
                                @endif
                                <a href="{{ route('operator.intent-signal', ['signalId' => $signal->id]) }}" class="text-brand-600">{{ __('free_radar.inspect') }}</a>
                            </div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="p-8 text-center text-gray-500">{{ __('free_radar.empty') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $signals->links() }}

    <details class="rounded-xl border border-gray-200 p-4 dark:border-gray-700" open>
        <summary class="cursor-pointer font-semibold">{{ __('free_radar.sources') }}</summary>
        <p class="mt-2 text-xs text-gray-500">{{ __('free_radar.coverage') }}</p>
        <div class="mt-3 space-y-3">
            @foreach ($sources as $source)
                <div wire:key="source-{{ $source->id }}" class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 pb-2 text-sm dark:border-gray-700">
                    <div>
                        <a href="{{ $source->url }}" target="_blank" rel="noopener noreferrer" class="text-brand-600">{{ $source->name }}</a>
                        <p class="text-xs text-gray-500">{{ __('free_radar.source_'.$source->state) }} · {{ __('free_radar.last_check') }}: {{ $source->checked_at?->diffForHumans() ?? '—' }} · {{ __('free_radar.candidates') }}: {{ $source->item_count }}</p>
                        @if ($source->error)<p class="text-xs text-amber-700">{{ __('free_radar.source_problem') }} ({{ $source->error }})</p>@endif
                    </div>
                    <button wire:click="toggleSource({{ $source->id }})" class="text-brand-600">{{ $source->enabled ? __('free_radar.pause') : __('free_radar.resume') }}</button>
                </div>
            @endforeach
        </div>
        <details class="mt-4">
            <summary class="cursor-pointer text-sm text-gray-500">{{ __('free_radar.add_source') }}</summary>
            <form wire:submit="addSource" class="mt-3 flex flex-wrap gap-2">
                <input wire:model="sourceName" required placeholder="{{ __('free_radar.source_name') }}" aria-label="{{ __('free_radar.source_name') }}" class="rounded-lg border-gray-300 dark:bg-gray-900" />
                <input wire:model="sourceUrl" type="url" required placeholder="https://" aria-label="{{ __('free_radar.source_url') }}" class="min-w-64 rounded-lg border-gray-300 dark:bg-gray-900" />
                <select wire:model="sourceFormat" aria-label="{{ __('free_radar.format') }}" class="rounded-lg border-gray-300 dark:bg-gray-900"><option value="html">{{ __('free_radar.list_page') }}</option><option value="rss">RSS / Atom</option></select>
                <button type="submit" class="rounded-lg bg-brand-500 px-3 py-2 text-white">{{ __('free_radar.add') }}</button>
            </form>
        </details>
    </details>
    <x-ta.card>
        <h2 class="font-semibold">{{ __('free_radar.recent_runs') }}</h2>
        @forelse ($runs as $run)
            <p class="mt-2 text-sm">{{ $run->searchProfile?->name }} · {{ __('free_radar.run_'.$run->status->value) }} · {{ $run->signal_count }} {{ __('free_radar.new_demands') }} · {{ $run->created_at?->diffForHumans() }}</p>
        @empty
            <p class="mt-2 text-sm text-gray-500">{{ __('free_radar.no_runs') }}</p>
        @endforelse
        <p class="mt-3 text-xs text-gray-500">{{ __('free_radar.worker_note') }}</p>
    </x-ta.card>
</div>
