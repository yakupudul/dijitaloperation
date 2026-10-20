{{--
    The provider's own mark on a 32 px tile (panel concept 2026-10-20: real logos). Expects $provider (google, meta,
    wordpress, bing, anthropic, website, dataforseo, openai, gemini, groq, openrouter, google_ads, ga4, gsc, gbp, …)
    and optionally $size ('sm' = 28 px, 'lg' = 40 px).
--}}
@php
    $markId = strtolower((string) ($provider ?? 'website'));
    $markSize = $size ?? 'md';
    $tile = match ($markSize) { 'sm' => 'h-7 w-7 rounded-[7px]', 'lg' => 'h-10 w-10 rounded-lg', default => 'h-8 w-8 rounded-lg' };
    $inner = match ($markSize) { 'sm' => 'h-4 w-4', 'lg' => 'h-5.5 w-5.5', default => 'h-[18px] w-[18px]' };
    // Marks that already exist as files (their own tinted tile) fill the tile; the others are drawn here.
    $file = match ($markId) {
        'meta', 'meta_ads' => 'meta',
        'google_ads', 'google-ads' => 'google-ads',
        'ga4', 'google_analytics' => 'ga4',
        'gsc', 'search_console' => 'gsc',
        'gbp', 'google_business_profile' => 'gbp',
        'instagram' => 'instagram',
        default => null,
    };
@endphp
@if ($file !== null)
    <img src="{{ asset('images/digital-assets/'.$file.'.svg') }}" alt="" aria-hidden="true" class="{{ $tile }} shrink-0 object-contain" />
@else
    <span class="{{ $tile }} inline-flex shrink-0 items-center justify-center border border-gray-200 bg-white text-gray-500 dark:border-gray-700 dark:bg-white/[0.06] dark:text-gray-300" aria-hidden="true">
        @switch($markId)
            @case('google')
                <svg class="{{ $inner }}" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
                @break
            @case('wordpress')
                <svg class="{{ $inner }}" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10.2" fill="none" stroke="#21759B" stroke-width="1.7"/><text x="12" y="16.4" text-anchor="middle" font-family="Georgia, 'Times New Roman', serif" font-size="12.5" font-weight="700" fill="#21759B">W</text></svg>
                @break
            @case('bing')
                <svg class="{{ $inner }}" viewBox="0 0 24 24"><defs><linearGradient id="bing-mark" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#37BDFF"/><stop offset="1" stop-color="#0C8C73"/></linearGradient></defs><path fill="url(#bing-mark)" d="M5 2l4.2 1.5v11.6l5.9-3.4-2.9-1.4-1.8-4.4 8.6 3v4.8L9.2 21.6 5 19.2z"/></svg>
                @break
            @case('anthropic')
            @case('claude')
                <svg class="{{ $inner }}" viewBox="0 0 24 24" fill="none" stroke="#D97757" stroke-width="2.4" stroke-linecap="round"><path d="M12 3v18M3 12h18M5.6 5.6l12.8 12.8M18.4 5.6 5.6 18.4"/></svg>
                @break
            @case('gemini')
                <svg class="{{ $inner }}" viewBox="0 0 24 24"><defs><linearGradient id="gemini-mark" x1="0" y1="1" x2="1" y2="0"><stop offset="0" stop-color="#4285F4"/><stop offset="1" stop-color="#9B72CB"/></linearGradient></defs><path fill="url(#gemini-mark)" d="M12 2c.5 5.4 4.6 9.5 10 10-5.4.5-9.5 4.6-10 10-.5-5.4-4.6-9.5-10-10 5.4-.5 9.5-4.6 10-10z"/></svg>
                @break
            @case('dataforseo')
                <svg class="{{ $inner }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M5 20V11M10 20V5M15 20v-7M20 20V8"/></svg>
                @break
            @case('website')
                <svg class="{{ $inner }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.6 2.8 2.6 15.2 0 18M12 3c-2.6 2.8-2.6 15.2 0 18"/></svg>
                @break
            @default
                <span class="text-[13px] font-semibold text-gray-700 dark:text-gray-200">{{ mb_strtoupper(mb_substr($markId, 0, 1)) }}</span>
        @endswitch
    </span>
@endif
