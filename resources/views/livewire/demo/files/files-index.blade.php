<div class="space-y-10">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white">{{ __('operator.files.title') }}</h1>
        </div>
    </div>

    <form wire:submit="uploadFile">
        <h2 class="section-title">{{ __('operator.files.upload_cta') }}</h2>
        <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 text-sm">
            <label class="block sm:col-span-2 lg:col-span-1">
                <span class="text-gray-500 dark:text-gray-400">{{ __('operator.actions.upload') }}</span>
                <input type="file" wire:model="upload"
                    class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-gray-700 dark:file:bg-white/10 dark:file:text-gray-200 dark:text-gray-300" />
                @error('upload') <span class="mt-1 block text-error-600 dark:text-error-400">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="text-gray-500 dark:text-gray-400">{{ __('operator.files.scope') }}</span>
                <select wire:model="uploadScope" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700 dark:text-white">
                    @foreach ($scopes as $scopeKey)
                        <option value="{{ $scopeKey }}">{{ __('operator.files.scopes.'.$scopeKey) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="text-gray-500 dark:text-gray-400">Description</span>
                <input wire:model="uploadDescription" type="text" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700 dark:text-white" />
            </label>
        </div>
        <div class="mt-3">
            <x-ta.button type="submit" size="sm" wire:loading.attr="disabled">{{ __('operator.actions.upload') }}</x-ta.button>
        </div>
    </form>

    <div class="flex flex-wrap items-end gap-3">
        <label class="block min-w-[200px] flex-1 text-sm">
            <span class="sr-only">{{ __('operator.actions.search') }}</span>
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="{{ __('operator.files.search_placeholder') }}"
                class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 dark:border-gray-700 dark:bg-gray-900 dark:text-white" />
        </label>
        <label class="block text-sm">
            <span class="sr-only">{{ __('operator.files.scope') }}</span>
            <select wire:model.live="scope" class="rounded-lg border border-gray-200 bg-white px-3 py-2 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                <option value="">{{ __('operator.files.scope_all') }}</option>
                @foreach ($scopes as $scopeKey)
                    <option value="{{ $scopeKey }}">{{ __('operator.files.scopes.'.$scopeKey) }}</option>
                @endforeach
            </select>
        </label>
    </div>

    @if ($files->isEmpty())
        <div class="py-6">
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('operator.files.empty') }}</p>
            <p class="mt-1 text-xs text-gray-500">Allowed: images, PDF, DOC/DOCX, XLS/XLSX, TXT, CSV, ZIP — not PHP/EXE.</p>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-800">
                        <th scope="col" class="py-2 pr-4 text-left text-xs font-normal text-gray-500">{{ __('operator.files.original_name') }}</th>
                        <th scope="col" class="py-2 pr-4 text-left text-xs font-normal text-gray-500">{{ __('operator.files.scope') }}</th>
                        <th scope="col" class="py-2 pr-4 text-left text-xs font-normal text-gray-500">{{ __('operator.files.size') }}</th>
                        <th scope="col" class="py-2 pr-4 text-left text-xs font-normal text-gray-500">{{ __('operator.files.uploaded_at') }}</th>
                        <th scope="col" class="py-2 text-right text-xs font-normal text-gray-500">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($files as $file)
                        <tr wire:key="file-{{ $file->id }}" class="border-b border-gray-200 dark:border-gray-800">
                            <td class="py-2.5 pr-4">
                                @if ($renamingId === $file->id)
                                    <div class="flex flex-wrap items-center gap-2">
                                        <input wire:model="renameName" type="text" class="rounded-lg border border-gray-200 bg-transparent px-2 py-1 dark:border-gray-700 dark:text-white" />
                                        <button type="button" wire:click="saveRename" class="link-primary text-xs font-medium">{{ __('operator.actions.save') }}</button>
                                        <button type="button" wire:click="cancelRename" class="text-xs text-gray-500">{{ __('operator.actions.cancel') }}</button>
                                    </div>
                                    @error('renameName') <span class="text-xs text-error-600">{{ $message }}</span> @enderror
                                @else
                                    <p class="font-medium text-gray-800 dark:text-white/90">{{ $file->original_name }}</p>
                                    <p class="text-xs text-gray-500">{{ $file->mime }}</p>
                                @endif
                            </td>
                            <td class="py-2.5 pr-4 text-gray-600 dark:text-gray-300">{{ __('operator.files.scopes.'.$file->scope_type) }}</td>
                            <td class="py-2.5 pr-4 tabular-nums text-gray-600 dark:text-gray-300">{{ number_format($file->size / 1024, 1) }} KB</td>
                            <td class="py-2.5 pr-4 text-gray-500">{{ $file->created_at?->diffForHumans() }}</td>
                            <td class="py-2.5 pr-4">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <a href="{{ route('operator.files.download', $file) }}" class="link-neutral text-xs font-medium">{{ __('operator.actions.download') }}</a>
                                    <button type="button" wire:click="startRename({{ $file->id }})" class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('operator.actions.rename') }}</button>
                                    @if ($confirmDeleteId === $file->id)
                                        <button type="button" wire:click="deleteFile" class="text-xs font-medium text-error-600 dark:text-error-400">{{ __('operator.actions.confirm') }}</button>
                                        <button type="button" wire:click="cancelDelete" class="text-xs text-gray-500">{{ __('operator.actions.cancel') }}</button>
                                    @else
                                        <button type="button" wire:click="askDelete({{ $file->id }})" class="text-xs font-medium text-error-600 dark:text-error-400">{{ __('operator.actions.delete') }}</button>
                                    @endif
                                </div>
                                @if ($confirmDeleteId === $file->id)
                                    <p class="mt-1 text-right text-xs text-warning-600 dark:text-warning-400">{{ __('operator.files.confirm_delete') }}</p>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="py-3">
                {{ $files->links() }}
            </div>
        </div>
    @endif
</div>
