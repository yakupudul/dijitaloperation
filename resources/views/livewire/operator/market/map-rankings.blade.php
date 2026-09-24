@php
    $card = 'rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $input = 'mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900';
    $cell = function ($point): array {
        if ($point->status === 'pending') {
            return ['bg-gray-200 text-gray-500 dark:bg-gray-800', '…'];
        }
        if ($point->status === 'failed') {
            return ['bg-gray-300 text-gray-600', '!'];
        }
        $rank = $point->our_rank;
        if ($rank === null) {
            return ['bg-rose-500 text-white', '20+'];
        }

        return [$rank <= 3 ? 'bg-emerald-500 text-white' : ($rank <= 10 ? 'bg-amber-400 text-gray-900' : 'bg-orange-500 text-white'), (string) $rank];
    };
    $delta = function (?float $now, ?float $before, bool $lowerIsBetter = true): string {
        if ($now === null || $before === null) {
            return '';
        }
        $diff = round($now - $before, 1);
        if ($diff == 0.0) {
            return '(=)';
        }
        $good = $lowerIsBetter ? $diff < 0 : $diff > 0;

        return '<span class="'.($good ? 'text-emerald-600' : 'text-rose-600').'">('.($diff > 0 ? '+' : '').$diff.')</span>';
    };
@endphp
<div class="space-y-5" @if ($selected?->status === 'running') wire:poll.15s @endif>
    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Harita sıralaması</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">İşletmenin çevresinde N×N noktada Google Haritalar araması: her noktada sıra, ARP (bulunduğu yerlerde ortalama sıra), ATRP (ilk 20'de yoksa 21 sayılır) ve SoLV (ilk 3'te olduğu noktaların payı). DataForSEO ücretli; marka bazında açılır, aylık tavan aşılmaz. Google'a hiçbir şey yazılmaz.</p>
    </div>

    @if ($message !== '')<p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $message }}</p>@endif
    @if ($error !== '')<p class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800">{{ $error }}</p>@endif
    @if (! $connected)<p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">DataForSEO bağlı değil; tarama yapılamaz (Entegrasyonlar › DataForSEO).</p>@endif

    <label class="block max-w-sm text-sm">
        <span class="text-xs text-gray-500">Marka</span>
        <select wire:model.live="brand" class="{{ $input }}">
            @foreach ($brands as $option)
                <option value="{{ $option->id }}">{{ $option->name }}</option>
            @endforeach
        </select>
    </label>

    @if ($settings !== null)
        <div class="grid gap-5 lg:grid-cols-3">
            <section class="{{ $card }} lg:col-span-2">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Son taramalar</h2>
                @if ($latest->isEmpty())
                    <p class="mt-2 text-sm text-gray-500">Henüz tarama yok.</p>
                @else
                    <table class="mt-2 w-full text-sm">
                        <thead><tr class="text-left text-xs text-gray-500"><th class="py-1">Kelime</th><th>Tarih</th><th>ARP</th><th>ATRP</th><th>SoLV</th><th>Durum</th></tr></thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($latest as $row)
                                <tr wire:key="latest-{{ $row->id }}">
                                    <td class="py-1.5"><button type="button" wire:click="$set('run', {{ $row->id }})" class="text-brand-600 hover:underline">{{ $row->keyword }}</button></td>
                                    <td class="text-xs text-gray-500">{{ $row->started_at?->format('d.m.Y') }}</td>
                                    <td>{{ $row->arp ?? '—' }}</td><td>{{ $row->atrp ?? '—' }}</td><td>{{ $row->solv !== null ? '%'.$row->solv : '—' }}</td>
                                    <td class="text-xs">{{ ['running' => 'sürüyor ('.$row->points_done.'/'.$row->points_total.')', 'completed' => 'tamam', 'partial' => 'kısmi', 'failed' => 'başarısız'][$row->status] ?? $row->status }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
                @if ($isAdmin)
                    <div class="mt-4 flex flex-wrap items-end gap-2">
                        <label class="text-sm"><span class="text-xs text-gray-500">Anahtar kelime</span><input type="text" wire:model="scanKeyword" class="{{ $input }}" placeholder="implant kadıköy"></label>
                        <x-ta.button type="button" wire:click="scan" size="sm">Şimdi tara (≈ {{ number_format($estimate, 3) }} USD)</x-ta.button>
                    </div>
                @endif
            </section>

            <section class="{{ $card }}">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Bütçe ve kimlik</h2>
                <dl class="mt-2 space-y-1 text-sm">
                    <div class="flex justify-between"><dt class="text-gray-500">Bu ay harcanan</dt><dd>{{ number_format($spent, 3) }} / {{ number_format($settings->monthly_usd, 2) }} USD</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Profil</dt><dd>{{ $identity['title'] ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Place ID / CID</dt><dd class="text-xs">{{ $identity['place_id'] ? 'var' : 'yok' }} / {{ $identity['cid'] ?? 'yok' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Profil pini</dt><dd class="text-xs">{{ $identity['lat'] !== null ? number_format($identity['lat'], 5).', '.number_format($identity['lng'], 5) : 'yok' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Site / telefon</dt><dd class="text-xs">{{ implode(', ', $identity['hosts']) ?: '—' }} · {{ count($identity['phones']) }} tel.</dd></div>
                </dl>
                <p class="mt-2 text-xs text-gray-500">Sonuçlarda işletme place ID, CID, site alan adı ya da telefonla tanınır.</p>
            </section>
        </div>

        @if ($selected !== null && $points !== null)
            <section class="{{ $card }}">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">"{{ $selected->keyword }}" · {{ $selected->started_at?->format('d.m.Y H:i') }} · {{ $selected->grid_size }}×{{ $selected->grid_size }}, {{ $selected->spacing_km }} km aralık</h2>
                    <p class="text-sm">ARP <b>{{ $selected->arp ?? '—' }}</b> {!! $delta($selected->arp, $previous?->arp) !!} · ATRP <b>{{ $selected->atrp ?? '—' }}</b> {!! $delta($selected->atrp, $previous?->atrp) !!} · SoLV <b>{{ $selected->solv !== null ? '%'.$selected->solv : '—' }}</b> {!! $delta($selected->solv, $previous?->solv, false) !!}</p>
                </div>
                <div class="mt-4 grid gap-5 lg:grid-cols-2">
                    <div>
                        <div class="inline-grid gap-1" style="grid-template-columns: repeat({{ $selected->grid_size }}, minmax(0, 2.75rem));">
                            @foreach ($points as $point)
                                <div wire:key="pt-{{ $point->id }}" title="{{ number_format($point->lat, 5) }}, {{ number_format($point->lng, 5) }}" @class(['flex h-11 items-center justify-center rounded text-xs font-semibold', $cell($point)[0], 'ring-2 ring-gray-900 dark:ring-white' => $point->row === intdiv($selected->grid_size, 2) && $point->col === intdiv($selected->grid_size, 2)])>{{ $cell($point)[1] }}</div>
                            @endforeach
                        </div>
                        <p class="mt-2 text-xs text-gray-500">Kuzey yukarıda. Yeşil 1–3, sarı 4–10, turuncu 11–20, kırmızı ilk 20'de yok. Çerçeveli hücre merkez.</p>
                    </div>
                    <div wire:ignore x-data="mapGrid(@js(['center' => [$selected->center_lat, $selected->center_lng], 'pin' => $identity['lat'] !== null ? [$identity['lat'], $identity['lng']] : null, 'points' => $points->map(fn ($p) => [$p->lat, $p->lng, $p->our_rank, $p->status])->values()]))" x-init="draw()" class="h-72 rounded-lg ring-1 ring-gray-200 dark:ring-gray-800" wire:key="map-{{ $selected->id }}-{{ $selected->points_done }}"></div>
                </div>
            </section>

            <section class="{{ $card }}">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Bu taramada öne çıkan işletmeler</h2>
                @if ($competitors === [])
                    <p class="mt-2 text-sm text-gray-500">Sonuç bekleniyor.</p>
                @else
                    <table class="mt-2 w-full text-sm">
                        <thead><tr class="text-left text-xs text-gray-500"><th class="py-1">İşletme</th><th>İlk 3'te nokta</th><th>İlk 20'de nokta</th><th>Ort. sıra</th><th>Puan</th></tr></thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($competitors as $row)
                                <tr @class(['font-semibold text-brand-700' => $row['ours']])><td class="py-1.5">{{ $row['title'] }}{{ $row['ours'] ? ' (siz)' : '' }}</td><td>{{ $row['top3'] }}</td><td>{{ $row['top20'] }}</td><td>{{ $row['avg_rank'] }}</td><td>{{ $row['rating'] !== null ? $row['rating'].' ('.$row['votes'].')' : '—' }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>
        @endif

        @if ($isAdmin)
            <section class="{{ $card }}">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Ayarlar</h2>
                <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <label class="text-sm"><span class="text-xs text-gray-500">Aylık tavan (USD, harita + yorum + backlink)</span><input type="number" step="0.5" wire:model="form.monthly_usd" class="{{ $input }}"></label>
                    <label class="text-sm"><span class="text-xs text-gray-500">Grid boyutu</span><select wire:model="form.grid_size" class="{{ $input }}">@foreach (config('moxdop-intel.grid.sizes') as $size)<option value="{{ $size }}">{{ $size }}×{{ $size }}</option>@endforeach</select></label>
                    <label class="text-sm"><span class="text-xs text-gray-500">Nokta aralığı (km)</span><input type="number" step="0.1" wire:model="form.grid_spacing_km" class="{{ $input }}"></label>
                    <label class="text-sm"><span class="text-xs text-gray-500">Kaç günde bir</span><input type="number" wire:model="form.grid_every_days" class="{{ $input }}"></label>
                    <label class="text-sm"><span class="text-xs text-gray-500">Merkez enlem (boşsa profil pini)</span><input type="text" wire:model="form.grid_center_lat" class="{{ $input }}"></label>
                    <label class="text-sm"><span class="text-xs text-gray-500">Merkez boylam</span><input type="text" wire:model="form.grid_center_lng" class="{{ $input }}"></label>
                    <label class="text-sm"><span class="text-xs text-gray-500">Place ID (boşsa profilden)</span><input type="text" wire:model="form.gbp_place_id" class="{{ $input }}"></label>
                    <label class="text-sm"><span class="text-xs text-gray-500">CID (boşsa profilden)</span><input type="text" wire:model="form.gbp_cid" class="{{ $input }}"></label>
                    <label class="text-sm sm:col-span-2"><span class="text-xs text-gray-500">Takip edilen kelimeler (satır başına bir, en fazla {{ config('moxdop-intel.grid.max_keywords') }})</span><textarea rows="3" wire:model="form.keywords" class="{{ $input }}"></textarea></label>
                    <label class="flex items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" wire:model="form.grid_enabled" class="rounded border-gray-300"> Zamanlanmış tarama açık (her kelime, seçilen günde bir)</label>
                </div>
                @error('monthly_usd')<p class="mt-2 text-xs text-rose-600">{{ $message }}</p>@enderror
                <x-ta.button type="button" wire:click="saveSettings" size="sm" class="mt-3">Kaydet</x-ta.button>
            </section>
        @endif
    @endif
</div>

@assets
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    window.mapGrid = window.mapGrid || function (data) {
        return {
            draw() {
                if (typeof L === 'undefined') { this.$el.innerHTML = '<p class="p-3 text-xs text-gray-500">Harita yüklenemedi; ısı tablosu solda.</p>'; return; }
                const map = L.map(this.$el, { scrollWheelZoom: false }).setView(data.center, 13);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);
                const bounds = [];
                data.points.forEach(([lat, lng, rank, status]) => {
                    const color = status !== 'done' ? '#9ca3af' : rank === null ? '#f43f5e' : rank <= 3 ? '#10b981' : rank <= 10 ? '#fbbf24' : '#f97316';
                    L.circleMarker([lat, lng], { radius: 11, color: '#111827', weight: 1, fillColor: color, fillOpacity: 0.9 })
                        .bindTooltip(status !== 'done' ? '…' : (rank === null ? '20+' : String(rank)), { permanent: true, direction: 'center', className: 'text-xs' }).addTo(map);
                    bounds.push([lat, lng]);
                });
                if (data.pin) { L.marker(data.pin).bindPopup('Profil pini').addTo(map); bounds.push(data.pin); }
                if (bounds.length) { map.fitBounds(bounds, { padding: [20, 20] }); }
            },
        };
    };
</script>
@endassets
