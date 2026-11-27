<div wire:loading wire:target="uploads" class="text-sm text-gray-600">Dosya yükleniyor…</div>
@if ($uploads !== [])
    <ul class="flex flex-wrap gap-2" data-panel-uploads>
        @foreach ($uploads as $index => $upload)
            <li class="inline-flex max-w-full items-center gap-1.5 rounded-lg border border-line bg-soft py-1 pl-2.5 pr-1 text-sm text-gray-900" wire:key="upload-{{ $index }}">
                <span class="truncate">{{ $upload->getClientOriginalName() }}</span>
                <button type="button" wire:click="dropUpload({{ $index }})" class="grid h-7 w-7 shrink-0 place-items-center rounded-md text-gray-500 hover:bg-hover hover:text-gray-900" aria-label="Dosyayı çıkar">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </li>
        @endforeach
    </ul>
@endif
@error('uploads.*')<span class="block text-sm text-error-600">{{ $message }}</span>@enderror
