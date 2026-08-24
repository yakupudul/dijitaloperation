@php
    $rangeStart = (string) ($data['period_start'] ?? ($periodStart ?? ''));
    $rangeEnd = (string) ($data['period_end'] ?? ($periodEnd ?? ''));
    $resolvedAssetId = (string) ($assetId ?? '');

    if ($resolvedAssetId !== '' && $rangeStart !== '' && $rangeEnd !== '') {
        $data = app(\App\Services\GoogleAds\GoogleAdsSearchLiveReadFallbackService::class)->reconcile(
            $resolvedAssetId,
            $rangeStart,
            $rangeEnd,
            $data,
            (string) ($period ?? ''),
        );
    }

    $liveSearch = is_array($data['search'] ?? null) ? $data['search'] : [];

    $liveTerms = collect($liveSearch['terms'] ?? []);
    $queryText = mb_strtolower(trim((string) ($search_query ?? '')));
    if ($queryText !== '') {
        $liveTerms = $liveTerms->filter(static function (array $row) use ($queryText): bool {
            $haystack = mb_strtolower(implode(' ', array_filter([
                $row['term'] ?? null,
                $row['campaign'] ?? null,
                $row['ad_group'] ?? null,
                $row['matched_keyword'] ?? null,
                $row['match_source'] ?? null,
            ])));
            return str_contains($haystack, $queryText);
        });
    }
    if (($search_campaign ?? 'all') !== 'all') {
        $selectedCampaign = (string) $search_campaign;
        $liveTerms = $liveTerms->filter(static fn (array $row): bool => in_array($selectedCampaign, $row['campaign_ids'] ?? [], true));
    }
    if (($search_ad_group ?? 'all') !== 'all') {
        $selectedAdGroup = (string) $search_ad_group;
        $liveTerms = $liveTerms->filter(static fn (array $row): bool => in_array($selectedAdGroup, $row['ad_group_ids'] ?? [], true));
    }
    if (($search_source ?? 'all') !== 'all') {
        $selectedSource = (string) $search_source;
        $liveTerms = $liveTerms->filter(static fn (array $row): bool => (string) ($row['source'] ?? '') === $selectedSource);
    }
    if (($search_match ?? 'all') !== 'all') {
        $selectedMatch = (string) $search_match;
        $liveTerms = $liveTerms->filter(static fn (array $row): bool => (string) ($row['match_type'] ?? '') === $selectedMatch);
    }
    if (($intent_filter ?? 'all') !== 'all') {
        $selectedIntent = (string) $intent_filter;
        $liveTerms = $liveTerms->filter(static fn (array $row): bool => (string) ($row['intent'] ?? '') === $selectedIntent);
    }
    if (($decision_filter ?? 'all') !== 'all') {
        $selectedDecision = (string) $decision_filter;
        $liveTerms = $liveTerms->filter(static fn (array $row): bool => (string) ($row['decision'] ?? '') === $selectedDecision);
    }
    $liveTerms = $liveTerms->values();

    $rowsPerPage = max(1, (int) ($search_per_page ?? 100));
    $currentSearchPage = max(1, (int) ($search_page ?? 1));
    $termRowsTotal = $liveTerms->count();
    $termRowsLastPage = max(1, (int) ceil($termRowsTotal / $rowsPerPage));
    $currentSearchPage = min($currentSearchPage, $termRowsLastPage);
    $termRows = $liveTerms
        ->slice(($currentSearchPage - 1) * $rowsPerPage, $rowsPerPage)
        ->values()
        ->all();

    $liveKeywords = collect($liveSearch['keywords'] ?? []);
    if ($queryText !== '') {
        $liveKeywords = $liveKeywords->filter(static function (array $row) use ($queryText): bool {
            $haystack = mb_strtolower(implode(' ', array_filter([
                $row['keyword'] ?? null,
                $row['campaign'] ?? null,
                $row['ad_group'] ?? null,
                $row['match'] ?? null,
                $row['status'] ?? null,
            ])));
            return str_contains($haystack, $queryText);
        });
    }
    if (($search_campaign ?? 'all') !== 'all') {
        $selectedCampaign = (string) $search_campaign;
        $liveKeywords = $liveKeywords->filter(static fn (array $row): bool => (string) ($row['campaign_id'] ?? '') === $selectedCampaign);
    }
    if (($search_ad_group ?? 'all') !== 'all') {
        $selectedAdGroup = (string) $search_ad_group;
        $liveKeywords = $liveKeywords->filter(static fn (array $row): bool => (string) ($row['ad_group_id'] ?? '') === $selectedAdGroup);
    }
    if (($keyword_status ?? 'all') !== 'all') {
        $selectedStatus = strtoupper((string) $keyword_status);
        $liveKeywords = $liveKeywords->filter(static fn (array $row): bool => strtoupper((string) ($row['status'] ?? '')) === $selectedStatus);
    }
    $liveKeywords = $liveKeywords->values();

    $currentKeywordPage = max(1, (int) ($keyword_page ?? 1));
    $keywordRowsTotal = $liveKeywords->count();
    $keywordRowsLastPage = max(1, (int) ceil($keywordRowsTotal / $rowsPerPage));
    $currentKeywordPage = min($currentKeywordPage, $keywordRowsLastPage);
    $keywordRows = $liveKeywords
        ->slice(($currentKeywordPage - 1) * $rowsPerPage, $rowsPerPage)
        ->values()
        ->all();
@endphp

@if (! empty(data_get($data, 'search.live_read_error.message')))
    <div class="mb-4 rounded-xl bg-rose-50 px-4 py-3 text-xs text-rose-800 ring-1 ring-inset ring-rose-200 dark:bg-rose-500/10 dark:text-rose-200 dark:ring-rose-500/20">
        <strong>{{ app()->getLocale() === 'tr' ? 'Google Ads canlı Arama okuması başarısız:' : 'Google Ads live Search read failed:' }}</strong>
        {{ data_get($data, 'search.live_read_error.message') }}
    </div>
@endif

@include('livewire.demo.google-ads.tabs.search-expert-v2')
