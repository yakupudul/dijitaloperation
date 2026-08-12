@if (! empty($flash))
    <div class="mb-4">
        <x-ta.alert
            :variant="($flash['tone'] ?? 'info') === 'success' ? 'success' : (($flash['tone'] ?? '') === 'error' ? 'error' : 'info')"
            :message="$flash['message'] ?? ''"
        />
    </div>
@endif
