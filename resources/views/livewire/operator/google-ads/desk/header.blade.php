{{-- Google Ads menu: section tabs (same place and look as the layout's section tabs), page head with the brand filter, result line. --}}
<div class="min-w-0">
    <nav class="no-scrollbar -mt-1 mb-6 flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" aria-label="Google Ads">
        @foreach (\App\Services\GoogleAds\Desk\GoogleAdsDesk::TABS as $routeName => $label)
            <a href="{{ route($routeName, array_filter(['marka' => $brand])) }}" wire:navigate @if ($active === $routeName) aria-current="page" @endif
                @class(['tab', 'tab-active' => $active === $routeName])>{{ $label }}</a>
        @endforeach
    </nav>
    <header class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
        <div class="min-w-0 flex-1">
            <h1 class="page-title" @if (filled($intro ?? null)) title="{{ $intro }}" @endif>@if ($highlight ?? false)Google <span class="highlight">Ads</span>@else Google Ads @endif</h1>
            @if (filled($sub ?? null))<p class="page-sub">{{ $sub }}</p>@endif
        </div>
        <div class="flex w-full min-w-0 flex-wrap items-center gap-2 sm:w-auto sm:flex-nowrap">
            @if (! empty($bulk))
                {{-- Bulk approve: the one main action of a suggestion tab. --}}
                <span class="mr-1 whitespace-nowrap text-[13px] text-gray-500" title="Yüksek riskliler tek tek onaylanır. Elle yapılacak kontroller onaylanmaz, Google Ads’te yapılınca ertesi sabah kendiliğinden kapanır.">{{ $bulk['count'] }} öneri, {{ count($selected) }} seçili</span>
                <button type="button" wire:click="approveSelected" @disabled($selected === []) class="btn-primary order-last h-9! w-full sm:order-none sm:w-auto" data-testid="google-ads-approve-selected">Seçilenleri onayla</button>
            @endif
            <label class="flex w-full min-w-0 items-center sm:w-auto">
                <span class="sr-only">Marka</span>
                <select wire:model.live="brand" class="h-9 w-full min-w-0 rounded-lg border border-gray-200 bg-white py-0 pl-3 pr-8 text-sm text-gray-900 sm:w-56 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    <option value="">Tüm markalar</option>
                    @foreach ($brandOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </header>
    @if ($message !== '')
        <p @class(['mt-4 rounded-lg px-3 py-2 text-sm', 'tone-ok' => $messageTone === 'success', 'tone-critical' => $messageTone === 'error', 'tone-info' => ! in_array($messageTone, ['success', 'error'], true)]) data-testid="google-ads-desk-message">{{ $message }}</p>
    @endif
</div>
