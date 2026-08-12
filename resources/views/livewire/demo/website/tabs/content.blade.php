@php
    $cw = $data['content_workspace'];
@endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Content</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">What the Website contains, how it is structured, what demand it covers and what is missing.</p>
    </div>

    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 xl:grid-cols-8">
        @foreach ($cw['inventory'] as $card)
            <div class="rounded-xl bg-white p-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <p class="text-xs text-gray-500">{{ $card['label'] }}</p>
                <p class="mt-1 text-lg font-semibold tabular-nums text-gray-900 dark:text-white">{{ number_format($card['count']) }}</p>
                <p class="mt-1 text-[10px] text-gray-400">{{ $card['source'] }}</p>
            </div>
        @endforeach
    </div>

    <div class="flex flex-col gap-3 rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700 lg:flex-row lg:items-end">
        <div class="min-w-0 flex-1">
            <label for="content-search" class="mb-1 block text-xs font-medium text-gray-500">Search</label>
            <input id="content-search" type="search" wire:model.live.debounce.300ms="content_q" placeholder="Search content..." class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
        </div>
        <select wire:model.live="content_role" aria-label="Content role" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            <option value="">Content role</option>
            @foreach ($cw['roles'] as $role)
                <option value="{{ $role }}">{{ $role }}</option>
            @endforeach
        </select>
        <select wire:model.live="content_cms" aria-label="CMS type" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            <option value="">CMS type</option>
            <option value="page">page</option>
            <option value="post">post</option>
            <option value="treatment">treatment</option>
            <option value="doctor">doctor</option>
            <option value="faq">faq</option>
        </select>
        @if ($content_q !== '' || $content_role !== '' || $content_cms !== '')
            <button type="button" wire:click="clearContentFilters" class="text-xs font-medium text-brand-600 hover:underline">Clear</button>
        @endif
    </div>

    @if ($selectedPage)
        <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-brand-300 dark:bg-gray-800 dark:ring-brand-500/40">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $selectedPage['title'] }}</h3>
                    <p class="text-sm text-gray-500">{{ $selectedPage['url'] }}</p>
                </div>
                <button type="button" wire:click="closeContentPage" class="text-xs text-gray-500 hover:underline">Close</button>
            </div>
            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-xs text-gray-400">CMS type</dt><dd>{{ $selectedPage['cms_type'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Content role</dt><dd>{{ $selectedPage['role'] }} · {{ $selectedPage['classification'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Language</dt><dd>{{ $selectedPage['language'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Last modified</dt><dd>{{ $selectedPage['updated'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">H1</dt><dd>{{ $selectedPage['h1'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Word count (context only)</dt><dd>{{ number_format($selectedPage['word_count']) }}</dd></div>
                <div><dt class="text-xs text-gray-400">Organic</dt><dd>{{ $selectedPage['organic'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Traffic</dt><dd>{{ $selectedPage['traffic'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Schema</dt><dd>{{ $selectedPage['schema'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Key events</dt><dd>{{ $selectedPage['events'] }}</dd></div>
            </dl>
            @if ($selectedPage['findings'] !== [])
                <p class="mt-3 text-xs text-gray-500">Findings: {{ implode(' · ', $selectedPage['findings']) }}</p>
            @endif
            @if ($selectedPage['opportunities'] !== [])
                <p class="mt-1 text-xs text-gray-500">Opportunities: {{ implode(' · ', $selectedPage['opportunities']) }}</p>
            @endif
        </div>
    @endif

    <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <table class="min-w-full divide-y divide-gray-100 dark:divide-gray-800">
            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-400 dark:bg-white/[0.02]">
                <tr>
                    <th class="px-4 py-3">Content</th>
                    <th class="px-4 py-3">Role</th>
                    <th class="px-4 py-3">CMS type</th>
                    <th class="px-4 py-3">Topic</th>
                    <th class="px-4 py-3">Organic</th>
                    <th class="px-4 py-3">Traffic</th>
                    <th class="px-4 py-3">Updated</th>
                    <th class="px-4 py-3">State</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($contentDirectory as $row)
                    <tr>
                        <td class="px-4 py-3 text-sm">
                            <p class="font-medium text-gray-800 dark:text-white/90">{{ $row['title'] }}</p>
                            <p class="text-xs text-gray-400">{{ $row['url'] }}</p>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ $row['role'] }}</td>
                        <td class="px-4 py-3 text-sm text-gray-500">{{ $row['cms_type'] }}</td>
                        <td class="px-4 py-3 text-sm text-gray-500">{{ $row['topic'] }}</td>
                        <td class="px-4 py-3 text-sm text-gray-500">{{ $row['organic'] }}</td>
                        <td class="px-4 py-3 text-sm text-gray-500">{{ $row['traffic'] }}</td>
                        <td class="px-4 py-3 text-sm text-gray-500">{{ $row['updated'] }}</td>
                        <td class="px-4 py-3 text-sm text-gray-500">{{ $row['state'] }}</td>
                        <td class="px-4 py-3 text-right"><button type="button" wire:click="openContentPage('{{ $row['id'] }}')" class="text-xs font-medium text-brand-600 hover:underline">Inspect</button></td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-8 text-center text-sm text-gray-500">No content matches these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Coverage · {{ $cw['coverage']['offering'] }}</h3>
            <ul class="mt-3 space-y-2 text-sm">
                @foreach ($cw['coverage']['rows'] as $row)
                    <li>
                        <div class="flex justify-between gap-3">
                            <span class="text-gray-800 dark:text-white/90">{{ $row['need'] }}</span>
                            <span class="text-xs text-gray-500">{{ $row['state'] }}</span>
                        </div>
                        <p class="text-xs text-gray-400">{{ $row['why'] }}</p>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Topic cluster · {{ $cw['topic_cluster']['name'] }}</h3>
            <ul class="mt-3 space-y-1 text-sm">
                @foreach ($cw['topic_cluster']['nodes'] as $node)
                    <li class="flex justify-between gap-3">
                        <span class="text-gray-700 dark:text-gray-300">{{ $node['role'] }}@if ($node['page']) · {{ $node['page'] }}@endif</span>
                        <span class="text-xs text-gray-500">{{ $node['state'] }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>

    <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Content opportunities</h3>
        @foreach ($cw['gaps'] as $gap)
            <div class="mt-3 rounded-lg bg-gray-50 p-4 dark:bg-white/[0.03]">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Content opportunity</p>
                <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $gap['title'] }}</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
                    @foreach ($gap['why'] as $why)<li>{{ $why }}</li>@endforeach
                </ul>
                <p class="mt-2 text-xs text-gray-500">Audience · {{ $gap['audience'] }}</p>
                <p class="text-xs text-gray-500">Suggested role · {{ $gap['suggested_role'] }}</p>
                <p class="mt-1 text-xs text-gray-400">Sources · {{ implode(' · ', $gap['sources']) }}</p>
                <p class="mt-3 text-xs text-gray-400">No automatic publish. Human review required.</p>
            </div>
        @endforeach
    </section>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Content decay candidates</h3>
            @foreach ($cw['decay'] as $row)
                <div class="mt-3 text-sm">
                    <p class="font-medium text-gray-800 dark:text-white/90">{{ $row['page'] }}</p>
                    <p class="text-xs text-gray-500">Clicks {{ $row['clicks_delta'] }} · Impr {{ $row['impressions_delta'] }} · Pos {{ $row['position'] }}</p>
                    <p class="text-xs text-gray-500">Last modified {{ $row['last_modified'] }} · {{ $row['window'] }}</p>
                    <p class="mt-1 text-xs text-amber-700 dark:text-amber-400">{{ $row['state'] }}</p>
                </div>
            @endforeach
        </section>
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Trend Intelligence</h3>
            <p class="mt-1 text-xs text-gray-400">Future-ready · Demo fixture · no pytrends / no live provider</p>
            @foreach ($cw['trends'] as $trend)
                <div class="mt-3 text-sm">
                    <p class="font-medium text-gray-800 dark:text-white/90">{{ $trend['topic'] }}</p>
                    <p class="text-xs text-gray-500">{{ $trend['market'] }} · {{ $trend['language'] }} · {{ $trend['trend'] }}</p>
                    <p class="text-xs text-gray-500">{{ $trend['why'] }} · Coverage {{ $trend['coverage'] }}</p>
                    <p class="mt-1 text-xs text-gray-400">{{ $trend['source'] }}</p>
                </div>
            @endforeach
        </section>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Internal linking</h3>
            <ul class="mt-2 space-y-1 text-sm text-gray-600 dark:text-gray-300">
                @foreach ($cw['internal_linking'] as $row)
                    <li>{{ $row['signal'] }} · {{ $row['value'] }}</li>
                @endforeach
            </ul>
        </section>
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Media</h3>
            <ul class="mt-2 space-y-1 text-sm text-gray-600 dark:text-gray-300">
                <li>Images {{ number_format($cw['media']['image_count']) }}</li>
                <li>Oversized candidates {{ $cw['media']['oversized_candidates'] }}</li>
                <li>Missing alt candidates {{ $cw['media']['missing_alt_candidates'] }}</li>
                <li>Broken images {{ $cw['media']['broken_images'] }}</li>
            </ul>
            <p class="mt-2 text-xs text-gray-400">{{ $cw['media']['note'] }}</p>
        </section>
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Content debt</h3>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
                @foreach ($cw['debt'] as $item)<li>{{ $item }}</li>@endforeach
            </ul>
        </section>
    </div>

    <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Information architecture</h3>
        <pre class="mt-3 overflow-x-auto text-xs text-gray-600 dark:text-gray-300">{{ implode("\n", $cw['architecture']) }}</pre>
    </section>
</div>
