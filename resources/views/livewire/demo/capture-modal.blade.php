<div>
    @if ($open)
        <div class="fixed inset-0 z-[100000] flex items-start justify-center bg-black/40 p-4 pt-16" wire:click.self="close">
            <div class="w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl dark:bg-gray-900" role="dialog" aria-modal="true" aria-labelledby="capture-title">
                <div class="flex items-center justify-between gap-2">
                    <h2 id="capture-title" class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ __('operator.capture.title') }}</h2>
                    <button type="button" wire:click="close" class="text-gray-400 hover:text-gray-600" aria-label="{{ __('operator.capture.close') }}">×</button>
                </div>

                <div class="mt-4 flex flex-wrap gap-2" role="tablist">
                    @foreach (['client_request', 'task', 'opportunity_hypothesis', 'note'] as $type)
                        <button type="button" wire:click="setCaptureType('{{ $type }}')"
                            @class([
                                'rounded-lg px-3 py-1.5 text-xs font-medium',
                                'bg-brand-500 text-white' => $captureType === $type,
                                'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' => $captureType !== $type,
                            ])>{{ __('operator.capture.types.'.$type) }}</button>
                    @endforeach
                </div>

                <form wire:submit="save" class="mt-4 space-y-3">
                    <label class="block text-sm">
                        <span class="text-gray-500">{{ __('operator.capture.fields.title') }}</span>
                        <input wire:model="title" type="text" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700" required />
                        @error('title') <span class="text-xs text-error-600">{{ $message }}</span> @enderror
                    </label>

                    <label class="block text-sm">
                        <span class="text-gray-500">{{ __('operator.capture.fields.description') }}</span>
                        <textarea wire:model="description" rows="3" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700"></textarea>
                    </label>

                    @if ($captureType === 'client_request')
                        <label class="block text-sm">
                            <span class="text-gray-500">{{ __('operator.capture.fields.source') }}</span>
                            <select wire:model="source" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700">
                                <option value="meeting">{{ __('operator.capture.sources.meeting') }}</option>
                                <option value="email">{{ __('operator.capture.sources.email') }}</option>
                                <option value="phone">{{ __('operator.capture.sources.phone') }}</option>
                            </select>
                        </label>
                    @endif

                    @if (in_array($captureType, ['client_request', 'task'], true))
                        <label class="block text-sm">
                            <span class="text-gray-500">{{ __('operator.capture.fields.priority') }}</span>
                            <select wire:model="priority" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700">
                                <option value="low">{{ __('operator.capture.priorities.low') }}</option>
                                <option value="medium">{{ __('operator.capture.priorities.medium') }}</option>
                                <option value="high">{{ __('operator.capture.priorities.high') }}</option>
                            </select>
                        </label>
                    @endif

                    @if ($captureType === 'note')
                        <label class="block text-sm">
                            <span class="text-gray-500">{{ __('operator.capture.fields.scope') }}</span>
                            <input wire:model="note_scope" type="text" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700" />
                        </label>
                    @endif

                    <div class="flex justify-end gap-2 pt-2">
                        <x-ta.button type="button" wire:click="close" size="sm" variant="outline">{{ __('operator.capture.cancel') }}</x-ta.button>
                        <x-ta.button type="submit" size="sm">{{ __('operator.capture.save') }}</x-ta.button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
