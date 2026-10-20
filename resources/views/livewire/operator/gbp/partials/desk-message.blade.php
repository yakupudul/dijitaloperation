@if ($message !== '')
    <p role="status" @class(['rounded-lg border px-3.5 py-2.5 text-sm', 'tone-info border-gray-200 dark:border-gray-800' => $messageTone !== 'error', 'tone-critical border-error-200 dark:border-error-500/20' => $messageTone === 'error'])>{{ $message }}</p>
@endif
