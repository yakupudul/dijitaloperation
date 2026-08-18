<section>
    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Related digital assets</h3>
    <p class="mt-1 text-xs text-gray-400">Independent Brand Digital Assets — not Website connections.</p>
    <div class="mt-3 grid gap-3 lg:grid-cols-3">
        @foreach ($conn['related_assets'] as $asset)
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $asset['name'] }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ $asset['detail'] }}</p>
                <p class="mt-2 text-xs text-gray-400">{{ $asset['note'] }}</p>
                <a href="{{ $asset['url'] ?? \App\Services\Operator\OperatorPortfolioPresenter::specialistHref($asset) }}" wire:navigate class="mt-3 inline-flex text-xs font-medium text-brand-600 hover:underline">{{ __('operator.actions.open') }} {{ $asset['name'] }}</a>
            </div>
        @endforeach
    </div>
</section>
