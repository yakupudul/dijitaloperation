{{-- Sector dropdown of "AI ile planla" step 1. Brand row: '' = no sector. Asset row: '' = the brand's sector (inherit). --}}
<select wire:model="{{ $model }}" aria-label="Sektör" class="{{ $input }} py-1 text-xs">
    <option value="">{{ $brandLabel !== null ? 'Marka: '.$brandLabel : '—' }}</option>
    @foreach ($sectors as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
    @foreach ($newOptions as $value)<option value="{{ $value }}">Yeni sektör: {{ substr($value, 4) }}</option>@endforeach
</select>
