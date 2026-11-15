@component('operator.layouts.app', ['title' => 'Excel hazırlanıyor'])
<div class="mx-auto max-w-2xl space-y-8" data-wa-export-wait>
    <div>
        <a href="{{ $backUrl }}" class="btn-quiet -ml-2.5 mb-2">
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 0 1-.02 1.06L8.83 10l3.94 3.71a.75.75 0 1 1-1.04 1.08l-4.5-4.25a.75.75 0 0 1 0-1.08l4.5-4.25a.75.75 0 0 1 1.06.02Z" clip-rule="evenodd"/></svg>
            WhatsApp
        </a>
        <h1 class="page-title">Excel hazırlanıyor</h1>
        <p class="page-sub">{{ $title }}: görüşme çok olduğu için dosya arka planda yazılıyor. Hazır olunca indirme kendiliğinden başlar; bu sayfayı açık bırakın.</p>
    </div>
    <div class="card">
        <div class="card-row items-start">
            <span id="wa-export-dot" class="mt-1.5 h-2 w-2 shrink-0 animate-pulse rounded-full bg-gray-400" aria-hidden="true"></span>
            <p id="wa-export-text" class="min-w-0 flex-1 text-sm text-gray-700 dark:text-gray-300" role="status">Hazırlanıyor… Büyük yedeklerde birkaç dakika sürebilir.</p>
            <a id="wa-export-link" href="{{ $fileUrl }}" class="btn hidden">Dosyayı indir</a>
        </div>
    </div>
</div>
<script>
(() => {
    const dot = document.getElementById('wa-export-dot');
    const text = document.getElementById('wa-export-text');
    const link = document.getElementById('wa-export-link');
    const started = Date.now();
    const done = (message, tone) => {
        dot.classList.remove('animate-pulse', 'bg-gray-400');
        dot.classList.add(tone);
        text.textContent = message;
    };
    const tick = async () => {
        let status = 'pending';
        try {
            const response = await fetch(@json($statusUrl), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            status = response.ok ? (await response.json()).status : (response.status === 404 ? 'gone' : 'pending');
        } catch (e) {}
        if (status === 'ready') {
            done('Excel hazır, indirme başladı. Başlamadıysa yandaki düğmeye basın.', 'bg-success-500');
            link.classList.remove('hidden');
            window.location.href = link.href;
            return;
        }
        if (status === 'failed') {
            return done('Excel hazırlanamadı. Hata kaydedildi; biraz sonra yeniden deneyin.', 'bg-error-500');
        }
        if (status === 'downloaded' || status === 'gone') {
            return done('Bu dosya indirildi ve silindi. Yeniden indirmek için WhatsApp ekranından Excel indir\'e basın.', 'bg-gray-400');
        }
        if (Date.now() - started > 20 * 60 * 1000) {
            return done('Hazırlık beklenenden uzun sürdü. Sayfayı yenileyip yeniden deneyin.', 'bg-warning-500');
        }
        setTimeout(tick, 3000);
    };
    setTimeout(tick, 1500);
})();
</script>
@endcomponent
