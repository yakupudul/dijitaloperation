@php
    use App\Services\Ai\AiRouteResolver;
    use App\Support\Ai\AiProviderCatalog;

    $reasonLabels = [
        'unsupported_provider' => 'desteklenmeyen sağlayıcı',
        'integration_disabled' => 'entegrasyon kapalı',
        'credential_missing' => 'API anahtarı yok (Entegrasyonlar → AI sağlayıcıları)',
        'health_auth_failed' => 'son bağlantı testi başarısız',
        'client_data_not_allowed' => 'bu iş müşteri verisi içeriyor; ücretsiz katmanda çalışmaz',
        'budget_exhausted' => 'aylık AI bütçesi doldu (yalnızca ücretsiz modeller çalışır)',
    ];
    $modelSuggestions = [
        'anthropic' => ['claude-sonnet-5', 'claude-haiku-4-5'],
        'openai' => ['gpt-5-mini', 'gpt-5'],
        'gemini' => ['gemini-3.6-flash', 'gemini-3.5-flash-lite'],
        'groq' => ['llama-3.3-70b-versatile'],
        'openrouter' => ['meta-llama/llama-3.3-70b-instruct:free'],
    ];
    $selectedDescriptor = collect($routes)->firstWhere('key', $selectedRoute);
    $selectedClientData = $selectedDescriptor ? AiRouteResolver::containsClientData($selectedDescriptor) : true;
    $percent = $usage['budget'] > 0 ? min(100, (int) round($usage['spend'] / $usage['budget'] * 100)) : 0;
