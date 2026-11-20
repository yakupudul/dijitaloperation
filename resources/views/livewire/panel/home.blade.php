@php
    use App\Panel\Support\PhoneKey;
    $tz = 'Europe/Istanbul';
@endphp
<div class="space-y-10">
    <header>
        <h1 class="page-title">Ana sayfa</h1>
        <p class="page-sub">{{ now($tz)->translatedFormat('j F Y, l') }}</p>
    </header>

    <section class="metric-strip -mt-4 grid-cols-2 lg:grid-cols-4" data-panel-numbers>
        @foreach ($numbers as [$label, $value, $stage])
            <a @if ($canOpenLeads) href="{{ route('panel.leads', array_filter(['asama' => $stage])) }}" wire:navigate @endif class="block bg-white px-5 py-4 hover:bg-gray-50">
                <span class="block text-[13px] text-gray-500">{{ $label }}</span>
                <span class="mt-1.5 block text-[30px] leading-none font-semibold tracking-[-0.03em] tabular-nums text-gray-900">{{ $value }}</span>
            </a>
        @endforeach
    </section>

    <div class="grid gap-10 xl:grid-cols-2">
        <section data-panel-reminders>
            <div class="section-head">
                <h2 class="section-title">Bugün yapılacaklar</h2>
                <span class="section-count">{{ $reminders->count() }}</span>
                @if ($later > 0)<span class="ml-auto text-[13px] text-gray-500">Sonraki günlerde {{ $later }} hatırlatma daha var</span>@endif
            </div>
            <div class="card">
                @forelse ($reminders as $reminder)
                    @php($late = $reminder->due_at->lt(now($tz)->startOfDay()))
                    <div class="card-row" wire:key="reminder-{{ $reminder->id }}">
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('panel.person', ['person' => $reminder->person_id, 'sekme' => 'reminders']) }}" wire:navigate class="block truncate text-sm font-medium text-gray-900 hover:underline">{{ $reminder->title }}</a>
                            <p class="truncate text-[13px] text-gray-500">
                                {{ $reminder->person?->name }}, <span @class(['text-error-700' => $late])>{{ $late ? $reminder->due_at->timezone($tz)->translatedFormat('j F').' gününden kaldı' : $reminder->due_at->timezone($tz)->format('H:i') }}</span>@if ($reminder->user && $reminder->panel_user_id !== auth('client')->id()), {{ $reminder->user->name }}@endif
                            </p>
                        </div>
                        @if ($canClose)
                            <button type="button" wire:click="done({{ $reminder->id }})" class="btn">Yapıldı</button>
                        @endif
                    </div>
                @empty
                    <x-ta.empty-state size="sm" title="Bugün için hatırlatma yok." message="Bir adayın sayfasından “Hatırlatma kur” ile ekleyebilirsiniz." />
                @endforelse
            </div>
        </section>

        <section data-panel-waiting>
            <div class="section-head">
                <h2 class="section-title">Aranmayı bekleyenler</h2>
                @if ($canOpenLeads && $waiting->isNotEmpty())<a href="{{ route('panel.leads', ['asama' => 'new']) }}" wire:navigate class="link-neutral ml-auto text-[13px]">Hepsini gör</a>@endif
            </div>
            <div class="card">
                @forelse ($waiting as $person)
                    <a href="{{ route('panel.person', $person->id) }}" wire:navigate class="card-row hover:bg-gray-50" wire:key="waiting-{{ $person->id }}">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-gray-900">{{ $person->name }}</span>
                            <span class="block truncate text-[13px] text-gray-500">{{ collect([\App\Panel\Support\PanelSources::label($person->source), $person->interest])->filter()->implode(', ') }}</span>
                        </span>
                        <span class="shrink-0 text-right text-[13px] tabular-nums text-gray-500">
                            <span class="block text-gray-900">{{ $showPhone ? $person->phone : PhoneKey::mask($person->phone) }}</span>
                            {{ $person->created_at->timezone($tz)->diffForHumans() }}
                        </span>
                    </a>
                @empty
                    <x-ta.empty-state size="sm" title="Aranmayı bekleyen yok." />
                @endforelse
            </div>
        </section>
    </div>

    @if ($appointments->isNotEmpty())
        <section data-panel-appointments>
            <div class="section-head"><h2 class="section-title">Yaklaşan randevular</h2></div>
            <div class="card">
                @foreach ($appointments as $person)
                    <a href="{{ route('panel.person', $person->id) }}" wire:navigate class="card-row hover:bg-gray-50" wire:key="appointment-{{ $person->id }}">
                        <span class="w-36 shrink-0 text-sm tabular-nums text-gray-900">{{ $person->appointment_at->timezone($tz)->translatedFormat('j F, H:i') }}</span>
                        <span class="min-w-0 flex-1 truncate text-sm font-medium text-gray-900">{{ $person->name }}</span>
                        <span class="hidden truncate text-[13px] text-gray-500 sm:block">{{ $person->interest }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
</div>
