@props(['head' => null])

{{-- TailAdmin basic-table wrapper: horizontally scrollable, bordered card. --}}
<div {{ $attributes->merge(['class' => 'overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]']) }}>
    <div class="max-w-full overflow-x-auto custom-scrollbar">
        <table class="min-w-full">
            @isset($head)
                <thead class="border-b border-gray-100 dark:border-gray-800">
                    <tr>{{ $head }}</tr>
                </thead>
            @endisset
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                {{ $slot }}
            </tbody>
        </table>
    </div>
</div>
