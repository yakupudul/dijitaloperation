@php
    $input = 'h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white';
    $filtered = trim($search) !== '' || $scope !== '';
@endphp
<div class="space-y-10" x-data="{ uploadOpen: @js($files->isEmpty() && ! $filtered) }">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="page-title" title="{{ __('operator.files.subtitle') }}">{{ __('operator.files.title') }}</h1>
            <p class="page-sub">{{ $files->total() }} dosya, özel depolamada; indirmek için giriş gerekir.</p>
        </div>
        <button type="button" @click="uploadOpen = ! uploadOpen" class="btn-primary" :aria-expanded="uploadOpen.toString()">{{ __('operator.files.upload_cta') }}</button>
    </div>

    <form wire:submit="uploadFile" x-show="uploadOpen || {{ $errors->has('upload') ? 'true' : 'false' }}" x-cloak class="card -mt-4">
        <div class="grid gap-4 p-5 text-[13px] sm:grid-cols-2 lg:grid-cols-3">
            <label class="block sm:col-span-2 lg:col-span-1">
                <span class="text-gray-600 dark:text-gray-300">{{ __('operator.actions.upload') }}</span>
                <input type="file" wire:model="upload"
                    class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:h-9 file:rounded-lg file:border file:border-gray-200 file:bg-white file:px-3 file:text-[13px] file:font-medium file:text-gray-900 hover:file:bg-gray-50 dark:text-gray-300 dark:file:border-gray-700 dark:file:bg-white/[0.04] dark:file:text-gray-200" />
                @error('upload') <span class="mt-1 block text-error-600 dark:text-error-400">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="text-gray-600 dark:text-gray-300">{{ __('operator.files.scope') }}</span>
                <select wire:model="uploadScope" class="{{ $input }} mt-1">
                    @foreach ($scopes as $scopeKey)
                        <option value="{{ $scopeKey }}">{{ __('operator.files.scopes.'.$scopeKey) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="text-gray-600 dark:text-gray-300">Açıklama</span>
                <input wire:model="uploadDescription" type="text" class="{{ $input }} mt-1" />
            </label>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-x-3 gap-y-2 border-t border-gray-200 bg-gray-50/60 px-4 py-3 dark:border-gray-800 dark:bg-white/[0.02]">
            <p class="mr-auto text-xs text-gray-500">Görseller, PDF, DOC/DOCX, XLS/XLSX, TXT, CSV ve ZIP yüklenebilir; PHP ve EXE yüklenemez.</p>
            <button type="button" @click="uploadOpen = false" class="btn-quiet">{{ __('operator.actions.cancel') }}</button>
            <button type="submit" wire:loading.attr="disabled" class="btn-primary">{{ __('operator.actions.upload') }}</button>
        </div>
    </form>

    <section>
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <label class="block min-w-0 flex-1 sm:max-w-sm">
                <span class="sr-only">{{ __('operator.actions.search') }}</span>
                <input wire:model.live.debounce.300ms="search" type="search" placeholder="{{ __('operator.files.search_placeholder') }}" class="{{ $input }}" />
            </label>
            <label class="block">
                <span class="sr-only">{{ __('operator.files.scope') }}</span>
                <select wire:model.live="scope" class="{{ $input }} w-auto">
                    <option value="">{{ __('operator.files.scope_all') }}</option>
                    @foreach ($scopes as $scopeKey)
                        <option value="{{ $scopeKey }}">{{ __('operator.files.scopes.'.$scopeKey) }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        @if ($files->isEmpty())
            <div class="card">
                <x-ta.empty-state :title="__('operator.files.empty')" size="sm" icon="none" />
            </div>
        @else
            <ul class="card">
                @foreach ($files as $file)
                    @php($extension = strtoupper((string) pathinfo((string) $file->original_name, PATHINFO_EXTENSION)))
                    <li wire:key="file-{{ $file->id }}" class="card-row flex-wrap sm:flex-nowrap">
                        <span class="row-icon text-[10px] font-semibold tracking-wide" title="{{ $file->mime }}">{{ $extension !== '' ? mb_substr($extension, 0, 4) : 'DOS' }}</span>
                        <div class="min-w-0 flex-1">
                            @if ($renamingId === $file->id)
                                <div class="flex flex-wrap items-center gap-2">
                                    <input wire:model="renameName" type="text" aria-label="{{ __('operator.files.original_name') }}" class="h-8 w-full min-w-0 rounded-lg border border-gray-200 bg-white px-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none sm:w-80 dark:border-gray-700 dark:bg-transparent dark:text-white" />
                                    <button type="button" wire:click="saveRename" class="btn-primary h-7! px-2.5! text-xs!">{{ __('operator.actions.save') }}</button>
                                    <button type="button" wire:click="cancelRename" class="btn-quiet h-7! text-xs!">{{ __('operator.actions.cancel') }}</button>
                                </div>
                                @error('renameName') <span class="text-xs text-error-600">{{ $message }}</span> @enderror
                            @else
                                <p class="break-words text-sm font-medium text-gray-900 dark:text-white">{{ $file->original_name }}</p>
                                <p class="mt-0.5 flex flex-wrap gap-x-3 text-[13px] text-gray-500 dark:text-gray-400">
                                    <span>{{ __('operator.files.scopes.'.$file->scope_type) }}</span>
                                    <span class="tabular-nums">{{ number_format($file->size / 1024, 1, ',', '.') }} KB</span>
                                    <span>{{ $file->created_at?->diffForHumans() }}</span>
                                    @if (filled($file->description))<span class="min-w-0 break-words">{{ $file->description }}</span>@endif
                                </p>
                            @endif
                        </div>
                        <div class="flex w-full shrink-0 flex-wrap items-center justify-end gap-1 pl-11 sm:w-auto sm:pl-0">
                            @if ($confirmDeleteId === $file->id)
                                <span class="mr-1 text-xs text-warning-700 dark:text-warning-400">{{ __('operator.files.confirm_delete') }}</span>
                                <button type="button" wire:click="deleteFile" class="btn h-7! px-2.5! text-xs! text-error-700! dark:text-error-300!">{{ __('operator.actions.confirm') }}</button>
                                <button type="button" wire:click="cancelDelete" class="btn-quiet h-7! text-xs!">{{ __('operator.actions.cancel') }}</button>
                            @else
                                <button type="button" wire:click="startRename({{ $file->id }})" class="btn-quiet h-7! text-xs!">{{ __('operator.actions.rename') }}</button>
                                <button type="button" wire:click="askDelete({{ $file->id }})" class="btn-quiet h-7! text-xs!">{{ __('operator.actions.delete') }}</button>
                                <a href="{{ route('operator.files.download', $file) }}" class="btn h-7! px-2.5! text-xs!">{{ __('operator.actions.download') }}</a>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
            <div class="pt-3">
                {{ $files->links() }}
            </div>
        @endif
    </section>
</div>
