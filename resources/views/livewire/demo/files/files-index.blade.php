<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">{{ __('operator.files.title') }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('operator.files.subtitle') }}</p>
        </div>
    </div>

    <form wire:submit="uploadFile" class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.files.upload_cta') }}</h2>
        <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 text-sm">
            <label class="block sm:col-span-2 lg:col-span-1">
                <span class="text-gray-500 dark:text-gray-400">{{ __('operator.actions.upload') }}</span>
                <input type="file" wire:model="upload"
                    class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-500 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white dark:text-gray-300" />
                @error('upload') <span class="mt-1 block text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
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
        <div class="rounded-xl bg-white p-10 text-center ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('operator.files.empty') }}</p>
            <p class="mt-3 text-xs text-gray-400">Allowed: images, PDF, DOC/DOCX, XLS/XLSX, TXT, CSV, ZIP — not PHP/EXE.</p>
        </div>
    @else
        <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <th scope="col" class="px-4 py-3 text-left text-xs uppercase text-gray-400">{{ __('operator.files.original_name') }}</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs uppercase text-gray-400">{{ __('operator.files.scope') }}</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs uppercase text-gray-400">{{ __('operator.files.size') }}</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs uppercase text-gray-400">{{ __('operator.files.uploaded_at') }}</th>
                        <th scope="col" class="px-4 py-3 text-right text-xs uppercase text-gray-400">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($files as $file)
                        <tr wire:key="file-{{ $file->id }}" class="border-b border-gray-50 dark:border-gray-800/60">
                            <td class="px-4 py-3">
                                @if ($renamingId === $file->id)
                                    <div class="flex flex-wrap items-center gap-2">
                                        <input wire:model="renameName" type="text" class="rounded-lg border border-gray-200 bg-transparent px-2 py-1 dark:border-gray-700 dark:text-white" />
                                        <button type="button" wire:click="saveRename" class="text-xs font-medium text-brand-600 dark:text-brand-400">{{ __('operator.actions.save') }}</button>
                                        <button type="button" wire:click="cancelRename" class="text-xs text-gray-500">{{ __('operator.actions.cancel') }}</button>
                                    </div>
                                    @error('renameName') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                @else
                                    <p class="font-medium text-gray-800 dark:text-white/90">{{ $file->original_name }}</p>
                                    <p class="text-xs text-gray-500">{{ $file->mime }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ __('operator.files.scopes.'.$file->scope_type) }}</td>
                            <td class="px-4 py-3 tabular-nums text-gray-600 dark:text-gray-300">{{ number_format($file->size / 1024, 1) }} KB</td>
                            <td class="px-4 py-3 text-gray-500">{{ $file->created_at?->diffForHumans() }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <a href="{{ route('demo.files.download', $file) }}" class="text-xs font-medium text-brand-600 dark:text-brand-400">{{ __('operator.actions.download') }}</a>
                                    <button type="button" wire:click="startRename({{ $file->id }})" class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('operator.actions.rename') }}</button>
                                    @if ($confirmDeleteId === $file->id)
                                        <button type="button" wire:click="deleteFile" class="text-xs font-medium text-red-600 dark:text-red-400">{{ __('operator.actions.confirm') }}</button>
                                        <button type="button" wire:click="cancelDelete" class="text-xs text-gray-500">{{ __('operator.actions.cancel') }}</button>
                                    @else
                                        <button type="button" wire:click="askDelete({{ $file->id }})" class="text-xs font-medium text-red-600 dark:text-red-400">{{ __('operator.actions.delete') }}</button>
                                    @endif
                                </div>
                                @if ($confirmDeleteId === $file->id)
                                    <p class="mt-1 text-right text-xs text-amber-600 dark:text-amber-400">{{ __('operator.files.confirm_delete') }}</p>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="border-t border-gray-100 px-4 py-3 dark:border-gray-800">
                {{ $files->links() }}
            </div>
        </div>
    @endif
</div>
