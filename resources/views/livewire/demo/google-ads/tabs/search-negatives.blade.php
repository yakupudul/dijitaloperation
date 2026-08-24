@php $isTr = app()->getLocale() === 'tr'; $campaignNegatives = collect(data_get($professional, 'search.campaign_negatives', [])); $adGroupNegatives = collect(data_get($professional, 'search.ad_group_negatives', [])); @endphp
<div class="mt-4 grid gap-4 xl:grid-cols-2">
    @foreach ([
        ['title' => $isTr ? 'Kampanya negatifleri' : 'Campaign negatives', 'rows' => $campaignNegatives, 'scope' => 'campaign_id'],
        ['title' => $isTr ? 'Reklam grubu negatifleri' : 'Ad group negatives', 'rows' => $adGroupNegatives, 'scope' => 'ad_group_id'],
    ] as $block)
        <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-800"><h3 class="font-semibold text-gray-900 dark:text-white">{{ $block['title'] }}</h3><span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-300">{{ $block['rows']->count() }}</span></div>
            <div class="max-h-[420px] overflow-auto"><table class="min-w-full text-sm"><thead class="sticky top-0 bg-gray-50 text-xs text-gray-500 dark:bg-gray-900"><tr><th class="px-4 py-2 text-left">{{ $isTr ? 'Negatif kelime' : 'Negative keyword' }}</th><th class="px-3 py-2 text-left">Match</th><th class="px-4 py-2 text-left">Scope</th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse($block['rows'] as $row)<tr><td class="px-4 py-2 font-medium text-gray-800 dark:text-gray-200">{{ $row['keyword_text'] ?? '—' }}</td><td class="px-3 py-2">{{ $row['match_type'] ?? '—' }}</td><td class="px-4 py-2 text-xs text-gray-500">{{ $row[$block['scope']] ?? '—' }}</td></tr>@empty<tr><td colspan="3" class="px-4 py-8 text-center text-gray-400">{{ $isTr ? 'Negatif keyword snapshotı yok.' : 'No negative keyword snapshot.' }}</td></tr>@endforelse
            </tbody></table></div>
        </section>
    @endforeach
</div>
