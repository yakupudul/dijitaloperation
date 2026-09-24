<div class="space-y-4">
    <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Dış denetim</h2>
        <p class="mt-1 max-w-3xl text-xs text-gray-500">Hesap erişimi olmadan dışarıdan görünen durum: web sitesi kontrolleri (ücretsiz) ve isteğe bağlı olarak bir hizmet aramasında Google Haritalar'da nerede çıktığı ve ilk 3'teki rakipler (DataForSEO, tek sorgu). Satış görüşmesi için yazdırılabilir.</p>
        <div class="mt-3 flex flex-wrap items-end gap-2">
            <label class="text-sm"><span class="text-xs text-gray-500">Harita araması (isteğe bağlı)</span><input type="text" wire:model="auditKeyword" placeholder="diş kliniği kadıköy" class="mt-1 w-64 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900"></label>
            <x-ta.button type="button" wire:click="runAudit" size="sm">Denetle</x-ta.button>
        </div>
        @if ($auditError !== '')<p class="mt-2 text-sm text-rose-700">{{ $auditError }}</p>@endif
    </div>

    @forelse ($audits as $audit)
        @php
            $website = (array) json_decode((string) $audit->website, true);
            $maps = (array) json_decode((string) $audit->maps, true);
        @endphp
        <div wire:key="audit-{{ $audit->id }}" class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" @if ($audit->status === 'running') wire:poll.15s @endif>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <p class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ \Illuminate\Support\Carbon::parse($audit->created_at)->format('d.m.Y H:i') }}@if ($audit->score !== null) · site puanı %{{ $audit->score }}@endif</p>
                <a href="{{ route('operator.prospect.audit.print', ['prospectId' => $prospectId, 'auditId' => $audit->id]) }}" target="_blank" class="text-xs text-brand-600 hover:underline">Yazdırılabilir rapor →</a>
            </div>
            @if ($website !== [])
                @if (! ($website['reachable'] ?? false))
                    <p class="mt-2 text-sm text-rose-700">Site açılmadı ({{ $website['error'] ?? '?' }}).</p>
                @else
                    <ul class="mt-2 grid gap-1 text-sm sm:grid-cols-2">
                        @foreach (\App\Services\Intel\ProspectAuditService::CHECKS as $key => [$label, $advice])
                            <li class="flex gap-2"><span @class(['text-emerald-600' => $website['checks'][$key] ?? false, 'text-rose-600' => ! ($website['checks'][$key] ?? false)])>{{ ($website['checks'][$key] ?? false) ? '✓' : '✗' }}</span>{{ $label }}</li>
                        @endforeach
                    </ul>
                @endif
            @endif
            @if ($audit->maps_keyword)
                <p class="mt-3 text-sm text-gray-700 dark:text-gray-300">"{{ $audit->maps_keyword }}" haritada:
                    @if ($audit->status === 'running') sonuç bekleniyor…
                    @elseif (isset($maps['error'])) okunamadı.
                    @elseif ($maps['found'] ?? false) {{ $maps['ours']['rank'] }}. sırada ({{ $maps['ours']['rating'] ?? '—' }}★, {{ $maps['ours']['votes'] ?? 0 }} yorum).
                    @else ilk {{ $maps['results'] ?? 20 }} sonuçta yok.
                    @endif
                </p>
            @endif
        </div>
    @empty
        <p class="text-sm text-gray-500">Henüz denetim yok.</p>
    @endforelse
</div>
