@php
    /** @var array{summary: array<string,int>, groups: list<array{key: string, label: string, cards: list<array<string, mixed>>}>} $hub */
    $hub = $hub ?? ['summary' => [], 'groups' => []];
    $summary = $hub['summary'] ?? [];
    $groups = $hub['groups'] ?? [];
@endphp

<div class="mox-integrations-hub" wire:key="integrations-hub">
    <div class="mox-section-head">
        <div>
            <h2 class="mox-section-title">Integrations</h2>
            <p class="mox-section-sub">Connect and manage the services MoxDOP uses for data and intelligence.</p>
        </div>
        @if (! empty($summary))
            <div class="mox-meta-pill" data-testid="integrations-summary">
                {{ (int) ($summary['total'] ?? 0) }} integrations
                · {{ (int) ($summary['connected'] ?? 0) }} connected
                @if ((int) ($summary['needs_attention'] ?? 0) > 0)
                    · {{ (int) $summary['needs_attention'] }} needs attention
                @endif
            </div>
        @endif
    </div>

    @foreach ($groups as $group)
        <section class="mox-integrations-group" wire:key="group-{{ $group['key'] }}">
            <h3 class="mox-integrations-group__title">{{ $group['label'] }}</h3>

            <div class="mox-integrations-grid">
                @foreach ($group['cards'] as $card)
                    <article
                        class="mox-integration-card"
                        data-provider="{{ $card['provider'] }}"
                        data-status="{{ $card['status'] }}"
                        data-testid="integration-card-{{ $card['provider'] }}"
                        wire:key="card-{{ $card['provider'] }}"
                    >
                        <div class="mox-integration-card__top">
                            <div class="mox-integration-card__identity">
                                <span class="mox-integration-card__mark mox-integration-card__mark--{{ $card['icon'] }}" aria-hidden="true">
                                    {{ strtoupper(substr($card['label'], 0, 1)) }}
                                </span>
                                <div>
                                    <h4 class="mox-integration-card__name">{{ $card['label'] }}</h4>
                                    <p class="mox-integration-card__purpose">{{ $card['description'] }}</p>
                                </div>
                            </div>
                            <span class="{{ $card['status_css'] }}" data-testid="status-{{ $card['provider'] }}">
                                ● {{ $card['status_label'] }}
                            </span>
                        </div>

                        @if (! empty($card['summary_lines']))
                            <ul class="mox-integration-card__summary">
                                @foreach ($card['summary_lines'] as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                        @endif

                        <div class="mox-integration-card__footer">
                            <span class="mox-muted">{{ $card['last_checked_label'] ?? 'Not checked yet' }}</span>

                            @if (($card['action'] ?? '') === 'manage' && filled($card['manage_url'] ?? null))
                                <x-filament::button
                                    tag="a"
                                    size="sm"
                                    color="gray"
                                    :href="$card['manage_url']"
                                >
                                    Manage
                                </x-filament::button>
                            @else
                                <x-filament::button
                                    size="sm"
                                    color="primary"
                                    wire:click="setupProvider('{{ $card['provider'] }}')"
                                    wire:key="setup-{{ $card['provider'] }}"
                                >
                                    Set up
                                </x-filament::button>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endforeach

    <div class="mox-collection-monitor-embed" wire:key="collection-monitoring">
        @livewire(\App\Livewire\Collection\MonitoringPanel::class)
    </div>

    <x-filament-actions::modals />
</div>
