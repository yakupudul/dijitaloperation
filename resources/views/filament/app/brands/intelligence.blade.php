@php
    /** @var \App\Support\BrandIntelligence\BrandIntelligenceSnapshot $snapshot */
    $snapshot = $snapshot ?? null;
@endphp

@if ($snapshot instanceof \App\Support\BrandIntelligence\BrandIntelligenceSnapshot)
    <div class="mox-website-workspace">
        <div class="mox-section-head">
            <div>
                <h3 class="mox-section-title">Business context</h3>
                <p class="mox-section-sub">
                    Operator-owned factual Brand intelligence. Unknown fields stay empty — nothing is invented.
                </p>
            </div>
            <div class="mox-meta-pill">{{ $snapshot->completeness['label'] }}</div>
        </div>

        <div class="mox-conn-card__actions" style="margin-bottom: 1rem;">
            <x-filament::button size="sm" color="primary" wire:click="mountAction('editIntelligence')">
                {{ $snapshot->hasContext ? 'Edit business context' : 'Add business context' }}
            </x-filament::button>
            @if ($snapshot->hasContext)
                <x-filament::button size="sm" color="gray" outlined wire:click="mountAction('clearIntelligence')">
                    Clear context
                </x-filament::button>
            @endif
        </div>

        @if (! $snapshot->hasContext)
            <div class="mox-empty">
                No Brand intelligence context yet. Add factual business context so future analysis understands offerings, markets, goals, and constraints.
            </div>
        @else
            <div class="mox-grid-2">
                <section class="mox-panel">
                    <div class="mox-panel__head"><h4>Business</h4></div>
                    <div class="mox-stack">
                        <div>
                            <div class="mox-muted">Summary</div>
                            <div>{{ $snapshot->businessSummary ?: '—' }}</div>
                        </div>
                        <div>
                            <div class="mox-muted">Business model</div>
                            <div>{{ $snapshot->businessModelLabel ?: '—' }}</div>
                        </div>
                    </div>
                </section>

                <section class="mox-panel">
                    <div class="mox-panel__head"><h4>Offerings</h4></div>
                    @if ($snapshot->offerings === [])
                        <div class="mox-empty mox-empty--compact">No offerings recorded.</div>
                    @else
                        <ul class="mox-list">
                            @foreach ($snapshot->offerings as $offering)
                                <li>
                                    <div class="mox-list__row">
                                        <strong>{{ $offering['name'] }}</strong>
                                        @if (in_array($offering['name'], $snapshot->priorityOfferings, true))
                                            <span class="mox-muted">Priority</span>
                                        @endif
                                    </div>
                                    @if (! empty($offering['description']))
                                        <div class="mox-muted mox-list__hint">{{ $offering['description'] }}</div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if ($snapshot->priorityOfferings !== [])
                        <div class="mox-panel__head mox-panel__head--spaced"><span class="mox-muted">Priority order</span></div>
                        <ol class="mox-list">
                            @foreach ($snapshot->priorityOfferings as $name)
                                <li><strong>{{ $name }}</strong></li>
                            @endforeach
                        </ol>
                    @endif
                </section>
            </div>

            <div class="mox-grid-2">
                <section class="mox-panel">
                    <div class="mox-panel__head"><h4>Audiences & markets</h4></div>
                    <div class="mox-stack">
                        <div>
                            <div class="mox-muted">Target audiences</div>
                            @if ($snapshot->targetAudiences === [])
                                <div>—</div>
                            @else
                                <ul class="mox-list">
                                    @foreach ($snapshot->targetAudiences as $row)
                                        <li>
                                            <strong>{{ $row['name'] }}</strong>
                                            @if (! empty($row['note']))
                                                <div class="mox-muted mox-list__hint">{{ $row['note'] }}</div>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                        <div>
                            <div class="mox-muted">Target markets</div>
                            <p class="mox-section-sub">Brand business markets — independent from Website SEO market.</p>
                            @if ($snapshot->targetMarkets === [])
                                <div>—</div>
                            @else
                                <div>
                                    @foreach ($snapshot->targetMarkets as $row)
                                        <span class="mox-meta-pill">{{ $row['name'] }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                </section>

                <section class="mox-panel">
                    <div class="mox-panel__head"><h4>Goals</h4></div>
                    <div class="mox-stack">
                        <div>
                            <div class="mox-muted">Business goals</div>
                            @if ($snapshot->businessGoals === [])
                                <div>—</div>
                            @else
                                <ul class="mox-list">
                                    @foreach ($snapshot->businessGoals as $row)
                                        <li><strong>{{ $row['goal'] }}</strong></li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                        <div>
                            <div class="mox-muted">Conversion goals</div>
                            <p class="mox-section-sub">Business outcomes — not automatic GA4 event mappings.</p>
                            @if ($snapshot->conversionGoals === [])
                                <div>—</div>
                            @else
                                <ul class="mox-list">
                                    @foreach ($snapshot->conversionGoals as $row)
                                        <li>
                                            <div class="mox-list__row">
                                                <strong>{{ $row['type_label'] }}</strong>
                                                @if (! empty($row['label']))
                                                    <span class="mox-muted">{{ $row['label'] }}</span>
                                                @endif
                                            </div>
                                            @if (! empty($row['note']))
                                                <div class="mox-muted mox-list__hint">{{ $row['note'] }}</div>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </div>
                </section>
            </div>

            <div class="mox-grid-2">
                <section class="mox-panel">
                    <div class="mox-panel__head"><h4>Competition</h4></div>
                    @if ($snapshot->competitors === [])
                        <div class="mox-empty mox-empty--compact">No competitors recorded.</div>
                    @else
                        <ul class="mox-list">
                            @foreach ($snapshot->competitors as $row)
                                <li>
                                    <div class="mox-list__row">
                                        <strong>{{ $row['name'] }}</strong>
                                        @if (! empty($row['url']))
                                            <a class="mox-link" href="{{ $row['url'] }}" target="_blank" rel="noopener noreferrer">{{ $row['url'] }}</a>
                                        @endif
                                    </div>
                                    @if (! empty($row['note']))
                                        <div class="mox-muted mox-list__hint">{{ $row['note'] }}</div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                <section class="mox-panel">
                    <div class="mox-panel__head"><h4>Positioning & constraints</h4></div>
                    <div class="mox-stack">
                        <div>
                            <div class="mox-muted">Positioning</div>
                            <div>{{ $snapshot->positioning ?: '—' }}</div>
                        </div>
                        <div>
                            <div class="mox-muted">Differentiators</div>
                            @if ($snapshot->differentiators === [])
                                <div>—</div>
                            @else
                                <ul class="mox-list">
                                    @foreach ($snapshot->differentiators as $item)
                                        <li>{{ $item }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                        <div>
                            <div class="mox-muted">Important constraints</div>
                            <div>{{ $snapshot->importantConstraints ?: '—' }}</div>
                        </div>
                    </div>
                </section>
            </div>
        @endif

        <x-filament-actions::modals />
    </div>
@endif
