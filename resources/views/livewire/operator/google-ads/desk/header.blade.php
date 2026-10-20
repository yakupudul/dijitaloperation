{{-- Google Ads menu: title, brand filter, tabs and the one-line result message. --}}
<header class="flex flex-wrap items-start justify-between gap-3">
    <div class="min-w-0 flex-1">
        <h1 class="text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white" @if (filled($intro ?? null)) title="{{ $intro }}" @endif>Google Ads</h1>
    </div>
    <label class="flex min-w-0 max-w-full items-center gap-2 text-sm">
        <span class="text-gray-500">Marka</span>
        <select wire:model.live="brand" class="min-w-0 rounded-lg border-gray-200 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-900">
            <option value="">Tüm markalar</option>
            @foreach ($brandOptions as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
    </label>
</header>
<nav class="-mb-px flex gap-1 overflow-x-auto border-b border-gray-200 text-sm dark:border-gray-700" aria-label="Google Ads">
    @foreach (\App\Services\GoogleAds\Desk\GoogleAdsDesk::TABS as $routeName => $label)
        <a href="{{ route($routeName, array_filter(['marka' => $brand])) }}" wire:navigate
            @class(['whitespace-nowrap border-b-2 px-3 py-2 font-medium', 'border-gray-900 text-gray-900 dark:border-white dark:text-white' => $active === $routeName, 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300' => $active !== $routeName])>{{ $label }}</a>
    @endforeach
</nav>
@if ($message !== '')
    <p @class(['rounded-lg px-3 py-2 text-sm', 'tone-ok' => $messageTone === 'success', 'tone-critical' => $messageTone === 'error', 'tone-info' => ! in_array($messageTone, ['success', 'error'], true)]) data-testid="google-ads-desk-message">{{ $message }}</p>
@endif
