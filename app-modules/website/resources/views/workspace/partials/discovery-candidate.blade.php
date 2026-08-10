@php
    /** @var \App\Models\DiscoveryCandidate $candidate */
    $support = is_array($candidate->support_json) ? $candidate->support_json : [];
    $source = $support['source_url'] ?? $support['provider'] ?? null;
    $supportLabel = match ($candidate->support_label) {
        'strong' => 'Strong support',
        'weak' => 'Weak support',
        default => 'Moderate support',
    };
    $isEditing = (int) ($editingCandidateId ?? 0) === (int) $candidate->id;
@endphp

<section class="mox-panel">
    <div class="mox-panel__head">
        <h4>
            @if ($candidate->candidate_kind === \App\Models\DiscoveryCandidate::KIND_INFERENCE)
                <span class="mox-badge-ai">INFERENCE</span>
            @else
                <span class="mox-badge">FACT</span>
            @endif
            {{ ucfirst(str_replace('_', ' ', $candidate->candidate_type)) }}
            → {{ $candidate->target_field }}
        </h4>
        <span class="mox-muted">{{ $supportLabel }} · {{ strtoupper($candidate->status) }}</span>
    </div>

    <p><strong>Proposed</strong><br>{{ $candidate->proposed_value }}</p>

    @if (! empty($support['conflict_with_existing']))
        <p class="mox-muted"><strong>Conflict</strong> with existing Brand Context: {{ $support['conflict_with_existing'] }}</p>
    @endif

    @if ($source)
        <p class="mox-muted"><strong>Source</strong> · {{ $source }}</p>
    @endif

    @if (! empty($support['query_note']))
        <p class="mox-muted">{{ $support['query_note'] }}</p>
    @endif

    @if ($candidate->status === \App\Models\DiscoveryCandidate::STATUS_ACCEPTED)
        <p class="mox-muted">
            Accepted
            @if ($candidate->was_edited)
                (edited)
            @endif
            @if ($candidate->reviewed_at)
                · {{ $candidate->reviewed_at->diffForHumans() }}
            @endif
            @if ($candidate->accepted_value && $candidate->was_edited)
                · Stored value: {{ $candidate->accepted_value }}
            @endif
        </p>
    @elseif ($candidate->status === \App\Models\DiscoveryCandidate::STATUS_IGNORED)
        <p class="mox-muted">Ignored · Brand Context unchanged</p>
    @else
        @if ($isEditing)
            <div class="mox-stack" style="gap: .75rem;">
                <textarea
                    class="fi-input block w-full"
                    rows="3"
                    wire:model="editingValue"
                >{{ $editingValue }}</textarea>
                <div class="mox-inline-actions" style="display:flex; gap:.5rem; flex-wrap:wrap;">
                    <button type="button" class="mox-btn mox-btn--secondary" wire:click="saveEditAcceptCandidate({{ (int) $candidate->id }})">
                        Save &amp; Accept
                    </button>
                    <button type="button" class="mox-btn" wire:click="cancelEditCandidate">
                        Cancel
                    </button>
                </div>
            </div>
        @else
            <div class="mox-inline-actions" style="display:flex; gap:.5rem; flex-wrap:wrap;">
                <button type="button" class="mox-btn mox-btn--secondary" wire:click="acceptCandidate({{ (int) $candidate->id }})">
                    Accept
                </button>
                <button type="button" class="mox-btn" wire:click="beginEditCandidate({{ (int) $candidate->id }})">
                    Edit &amp; Accept
                </button>
                <button type="button" class="mox-btn" wire:click="ignoreCandidate({{ (int) $candidate->id }})">
                    Ignore
                </button>
            </div>
        @endif
    @endif
</section>
