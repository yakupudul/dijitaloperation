@php
    $data = $data ?? [];
    $cards = $data['connections'] ?? [];
    $bothBound = $bothBound ?? false;
@endphp

<div class="mox-website-workspace">
    <div class="mox-section-head">
        <div>
            <h3 class="mox-section-title">Connections</h3>
            <p class="mox-section-sub">Data sources powering this Website workspace.</p>
        </div>
    </div>

    <div class="mox-conn-grid">
        @foreach ($cards as $card)
            <section class="mox-conn-card">
                <div class="mox-conn-card__top">
                    <h4>{{ $card['label'] }}</h4>
                    <span class="{{ ($card['connected'] ?? false) ? 'mox-ok' : 'mox-warn' }}">
                        {{ ($card['connected'] ?? false) ? 'Connected' : 'Not connected' }}
                    </span>
                </div>

                @if ($card['connected'] ?? false)
                    <div class="mox-conn-card__name">{{ $card['display_name'] ?? '—' }}</div>
                    <div class="mox-muted">{{ $card['subtitle'] ?? '' }}</div>
                    <div class="mox-conn-card__meta">
                        Last sync:
                        {{ $card['last_sync_human'] ?? 'Never' }}
                        @if (! empty($card['last_status']))
                            · {{ ucfirst((string) $card['last_status']) }}
                        @endif
                    </div>
                @else
                    <div class="mox-muted">{{ $card['subtitle'] ?? 'Not connected' }}</div>
                @endif

                <div class="mox-conn-card__actions">
                    @if (($card['key'] ?? null) === 'ga4')
                        <x-filament::button size="sm" color="gray" wire:click="mountAction('changeGa4')">
                            {{ ($card['connected'] ?? false) ? 'Change' : 'Connect' }}
                        </x-filament::button>
                    @elseif (($card['key'] ?? null) === 'search_console')
                        <x-filament::button size="sm" color="gray" wire:click="mountAction('changeSearchConsole')">
                            {{ ($card['connected'] ?? false) ? 'Change' : 'Connect' }}
                        </x-filament::button>
                    @elseif (($card['key'] ?? null) === 'wordpress')
                        <x-filament::button size="sm" color="gray" wire:click="mountAction('manageWordPress')">
                            {{ ($card['connected'] ?? false) ? 'Edit' : 'Connect' }}
                        </x-filament::button>
                        @if ($card['connected'] ?? false)
                            <x-filament::button size="sm" color="gray" outlined wire:click="mountAction('testWordPress')">
                                Test connection
                            </x-filament::button>
                        @endif
                    @endif
                </div>
            </section>
        @endforeach
    </div>

    @if ($bothBound)
        <p class="mox-muted mox-footnote">GA4 and Search Console are connected. Additional Google sources are managed from agency Integrations when needed.</p>
    @endif

    {{-- Custom schema content replaces EmbeddedTable; keep Filament action modal outlet. --}}
    <x-filament-actions::modals />
</div>
