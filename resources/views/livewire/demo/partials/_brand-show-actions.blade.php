<div class="flex flex-wrap gap-2">
    <x-ta.button href="{{ route('demo.brands') }}" size="sm" variant="outline">← Brands</x-ta.button>
    <x-ta.button href="{{ route('demo.brand.edit', ['brandId' => $brandId]) }}" size="sm" variant="outline">Edit brand</x-ta.button>
    <x-ta.button href="{{ route('demo.asset.create', ['brandId' => $brandId]) }}" size="sm" variant="outline">Add asset</x-ta.button>
    <x-ta.button wire:click="runPublicResearch" size="sm" variant="outline">Run public research</x-ta.button>
    <x-ta.button wire:click="runAiBrief" size="sm">Generate AI analysis</x-ta.button>
</div>
