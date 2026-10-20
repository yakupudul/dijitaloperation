@props(['head' => null])

{{-- Plain table (sade panel 2026-10-20): no outer box, scrolls sideways inside itself on a phone. --}}
<div {{ $attributes->merge(['class' => 'min-w-0']) }}>
    <div class="max-w-full overflow-x-auto custom-scrollbar">
        <table class="min-w-full">
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