@endphp
<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('operator.settings', ['section' => 'ai']) }}" wire:navigate class="text-sm text-gray-500 hover:text-brand-600">← {{ __('operator.nav.settings') }}</a>
            <h1 class="mt-2 text-2xl font-bold text-gray-800 dark:text-white/90">{{ __('operator.settings.ai.control_plane_title') }}</h1>
            <p class="mt-1 text-sm text-gray-500">Her AI işi için sağlayıcı ve modeli seç; ilk uygun adım çalışır, diğerleri yedektir.</p>
        </div>
    </div>

    {{-- Monthly spend and budget --}}
    <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs font-medium text-gray-500">Bu ayki AI harcaması</p>
                <p class="mt-1 text-2xl font-bold text-gray-800 dark:text-white/90">${{ number_format($usage['spend'], 2) }} <span class="text-sm font-medium text-gray-400">/ ${{ number_format($usage['budget'], 2) }}</span></p>
                <div class="mt-2 h-2 w-64 max-w-full rounded-full bg-gray-100 dark:bg-gray-800"><div @class(['h-2 rounded-full', 'bg-success-500' => $percent < 80, 'bg-warning-500' => $percent >= 80 && $percent < 100, 'bg-error-500' => $percent >= 100]) style="width: {{ $percent }}%"></div></div>
                <p class="mt-2 text-xs text-gray-500">{{ number_format($usage['calls']) }} çağrı @if ($usage['unknown_cost_calls'] > 0)· {{ $usage['unknown_cost_calls'] }} çağrının fiyatı bilinmiyor (config/moxdop-ai-pricing.php) @endif</p>
                @if ($usage['exhausted'])
                    <p class="mt-2 text-sm font-medium text-error-600">Bütçe doldu: ay sonuna kadar yalnızca ücretsiz modeller çalışır; planlar kural sonuçlarıyla devam eder.</p>
                @endif
            </div>
            <form wire:submit="saveBudget" class="flex items-end gap-2">
                <label class="text-sm">
                    <span class="block text-xs text-gray-500">Aylık bütçe (USD, 0 = sınırsız)</span>
                    <input wire:model="monthlyBudget" type="number" step="1" min="0" class="mt-1 w-32 rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700" />
                </label>
                <x-ta.button type="submit" size="sm" variant="outline">Kaydet</x-ta.button>
            </form>
        </div>
        @error('monthlyBudget')<p class="mt-2 text-sm text-error-600">{{ $message }}</p>@enderror
    </section>

    {{-- Routes --}}
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($routes as $route)
            @php $spend = $routeSpend->get($route['key']); $clientData = AiRouteResolver::containsClientData($route); @endphp
            <button type="button" wire:click="selectRoute('{{ $route['key'] }}')"
                @class([
                    'rounded-xl border p-4 text-left transition',
                    'border-brand-500 bg-brand-50 dark:bg-brand-500/10' => $selectedRoute === $route['key'],
                    'border-gray-200 dark:border-gray-700 hover:border-gray-300' => $selectedRoute !== $route['key'],
                ])>
                <p class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ $route['name'] }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ $route['key'] }}</p>
                <p class="mt-2 flex flex-wrap gap-2 text-xs">
                    <span @class(['rounded-full px-2 py-0.5', 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400' => $clientData, 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400' => ! $clientData])>{{ $clientData ? 'Müşteri verisi' : 'Herkese açık veri · ücretsiz model olur' }}</span>
                    <span class="text-gray-500">Bu ay: {{ $spend ? $spend['calls'].' çağrı · $'.number_format($spend['cost'], 2) : '—' }}</span>
                </p>
            </button>
        @endforeach
    </div>

    @if ($selectedRoute !== '')
        <form wire:submit="save" class="space-y-4 rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div>
                <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ $selectedDescriptor['name'] ?? $selectedRoute }} · {{ __('operator.settings.ai.provider_order') }}</h2>
                <p class="mt-1 text-sm text-gray-500">{{ $selectedDescriptor['description'] ?? '' }}</p>
                @if ($selectedClientData)
                    <p class="mt-1 text-xs text-warning-700">Bu iş müşteri verisi içeriyor: Groq ve OpenRouter gibi ücretsiz/üçüncü taraf katmanlar seçilemez.</p>
                @endif
            </div>
            @foreach ($steps as $index => $step)
                @php $resolvedStep = $resolved?->steps[$index] ?? null; @endphp
                <div class="grid gap-3 sm:grid-cols-[1fr_1fr_auto]" wire:key="step-{{ $index }}">
                    <label class="block text-sm">
                        <span class="text-gray-500">{{ $index === 0 ? 'Birincil' : 'Yedek '.$index }} · {{ __('operator.settings.ai.provider') }}</span>
                        <select wire:model.live="steps.{{ $index }}.provider" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700">
                            @foreach ($providers as $provider)
                                <option value="{{ $provider }}" @disabled($selectedClientData && AiProviderCatalog::isFreeTierDataRisk($provider))>{{ AiProviderCatalog::label($provider) }}@if ($selectedClientData && AiProviderCatalog::isFreeTierDataRisk($provider)) (müşteri verisi için kapalı)@endif</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm">
                        <span class="text-gray-500">{{ __('operator.settings.ai.model') }}</span>
                        <input wire:model="steps.{{ $index }}.model" type="text" list="models-{{ $index }}" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700" />
                        <datalist id="models-{{ $index }}">
                            @foreach ($modelSuggestions[$step['provider']] ?? [] as $suggestion)
                                <option value="{{ $suggestion }}">{{ AiProviderCatalog::humanModelLabel($suggestion) }}</option>
                            @endforeach
                        </datalist>
                    </label>
                    <div class="flex items-end">
                        <x-ta.button type="button" wire:click="removeStep({{ $index }})" size="sm" variant="outline">{{ __('operator.actions.remove') }}</x-ta.button>
                    </div>
                    @if ($resolvedStep && ($resolvedStep['provider'] ?? null) === $step['provider'])
                        <p @class(['text-xs sm:col-span-3', 'text-success-600' => $resolvedStep['eligible'], 'text-warning-700' => ! $resolvedStep['eligible']])>
                            {{ $resolvedStep['eligible'] ? 'Hazır: bu adım çalışabilir.' : 'Çalışmaz: '.($reasonLabels[$resolvedStep['reason']] ?? $resolvedStep['reason']) }}
                        </p>
                    @endif
                </div>
            @endforeach
            @error('steps')<p class="text-sm text-error-600">{{ $message }}</p>@enderror
            <div class="flex flex-wrap gap-2">
                <x-ta.button type="button" wire:click="addStep" size="sm" variant="outline">{{ __('operator.settings.ai.add_provider') }}</x-ta.button>
                <x-ta.button type="submit" size="sm">{{ __('operator.actions.save') }}</x-ta.button>
            </div>
            <p class="text-xs text-gray-400">Öneri: analiz ve brief işleri için Claude Sonnet 5, eşleştirme/sınıflandırma için Claude Haiku 4.5, herkese açık veri işleri için ücretsiz model; yedek olarak Gemini Flash.</p>
        </form>
    @endif
</div>
