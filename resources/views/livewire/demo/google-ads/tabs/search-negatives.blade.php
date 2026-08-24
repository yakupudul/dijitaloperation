@php
    $isTr = app()->getLocale() === 'tr';
    $campaignNegatives = collect(data_get($professional, 'search.campaign_negatives', []));
    $adGroupNegatives = collect(data_get($professional, 'search.ad_group_negatives', []));
    $formatNegative = function ($keyword, $matchType) {
        $keyword = trim((string)$keyword);
        return match (strtoupper((string)$matchType)) {
            'EXACT' => '['.$keyword.']',
            'PHRASE' => '"'.$keyword.'"',
            default => $keyword,
        };
    };
    $matchLabel = function ($matchType) use ($isTr) {
        return match (strtoupper((string)$matchType)) {
            'EXACT' => $isTr ? 'Tam eşleme' : 'Exact',
            'PHRASE' => $isTr ? 'Sıralı eşleme' : 'Phrase',
            'BROAD' => $isTr ? 'Geniş eşleme' : 'Broad',
            default => filled($matchType) ? (string)$matchType : '—',
        };
    };
@endphp

<div class="mt-4 grid gap-4 xl:grid-cols-2">
    @foreach ([
        ['title' => $isTr ? 'Kampanya negatifleri' : 'Campaign negatives', 'rows' => $campaignNegatives, 'kind' => 'campaign'],
        ['title' => $isTr ? 'Reklam grubu negatifleri' : 'Ad group negatives', 'rows' => $adGroupNegatives, 'kind' => 'ad_group'],
    ] as $block)
        <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                <div>
                    <h3 class="font-semibold text-gray-900 dark:text-white">{{ $block['title'] }}</h3>
                    <p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Kelime yazımı eşleme türünü doğrudan gösterir: [tam], “sıralı”, geniş.' : 'Keyword notation directly reflects match type: [exact], “phrase”, broad.' }}</p>
                </div>
                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-300">{{ $block['rows']->count() }}</span>
            </div>
            <div class="max-h-[480px] overflow-auto">
                <table class="min-w-full text-sm">
                    <thead class="sticky top-0 z-10 bg-gray-50 text-xs text-gray-500 dark:bg-gray-900">
                        <tr>
                            <th class="px-4 py-2 text-left">{{ $isTr ? 'Negatif kelime' : 'Negative keyword' }}</th>
                            <th class="px-3 py-2 text-left">{{ $isTr ? 'Eşleme' : 'Match' }}</th>
                            <th class="px-4 py-2 text-left">{{ $block['kind'] === 'campaign' ? ($isTr ? 'Kampanya' : 'Campaign') : ($isTr ? 'Reklam grubu / Kampanya' : 'Ad group / Campaign') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse($block['rows'] as $row)
                            @php
                                $formatted = $formatNegative($row['keyword_text'] ?? '', $row['match_type'] ?? '');
                                $campaignName = (string)($row['campaign_name'] ?? '');
                                $campaignId = (string)($row['campaign_id'] ?? '');
                                $adGroupName = (string)($row['ad_group_name'] ?? '');
                                $adGroupId = (string)($row['ad_group_id'] ?? '');
                            @endphp
                            <tr>
                                <td class="px-4 py-2 font-mono font-semibold text-gray-900 dark:text-gray-100">{{ $formatted !== '' ? $formatted : '—' }}</td>
                                <td class="px-3 py-2 text-xs text-gray-600 dark:text-gray-300">{{ $matchLabel($row['match_type'] ?? null) }}</td>
                                <td class="px-4 py-2">
                                    @if ($block['kind'] === 'campaign')
                                        <p class="font-medium text-gray-800 dark:text-gray-200">{{ $campaignName !== '' ? $campaignName : ($campaignId !== '' ? 'Campaign '.$campaignId : '—') }}</p>
                                        @if ($campaignId !== '')<p class="mt-0.5 text-[11px] text-gray-400">ID {{ $campaignId }}</p>@endif
                                    @else
                                        <p class="font-medium text-gray-800 dark:text-gray-200">{{ $adGroupName !== '' ? $adGroupName : ($adGroupId !== '' ? 'Ad group '.$adGroupId : '—') }}</p>
                                        <p class="mt-0.5 text-[11px] text-gray-400">{{ $campaignName !== '' ? $campaignName : ($campaignId !== '' ? 'Campaign '.$campaignId : '—') }}@if($adGroupId !== '') · ID {{ $adGroupId }} @endif</p>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-8 text-center text-gray-400">{{ $isTr ? 'Negatif keyword snapshotı yok.' : 'No negative keyword snapshot.' }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endforeach
</div>