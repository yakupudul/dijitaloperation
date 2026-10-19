@if ($message !== '')
    <p role="status" @class(['rounded-lg p-3 text-sm', 'bg-gray-50 text-gray-800 dark:bg-gray-950 dark:text-gray-200' => $messageTone !== 'error', 'bg-error-50 text-error-800 dark:bg-error-950 dark:text-error-200' => $messageTone === 'error'])>{{ $message }}</p>
@endif
