<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <title>Yorum yanıtları · {{ $title }}</title>
    <style>
        @page { margin: 16mm 14mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; color: #111827; }
        h1 { font-size: 15pt; margin: 0 0 2mm; }
        .intro { color: #4b5563; margin: 0 0 6mm; line-height: 1.45; }
        .item { border: 1px solid #d1d5db; border-radius: 3mm; padding: 3.5mm 4mm; margin-bottom: 4mm; page-break-inside: avoid; }
        .meta { color: #4b5563; font-size: 8.5pt; }
        .no { display: inline-block; min-width: 7mm; font-weight: bold; color: #111827; }
        .stars { color: #d97706; }
        .comment { margin: 2mm 0; line-height: 1.45; }
        .empty { color: #9ca3af; font-style: italic; }
        .reply { background: #eef2ff; border-radius: 2mm; padding: 2.5mm 3mm; line-height: 1.45; }
        .reply b { display: block; font-size: 7.5pt; color: #4338ca; text-transform: uppercase; margin-bottom: 1mm; }
        .approve { margin-top: 2.5mm; font-size: 8.5pt; color: #374151; }
        .box { display: inline-block; width: 3mm; height: 3mm; border: 1px solid #6b7280; margin: 0 1mm -0.5mm 0; }
        .line { display: inline-block; width: 95mm; border-bottom: 1px solid #9ca3af; }
    </style>
</head>
<body>
    <h1>{{ $title }} · Google yorum yanıtları</h1>
    <p class="intro">{{ $date }} · {{ count($rows) }} yorum. Aşağıdaki yanıtlar henüz yayımlanmadı. Uygun olanları işaretleyin, değişmesini istediklerinize notunuzu yazın; onayınızdan sonra Google’da yayımlanır.</p>
    @foreach ($rows as $i => $row)
        <div class="item">
            <div class="meta">
                <span class="no">{{ $i + 1 }}.</span>
                <span class="stars">{{ $row['rating'] !== null ? str_repeat('★', $row['rating']).str_repeat('☆', 5 - $row['rating']) : '—' }}</span>
                · {{ $row['reviewer'] }} · {{ $row['date'] }} · {{ $names[$row['asset_id']] ?? '' }}
            </div>
            <p class="comment">@if ($row['comment'] !== ''){{ $row['comment'] }}@else<span class="empty">Yalnız puan verilmiş, yorum metni yok.</span>@endif</p>
            <div class="reply"><b>Önerilen yanıt</b>{{ $row['draft'] }}</div>
            <div class="approve"><span class="box"></span> Uygun &nbsp;&nbsp; <span class="box"></span> Değişsin: <span class="line"></span></div>
        </div>
    @endforeach
</body>
</html>
