@php
    $reviews = $data['reviews'];
    $glance = $reviews['glance'];
    $dist = $reviews['distribution'];
    $totalDist = max(1, array_sum($dist));
@endphp

<div class="space-y-5">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Reviews</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $reviews['subtitle'] }}</p>
        <p class="mt-1 text-xs text-gray-400">{{ $reviews['provenance'] }} · {{ $reviews['no_write'] }}</p>
    </div>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
        <div class="rounded-xl bg-white p-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <p class="text-xs text-gray-500">Rating</p>
            <p class="text-xl font-semibold tabular-nums">{{ $glance['rating'] }}</p>
        </div>
        <div class="rounded-xl bg-white p-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <p class="text-xs text-gray-500">Total reviews</p>
            <p class="text-xl font-semibold tabular-nums">{{ $glance['total'] }}</p>
        </div>
        <div class="rounded-xl bg-white p-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <p class="text-xs text-gray-500">New</p>
            <p class="text-xl font-semibold tabular-nums">{{ $glance['new'] }}</p>
        </div>
        <div class="rounded-xl bg-white p-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <p class="text-xs text-gray-500">Needs reply</p>
            <p class="text-xl font-semibold tabular-nums">{{ $glance['needs_reply'] }}</p>
        </div>
        <div class="rounded-xl bg-white p-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <p class="text-xs text-gray-500">Attention reviews</p>
            <p class="text-xl font-semibold tabular-nums">{{ $glance['attention'] }}</p>
        </div>
    </div>

    <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Star distribution</h3>
        <ul class="mt-3 space-y-1.5 text-sm" aria-label="Review star distribution">
            @foreach ([5,4,3,2,1] as $star)
                @php $pct = (int) round(($dist[$star] / $totalDist) * 100); @endphp
                <li class="flex items-center gap-2">
                    <span class="w-8 tabular-nums text-xs text-gray-500">{{ $star }} ★</span>
                    <div class="h-2 flex-1 rounded bg-gray-100 dark:bg-gray-700"><div class="h-2 rounded bg-amber-500" style="width: {{ $pct }}%"></div></div>
                    <span class="w-10 text-right text-xs tabular-nums text-gray-500">{{ $pct }}%</span>
                </li>
            @endforeach
        </ul>
    </section>

    <div class="inline-flex rounded-lg ring-1 ring-inset ring-gray-300 dark:ring-gray-700" role="tablist" aria-label="Reviews sections">
        @foreach (['inbox' => 'Inbox', 'topics' => 'Topics', 'queue' => 'Response Queue'] as $key => $label)
            <button type="button" role="tab" wire:click="setReviewsSub('{{ $key }}')" @class([
                'px-3 py-2 text-xs font-medium',
                'bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-white' => $reviews_sub === $key,
                'text-gray-600 dark:text-gray-300' => $reviews_sub !== $key,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($reviews_sub === 'inbox')
        <div class="flex flex-col gap-3 rounded-xl bg-white p-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700 sm:flex-row sm:flex-wrap">
            <label class="text-xs text-gray-500">Stars
                <select wire:model.live="review_stars" class="mt-1 block rounded-lg border-gray-200 text-sm dark:border-gray-700 dark:bg-gray-900">
                    <option value="all">All</option>
                    @foreach ([5,4,3,2,1] as $s)<option value="{{ $s }}">{{ $s }} ★</option>@endforeach
                </select>
            </label>
            <label class="text-xs text-gray-500">Reply state
                <select wire:model.live="review_reply" class="mt-1 block rounded-lg border-gray-200 text-sm dark:border-gray-700 dark:bg-gray-900">
                    <option value="all">All</option>
                    <option value="Needs reply">Needs reply</option>
                    <option value="Replied">Replied</option>
                </select>
            </label>
            <label class="text-xs text-gray-500">Topic
                <select wire:model.live="review_topic" class="mt-1 block rounded-lg border-gray-200 text-sm dark:border-gray-700 dark:bg-gray-900">
                    <option value="">All topics</option>
                    @foreach ($reviews['topics'] as $t)<option value="{{ $t['topic'] }}">{{ $t['topic'] }}</option>@endforeach
                </select>
            </label>
            <label class="min-w-[12rem] flex-1 text-xs text-gray-500">Search
                <input type="search" wire:model.live.debounce.300ms="review_q" class="mt-1 w-full rounded-lg border-gray-200 text-sm dark:border-gray-700 dark:bg-gray-900" placeholder="Review text" />
            </label>
        </div>

        <ul class="space-y-2">
            @forelse ($reviewInbox as $review)
                <li class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ $review['reviewer'] }}</span>
                                <span class="text-xs tabular-nums text-amber-700 dark:text-amber-400">{{ $review['stars'] }} ★</span>
                                <span class="text-xs text-gray-400">{{ $review['date'] }}</span>
                                <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ $review['reply'] }}</span>
                                <span class="text-[10px] uppercase text-gray-400">{{ $review['sentiment'] }} · {{ $reviews['provenance'] }}</span>
                                @if (! empty($review['attention']))
                                    <span class="rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-semibold uppercase text-rose-700 dark:bg-rose-500/15 dark:text-rose-400">Needs attention</span>
                                @endif
                            </div>
                            <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">{{ $review['excerpt'] }}</p>
                            <p class="mt-1 text-xs text-gray-500">Topics · {{ implode(', ', $review['topics']) }}</p>
                            @if (! empty($review['why']))
                                <p class="mt-1 text-xs text-rose-700 dark:text-rose-400">Why · {{ $review['why'] }}</p>
                            @endif
                        </div>
                        @if ($review['reply'] === 'Needs reply')
                            <button type="button" wire:click="createReviewTask('{{ $review['id'] }}')" class="shrink-0 rounded-lg px-3 py-1.5 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Create task</button>
                        @endif
                    </div>
                </li>
            @empty
                <li class="text-sm text-gray-500">No reviews match these filters.</li>
            @endforelse
        </ul>
    @elseif ($reviews_sub === 'topics')
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">What customers are talking about</h3>
            <p class="mt-1 text-xs text-gray-400">{{ $reviews['provenance'] }} · multi-label topics</p>
            <table class="mt-3 w-full text-left text-sm">
                <thead class="text-xs text-gray-500">
                    <tr>
                        <th class="py-1">Topic</th>
                        <th class="py-1">Mentions</th>
                        <th class="py-1">Positive</th>
                        <th class="py-1">Mixed</th>
                        <th class="py-1">Negative</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($reviews['topics'] as $topic)
                        <tr>
                            <td class="py-2 font-medium text-gray-900 dark:text-white">{{ $topic['topic'] }}</td>
                            <td class="py-2 tabular-nums">{{ $topic['mentions'] }}</td>
                            <td class="py-2 tabular-nums text-emerald-700 dark:text-emerald-400">{{ $topic['positive'] }}</td>
                            <td class="py-2 tabular-nums text-amber-700 dark:text-amber-400">{{ $topic['mixed'] }}</td>
                            <td class="py-2 tabular-nums text-rose-700 dark:text-rose-400">{{ $topic['negative'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="mt-4 rounded-lg bg-amber-50 p-3 text-sm dark:bg-amber-500/10">
                <p class="font-medium text-amber-900 dark:text-amber-200">{{ $reviews['topic_trend']['note'] }}</p>
                <p class="mt-1 text-xs text-amber-800 dark:text-amber-300">Current period {{ $reviews['topic_trend']['current'] }} mentions · Previous {{ $reviews['topic_trend']['previous'] }}</p>
            </div>
        </section>
    @else
        <section class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Response queue</h3>
                <p class="text-xs text-gray-400">Prioritized for human attention. No Reply on Google action.</p>
            </div>
            <table class="min-w-full text-left text-sm">
                <thead class="text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-2">Review</th>
                        <th class="px-4 py-2">Stars</th>
                        <th class="px-4 py-2">Age</th>
                        <th class="px-4 py-2">Topics</th>
                        <th class="px-4 py-2">Why queued</th>
                        <th class="px-4 py-2">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($reviews['queue'] as $row)
                        <tr>
                            <td class="px-4 py-2 font-medium text-gray-900 dark:text-white">{{ $row['reviewer'] }}</td>
                            <td class="px-4 py-2 tabular-nums">{{ $row['stars'] }} ★</td>
                            <td class="px-4 py-2">{{ $row['age'] }}</td>
                            <td class="px-4 py-2 text-xs">{{ $row['topics'] }}</td>
                            <td class="px-4 py-2 text-xs text-gray-600 dark:text-gray-300">{{ $row['why'] }}</td>
                            <td class="px-4 py-2">
                                <button type="button" wire:click="createReviewTask('{{ $row['id'] }}')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Create task</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif
</div>
