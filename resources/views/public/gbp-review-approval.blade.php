<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Yorum yanıtları onayı · {{ $approval->brand?->name }}</title>
    <style>
        :root { --ink: #111827; --muted: #6b7280; --line: #e5e7eb; --bg: #f9fafb; --card: #fff; --brand: #4f46e5; --ok: #059669; --warn: #b45309; --bad: #e11d48; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--ink); background: var(--bg); line-height: 1.5; }
        main { max-width: 760px; margin: 0 auto; padding: 20px 16px 120px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        .intro { color: var(--muted); font-size: 14px; margin: 0 0 18px; }
        .notice { border-radius: 10px; padding: 12px 14px; margin-bottom: 16px; font-size: 14px; }
        .notice.ok { background: #ecfdf5; color: #065f46; }
        .notice.err { background: #fff1f2; color: #9f1239; }
        .item { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 14px; margin-bottom: 12px; }
        .meta { font-size: 13px; color: var(--muted); display: flex; flex-wrap: wrap; gap: 6px 10px; align-items: center; }
        .stars { color: #d97706; letter-spacing: 1px; }
        .no { font-weight: 700; color: var(--ink); }
        .comment { margin: 8px 0; font-size: 15px; white-space: pre-line; }
        .empty { color: #9ca3af; font-style: italic; }
        .reply-label { font-size: 12px; font-weight: 600; color: var(--brand); text-transform: uppercase; letter-spacing: .03em; margin-top: 10px; }
        textarea { width: 100%; border: 1px solid #d1d5db; border-radius: 8px; padding: 9px 10px; font: inherit; font-size: 15px; margin-top: 4px; }
        textarea[readonly] { background: #eef2ff; border-color: #e0e7ff; }
        .choices { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        .choices label { display: inline-flex; align-items: center; gap: 6px; border: 1px solid var(--line); border-radius: 999px; padding: 6px 12px; font-size: 14px; cursor: pointer; background: #fff; }
        .choices input { margin: 0; }
        .choices label:has(input:checked) { border-color: var(--brand); background: #eef2ff; font-weight: 600; }
        .answered { margin-top: 8px; font-size: 13px; font-weight: 600; }
        .bar { position: fixed; left: 0; right: 0; bottom: 0; background: #fff; border-top: 1px solid var(--line); padding: 12px 16px; }
        .bar-inner { max-width: 760px; margin: 0 auto; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        button { background: var(--brand); color: #fff; border: 0; border-radius: 8px; padding: 10px 18px; font-size: 15px; font-weight: 600; cursor: pointer; }
        .all { background: #fff; color: var(--brand); border: 1px solid #c7d2fe; }
        .note { margin-top: 6px; }
        footer { color: var(--muted); font-size: 12px; margin-top: 18px; }
    </style>
</head>
<body>
<main>
    <h1>{{ $approval->brand?->name }} · Google yorum yanıtları</h1>
    <p class="intro">Google’daki yorumlarınıza vermeyi önerdiğimiz yanıtlar aşağıda. Her biri için “Uygun”, “Düzelterek onayla” (metni değiştirin) ya da “Yanıtlamayalım” seçip en alttan gönderin. Onayınızdan sonra yanıtları biz yayınlarız; bu sayfadan Google’a hiçbir şey gitmez.</p>

    @if ($saved)
        <div class="notice ok">Teşekkürler, yanıtınız bize ulaştı. Bağlantı açık kaldığı sürece değiştirip yeniden gönderebilirsiniz.</div>
    @endif
    @if ($errors->any())
        <div class="notice err">{{ $errors->first() }}</div>
    @endif
    @if (! $usable)
        <div class="notice err">Bu bağlantının süresi doldu ya da kapatıldı. Yeni bağlantı için bizimle iletişime geçin.</div>
    @endif

    <form method="post" action="{{ route('gbp-review-approval.store', ['token' => $approval->token]) }}" id="approval">
        @csrf
        @foreach ($approval->items as $index => $item)
            @php
                $id = $item['review_id'];
                $decision = old('decision.'.$id, $item['decision'] ?? 'ok');
                $text = old('text.'.$id, $item['brand_text'] ?? $item['text']);
            @endphp
            <section class="item" data-item>
                <div class="meta">
                    <span class="no">{{ $index + 1 }}.</span>
                    @if ($item['rating'])<span class="stars" aria-label="{{ $item['rating'] }} yıldız">{{ str_repeat('★', (int) $item['rating']).str_repeat('☆', 5 - (int) $item['rating']) }}</span>@endif
                    <span>{{ $item['reviewer'] !== '' ? $item['reviewer'] : 'Bir müşteri' }}</span>
                    <span>{{ $item['date'] !== '' ? \Illuminate\Support\Carbon::parse($item['date'])->format('d.m.Y') : '' }}</span>
                    @if ($item['business'] !== '')<span>· {{ $item['business'] }}</span>@endif
                </div>
                <p class="comment">@if ($item['comment'] !== ''){{ $item['comment'] }}@else<span class="empty">Yalnız puan verilmiş, yorum metni yok.</span>@endif</p>
                <p class="reply-label">Önerilen yanıt</p>
                <textarea name="text[{{ $id }}]" rows="4" maxlength="4000" @readonly($decision !== 'edit' || ! $usable) data-text data-original="{{ $item['text'] }}">{{ $text }}</textarea>
                @if ($usable)
                    <div class="choices">
                        <label><input type="radio" name="decision[{{ $id }}]" value="ok" @checked($decision === 'ok') data-choice> Uygun</label>
                        <label><input type="radio" name="decision[{{ $id }}]" value="edit" @checked($decision === 'edit') data-choice> Düzelterek onayla</label>
                        <label><input type="radio" name="decision[{{ $id }}]" value="skip" @checked($decision === 'skip') data-choice> Yanıtlamayalım</label>
                    </div>
                @elseif (isset($item['decision']))
                    <p class="answered">{{ \App\Models\GbpReviewApproval::DECISIONS[$item['decision']] ?? '' }}</p>
                @endif
            </section>
        @endforeach

        <section class="item">
            <p class="reply-label" style="margin-top:0">Notunuz (isteğe bağlı)</p>
            <textarea name="note" rows="3" maxlength="2000" class="note" @readonly(! $usable) placeholder="Genel bir isteğiniz varsa yazın (ör. yanıtlarda hitap “Sayın …” olsun).">{{ old('note', $approval->note) }}</textarea>
        </section>
        <footer>Bu bağlantı {{ $approval->expires_at->timezone('Europe/Istanbul')->format('d.m.Y') }} tarihine kadar açıktır. Moximu</footer>

        @if ($usable)
            <div class="bar"><div class="bar-inner">
                <button type="submit">Yanıtlarımı gönder</button>
                <span style="font-size:13px;color:var(--muted)">{{ count($approval->items) }} yanıt</span>
            </div></div>
        @endif
    </form>
</main>
<script>
    document.querySelectorAll('[data-item]').forEach(function (item) {
        var text = item.querySelector('[data-text]');
        item.querySelectorAll('[data-choice]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                text.readOnly = radio.value !== 'edit';
                if (radio.value === 'edit') { text.focus(); }
                if (radio.value === 'ok') { text.value = text.dataset.original; }
            });
        });
    });
</script>
</body>
</html>
