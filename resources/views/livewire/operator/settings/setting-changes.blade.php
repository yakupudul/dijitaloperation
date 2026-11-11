@php
    $levels = \App\Support\WriteLevels::LABELS;
    // A value as a person reads it: a switch as Açık / Kapalı, a level by its name, a long text by its first words.
    $show = function (mixed $value, string $target) use ($levels): string {
        if (str_starts_with($target, 'job:')) {
            return $value ? 'Açık' : 'Kapalı';
        }
        if (str_starts_with($target, 'level:')) {
            return is_string($value) ? ($levels[$value] ?? $value) : 'Varsayılan';
        }
        if (is_bool($value) || $value === 0 || $value === 1 || $value === '0' || $value === '1') {
            return $value ? 'Açık' : 'Kapalı';
        }
        if ($value === null || $value === '') {
            return 'boş';
        }
        $text = trim(preg_replace('/\s+/u', ' ', is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE)));

        return mb_strlen($text) > 90 ? mb_substr($text, 0, 90).'…' : $text;
    };
@endphp
<div class="space-y-10 dark:text-gray-200" data-setting-changes>
    <header>
        <h1 class="page-title">Değişiklik kaydı</h1>
        <p class="page-sub">Hangi ayar ne zaman, kim tarafından değişti. "Geri al" eski değeri yerine koyar.</p>
    </header>

    @if ($message !== '')
        <p class="card -mt-4 px-4 py-3 text-sm text-gray-700 dark:text-gray-300" role="status">{{ $message }}</p>
    @endif

    <ul class="card -mt-4">
        @forelse ($changes as $change)
            <li class="card-row flex-wrap items-start gap-y-2 sm:flex-nowrap sm:items-center" wire:key="change-{{ $change->id }}" data-change-row>
                <div class="min-w-0 flex-1 basis-64">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $change->label }}</p>
                    <p class="mt-0.5 text-[13px] text-gray-600 dark:text-gray-300">
                        @if ($change->secret)
                            Değiştirildi (değer gösterilmez)
                        @else
                            <span class="text-gray-400 line-through decoration-gray-300">{{ $show($change->before['v'] ?? null, $change->target) }}</span>
                            <span class="mx-1 text-gray-400" aria-hidden="true">›</span>
                            <span>{{ $show($change->after['v'] ?? null, $change->target) }}</span>
                        @endif
                    </p>
                    <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $change->created_at->timezone('Europe/Istanbul')->locale('tr')->translatedFormat('j F H:i') }}, {{ $change->user?->name ?? 'sistem' }}</p>
                </div>
                @if ($change->undone_at !== null)
                    <span class="text-[13px] text-gray-400">Geri alındı</span>
                @elseif (\App\Services\Operations\SettingChanges::undoable($change))
                    <button type="button" wire:click="undo({{ $change->id }})" wire:loading.attr="disabled" class="btn-quiet w-full sm:w-auto" data-change-undo>Geri al</button>
                @endif
            </li>
        @empty
            <li><x-ta.empty-state size="sm" title="Henüz kayıt yok" message="Bir ayar değiştiğinde burada görünür." /></li>
        @endforelse
    </ul>
</div>
