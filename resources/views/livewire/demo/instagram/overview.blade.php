@php
    $identity = $workspace['identity'];
    $navTabs = [
        'overview' => __('operator.instagram.tabs.overview'),
        'profile' => __('operator.instagram.tabs.profile'),
        'operations' => __('operator.instagram.tabs.operations'),
        'setup' => __('operator.instagram.tabs.setup'),
    ];
@endphp

<div class="space-y-5">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <x-demo.digital-asset-mark type="instagram" size="lg" />
            <div>
                <p class="text-xs uppercase tracking-wide text-gray-400">{{ $identity['eyebrow'] }}</p>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $identity['title'] }}</h1>
                <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                    <span class="font-medium text-gray-700 dark:text-gray-300">{{ $identity['handle'] }}</span>
                    <span>·</span>
                    <span>{{ $identity['connection'] }}</span>
                    <span>·</span>
                    <span>{{ $identity['freshness'] }}</span>
                </div>
                <p class="mt-2 text-xs text-gray-400">{{ $workspace['demo_boundary'] }}</p>
                @include('livewire.demo.partials._asset-scope-chip', ['assetType' => 'instagram'])
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('operator.activity', ['asset' => $identity['asset_id']]) }}" wire:navigate class="inline-flex rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">{{ __('operator.customer.actions.view_activity') }}</a>
            <a href="{{ route('operator.brand', ['brand' => $identity['brand_id']]) }}" wire:navigate class="inline-flex rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">{{ $identity['brand_name'] }}</a>
            <a href="{{ route('operator.assets') }}" wire:navigate class="inline-flex rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">{{ __('operator.nav.digital_assets') }}</a>
        </div>
    </div>

    <nav class="flex flex-wrap gap-1 border-b border-gray-200 dark:border-gray-800" aria-label="Instagram sections">
        @foreach ($navTabs as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')"
                @class([
                    'rounded-t-lg px-3 py-2 text-sm font-medium',
                    'border-b-2 border-brand-500 text-brand-700 dark:text-brand-400' => $tab === $key,
                    'text-gray-500 hover:text-gray-800 dark:hover:text-white/90' => $tab !== $key,
                ])>{{ $label }}</button>
        @endforeach
    </nav>

    @if ($tab === 'overview')
        <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
            @foreach ($workspace['overview']['glance'] as $card)
                <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <p class="text-xs text-gray-400">{{ $card['label'] }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $card['value'] }}</p>
                    <p class="mt-1 text-xs text-gray-500">{{ $card['hint'] }}</p>
                </div>
            @endforeach
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Needs attention</h2>
                <ul class="mt-3 space-y-3">
                    @foreach ($workspace['overview']['needs_attention'] as $item)
                        <li>
                            <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $item['title'] }}</p>
                            <p class="text-xs text-gray-500">{{ $item['summary'] }}</p>
                        </li>
                    @endforeach
                </ul>
            </div>
            <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Content mix</h2>
                <ul class="mt-3 space-y-2">
                    @foreach ($workspace['overview']['content_mix'] as $mix)
                        <li class="flex items-center justify-between text-sm">
                            <span class="text-gray-600 dark:text-gray-300">{{ $mix['label'] }}</span>
                            <span class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $mix['share'] }}%</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <th class="px-4 py-3 text-left text-xs uppercase text-gray-400">Recent posts</th>
                        <th class="px-4 py-3 text-left text-xs uppercase text-gray-400">Type</th>
                        <th class="px-4 py-3 text-left text-xs uppercase text-gray-400">Published</th>
                        <th class="px-4 py-3 text-left text-xs uppercase text-gray-400">Reach</th>
                        <th class="px-4 py-3 text-left text-xs uppercase text-gray-400">Engagement</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($workspace['overview']['recent_posts'] as $post)
                        <tr class="border-b border-gray-50 dark:border-gray-800/60">
                            <td class="px-4 py-3 font-medium text-gray-800 dark:text-white/90">{{ $post['title'] }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ $post['type'] }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ $post['published'] }}</td>
                            <td class="px-4 py-3 tabular-nums">{{ $post['reach'] }}</td>
                            <td class="px-4 py-3 tabular-nums">{{ $post['engagement'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @php $rel = $workspace['relationships']; @endphp
        <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.asset.relationship_summary') }}</h2>
            <p class="mt-1 text-xs text-gray-500">Linked Digital Assets and cross-channel checks for this profile.</p>
            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400">Linked assets</h3>
                    <ul class="mt-3 space-y-3">
                        @foreach ($rel['linked_assets'] as $asset)
                            <li class="flex items-start justify-between gap-3 text-sm">
                                <div>
                                    <p class="font-medium text-gray-800 dark:text-white/90">{{ $asset['name'] }}</p>
                                    <p class="text-xs text-gray-500">{{ $asset['label'] }} · {{ $asset['note'] }}</p>
                                </div>
                                <a href="{{ $asset['url'] ?? \App\Services\Operator\OperatorPortfolioPresenter::specialistHref($asset) }}" wire:navigate class="text-xs font-medium text-brand-600 dark:text-brand-400">{{ __('operator.actions.open') }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400">Cross-asset checks</h3>
                    <ul class="mt-3 space-y-3">
                        @foreach ($rel['cross_checks'] as $check)
                            <li>
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $check['check'] }}</p>
                                    <x-ta.badge :color="$check['state'] === 'needs_attention' ? 'warning' : 'info'" size="sm">{{ $check['state'] }}</x-ta.badge>
                                </div>
                                <p class="text-xs text-gray-500">{{ $check['summary'] }}</p>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    @endif

    @if ($tab === 'profile')
        @php $profile = $workspace['profile']; @endphp
        <div class="grid gap-4 lg:grid-cols-2">
            <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800 space-y-3 text-sm">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Public profile</h2>
                <p><span class="text-gray-400">Display name · </span><span class="text-gray-800 dark:text-white/90">{{ $profile['display_name'] }}</span></p>
                <p><span class="text-gray-400">Username · </span><span class="text-gray-800 dark:text-white/90">{{ '@'.$profile['username'] }}</span></p>
                <p><span class="text-gray-400">Category · </span><span class="text-gray-800 dark:text-white/90">{{ $profile['category'] }}</span></p>
                <p><span class="text-gray-400">Website · </span><span class="text-gray-800 dark:text-white/90">{{ $profile['website'] }}</span></p>
                <pre class="whitespace-pre-wrap rounded-lg bg-gray-50 p-3 text-xs text-gray-700 dark:bg-white/[0.03] dark:text-gray-300">{{ $profile['bio'] }}</pre>
            </div>
            <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Coverage</h2>
                <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($profile['coverage'] as $row)
                        <li class="flex items-center justify-between py-2 text-sm">
                            <span class="text-gray-600 dark:text-gray-300">{{ $row['field'] }}</span>
                            <x-ta.badge :color="$row['state'] === 'complete' ? 'success' : 'warning'" size="sm">{{ $row['state'] }}</x-ta.badge>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-4 text-xs text-gray-500">{{ $profile['consistency']['website_note'] }}</p>
            </div>
        </div>
    @endif

    @if ($tab === 'operations')
        <div class="space-y-6">
            <section>
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Findings</h2>
                <ul class="mt-3 space-y-3">
                    @foreach ($workspace['findings'] as $finding)
                        <li class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-ta.badge :color="match($finding['severity']) { 'warning' => 'warning', 'success' => 'success', default => 'info' }" size="sm">{{ $finding['severity'] }}</x-ta.badge>
                                <x-ta.badge color="light" size="sm">{{ $finding['status'] }}</x-ta.badge>
                            </div>
                            <h3 class="mt-2 text-sm font-semibold text-gray-800 dark:text-white/90">{{ $finding['title'] }}</h3>
                            <p class="mt-1 text-sm text-gray-500">{{ $finding['summary'] }}</p>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section>
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Recent activity</h2>
                    <a href="{{ route('operator.activity', ['asset' => $identity['asset_id']]) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">{{ __('operator.customer.actions.view_activity') }}</a>
                </div>
                <ul class="divide-y divide-gray-100 rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:divide-gray-800 dark:bg-gray-900 dark:ring-gray-800">
                    @foreach ($workspace['activity'] as $row)
                        <li class="px-4 py-3 text-sm">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <p class="font-medium text-gray-800 dark:text-white/90">{{ $row['event'] }}</p>
                                <span class="text-xs text-gray-400">{{ $row['at'] }}</span>
                            </div>
                            <p class="text-xs text-gray-500">{{ $row['detail'] }}</p>
                        </li>
                    @endforeach
                </ul>
            </section>
        </div>
    @endif

    @if ($tab === 'setup')
        @php $settings = $workspace['settings']; @endphp
        <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800 space-y-3 text-sm">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.instagram.tabs.setup') }}</h2>
            <p><span class="text-gray-400">Connection mode · </span><span class="text-gray-800 dark:text-white/90">{{ $settings['connection_mode'] }}</span></p>
            <p><span class="text-gray-400">Write actions · </span><span class="text-gray-800 dark:text-white/90">{{ $settings['write_actions'] }}</span></p>
            <p><span class="text-gray-400">Sync cadence · </span><span class="text-gray-800 dark:text-white/90">{{ $settings['sync_cadence'] }}</span></p>
            <p><span class="text-gray-400">Responsible · </span><span class="text-gray-800 dark:text-white/90">{{ $settings['responsible'] }}</span></p>
            <ul class="mt-3 list-disc space-y-1 pl-5 text-gray-500">
                @foreach ($settings['notes'] as $note)
                    <li>{{ $note }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
