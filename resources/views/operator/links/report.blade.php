@php
    use App\Models\BacklinkSource;
    use App\Services\SeoTasks\SeoText;
    use App\Services\Site\Backlinks\LinkNapCheck;
    $months = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    $label = $months[$month->month - 1].' '.$month->year;
    $path = fn (?string $url): string => $url ? (SeoText::urlPath($url) ?: '/') : '/';
    $relLabel = ['follow' => 'takip edilen', 'nofollow' => 'nofollow', 'sponsored' => 'sponsorlu', 'ugc' => 'kullanıcı içeriği'];
    $pos = fn ($p): string => $p === null ? '—' : number_format((float) $p, 1, ',', '');
@endphp
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Bağlantı raporu · {{ $brand->name }} · {{ $label }}</title>
    <style>
        :root { --ink: #1f2933; --muted: #6b7280; --line: #e5e7eb; --accent: #0f766e; --bad: #b42318; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #fff; color: var(--ink); font: 14px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        main { max-width: 860px; margin: 0 auto; padding: 40px 20px 64px; }
        h1 { font-size: 26px; font-weight: 500; margin: 0 0 4px; }
        h2 { font-size: 16px; font-weight: 600; margin: 36px 0 10px; }
        p.sub { color: var(--muted); margin: 0; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-top: 24px; }
        .stat { border: 1px solid var(--line); border-radius: 10px; padding: 12px 14px; }
        .stat b { display: block; font-size: 24px; font-weight: 500; }
        .stat span { color: var(--muted); font-size: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 8px 6px; border-bottom: 1px solid var(--line); vertical-align: top; }
        th { font-size: 12px; font-weight: 500; color: var(--muted); }
        td small { color: var(--muted); }
        .up { color: var(--accent); }
        .bad { color: var(--bad); }
        .empty { color: var(--muted); }
        .note { color: var(--muted); font-size: 12px; margin-top: 40px; }
        .print { float: right; border: 1px solid var(--line); background: #fff; border-radius: 8px; padding: 6px 12px; cursor: pointer; font: inherit; }
        @media print { .print { display: none; } main { padding-top: 0; } }
        @media (max-width: 600px) { .hide-sm { display: none; } }
    </style>
</head>
<body>
<main data-link-report>
    <button type="button" class="print" onclick="window.print()">Yazdır / PDF</button>
    <h1>Bağlantı raporu · {{ $label }}</h1>
    <p class="sub">{{ $brand->name }} · paket: {{ $package }}</p>

    <div class="stats">
        <div class="stat"><b>{{ $won->count() }}</b><span>bu ay gelen ve doğrulanan link{{ $budget > 0 ? ' (hedef '.$budget.')' : '' }}</span></div>
        <div class="stat"><b>{{ $applied }}</b><span>bu ay yapılan başvuru</span></div>
        <div class="stat"><b>{{ $waiting }}</b><span>cevap beklenen başvuru</span></div>
        <div class="stat"><b>{{ $total }}</b><span>şu an yayında olan link</span></div>
    </div>

    <h2>Bu ay gelen linkler</h2>
    @if ($won->isEmpty())
        <p class="empty">Bu ay doğrulanan yeni link yok.</p>
    @else
        <table>
            <thead><tr><th>Site</th><th>Bağlantı verilen sayfamız</th><th class="hide-sm">Tür</th><th>Tarih</th></tr></thead>
            <tbody>
            @foreach ($won as $row)
                <tr>
                    <td>{{ $row->name }}<br><small>{{ $row->domain }}</small></td>
                    <td>{{ $row->target?->title ?: $path($row->target?->url) }}<br><small>{{ $row->anchor ? '“'.$row->anchor.'”' : '' }}</small></td>
                    <td class="hide-sm">{{ $row->engineLabel() }}<br><small>{{ $relLabel[$row->rel] ?? '' }}</small></td>
                    <td>{{ $row->verified_at->timezone('Europe/Istanbul')->format('d.m.Y') }}@if ($row->status === BacklinkSource::REMOVED)<br><small class="bad">sonra kaldırıldı</small>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <h2>Güçlendirilen sayfalar</h2>
    @if ($targets->isEmpty())
        <p class="empty">Bu ay link desteği verilen bir hedef sayfa yok.</p>
    @else
        <table>
            <thead><tr><th>Sayfa</th><th>Seçildiğinde</th><th>Şimdi</th><th class="hide-sm">Linkten 60 / 90 gün sonra</th></tr></thead>
            <tbody>
            @foreach ($targets as $t)
                @php $b = (array) $t->before; $i = (array) $t->impact; @endphp
                <tr>
                    <td>{{ $t->title ?: $path($t->url) }}<br><small>{{ $t->kind === 'miknatis' ? 'rehber sayfası' : ($t->query ? '“'.$t->query.'” araması' : $path($t->url)) }}</small></td>
                    <td>{{ $b !== [] ? $pos($b['position'] ?? null).'. sıra · '.($b['clicks'] ?? 0).' tıklama' : '—' }}</td>
                    <td @class(['up' => $b !== [] && $t->position !== null && ($b['position'] ?? 99) > $t->position])>{{ $t->position !== null ? $pos($t->position).'. sıra · '.$t->clicks.' tıklama' : '—' }}@if ($t->status === 'tamam')<br><small class="up">ilk 3'te</small>@endif</td>
                    <td class="hide-sm">
                        @foreach ([60, 90] as $d)
                            @if (isset($i['d'.$d]))<span>{{ $d }}. gün: {{ $pos($i['d'.$d]['position']) }}. sıra · {{ $i['d'.$d]['clicks'] }} tıklama</span><br>@endif
                        @endforeach
                        @if (! isset($i['d60']))<small>{{ isset($i['first_link_at']) ? 'ilk link '.\Illuminate\Support\Carbon::parse($i['first_link_at'])->format('d.m.Y') : 'ilk link bekleniyor' }}</small>@endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <p class="note">Sıra ve tıklama Google Search Console'un son 28 gününden. Linklerin etkisi genellikle 2-3 ayda görünür; bu yüzden ilk linkten 60 ve 90 gün sonra ölçülür.</p>
    @endif

    @if ($nap->isNotEmpty())
        <h2>Düzeltilecek kayıtlar</h2>
        <table>
            <tbody>
            @foreach ($nap as $row)
                <tr><td>{{ $row->name }}</td><td class="bad">{{ LinkNapCheck::line($row) }}</td></tr>
            @endforeach
            </tbody>
        </table>
    @endif

    @if ($lost->isNotEmpty())
        <h2>Kaybolan linkler</h2>
        <table>
            <tbody>
            @foreach ($lost as $row)
                <tr><td>{{ $row->name }}</td><td><small>{{ $row->domain }}</small></td><td>geri istenecek</td></tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <p class="note">Linkler satın alınmaz ve karşılıklı link verilmez; tüm bağlantılar gerçek kayıtlar, ilişkiler ve haberlerden gelir.</p>
</main>
</body>
</html>
