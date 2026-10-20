@props(['head' => null])

{{-- Table in a card (panel concept 2026-10-20): light header row, hairline rows; scrolls sideways inside itself on a phone. --}}
<div {{ $attributes->merge(['class' => 'card']) }}>
    <div class="max-w-full overflow-x-auto custom-scrollbar">
        <table class="data-table min-w-full">
            @isset($head)
                <thead class="border-b border-gray-200 dark:border-gray-800">
                    <tr>{{ $head }}</tr>
                </thead>
            @endisset
            <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                {{ $slot }}
            </tbody>
        </table>
    </div>
</div>
