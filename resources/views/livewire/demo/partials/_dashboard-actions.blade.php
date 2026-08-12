<div class="flex flex-wrap gap-2">
    <x-ta.button href="{{ route('demo.brands') }}" variant="outline" size="sm">Open brands</x-ta.button>
    <x-ta.button href="{{ route('demo.findings') }}" size="sm">Review findings</x-ta.button>
    <x-ta.button wire:click="resetDemo" variant="outline" size="sm">Reset demo</x-ta.button>
</div>
