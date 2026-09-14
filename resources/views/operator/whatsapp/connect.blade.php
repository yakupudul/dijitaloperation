@component('operator.layouts.app', ['title' => 'WhatsApp hesabını bağla'])
<section class="mx-auto max-w-2xl space-y-5 rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">
    <h1 class="text-2xl font-semibold">WhatsApp hesabını bağla</h1>
    <p class="text-sm text-gray-500">{{ $attempt['mode'] === 'coexistence' ? 'Mevcut WhatsApp Business uygulamanızla birlikte kullanım için Meta ekranını tamamlayın.' : 'WhatsApp Cloud API hesabınızı Meta ekranından seçin.' }}</p>
    @if($attempt['expires_at']->isPast())
        <p class="text-amber-700">Bağlantı oturumunun süresi doldu. Panele dönüp yeniden başlatın.</p>
    @elseif($attempt['status'] === 'choose_phone')
        <p>Meta numara seçimini iletmedi. Bağlamak istediğiniz işletme numarasını seçin.</p>
        <label for="wa-selected-phone" class="block text-sm">İşletme numarası</label>
        <select id="wa-selected-phone" class="w-full rounded-lg border border-gray-300 bg-transparent p-3">
            <option value="">Numara seçin</option>
            @foreach($phones as $phone)
                <option value="{{ $phone['id'] }}">{{ $phone['display_phone_number'] }} · {{ $phone['verified_name'] }} · {{ $phone['id'] }}</option>
            @endforeach
        </select>
        <button id="wa-select-phone" type="button" class="rounded-lg bg-brand-500 px-5 py-3 text-white disabled:opacity-50">Bu numarayı bağla</button>
    @elseif($attempt['status'] === 'prepared')
        <p class="text-sm">Facebook girişini tamamlayıp doğru işletmeyi ve numarayı seçin. Uygulamayla birlikte kullanımda Meta telefonunuzdan onay isteyebilir.</p>
        <button id="wa-facebook-login" type="button" disabled class="rounded-lg bg-brand-500 px-5 py-3 text-white disabled:opacity-50">Facebook yükleniyor…</button>
        <button id="wa-retry-handoff" type="button" hidden class="rounded-lg border border-gray-300 px-5 py-3">Bağlantı sonucunu tekrar kaydet</button>
    @else
        <p>Bağlantı sonucu panelde gösteriliyor. Panele dönebilirsiniz.</p>
    @endif
    <p id="wa-signup-status" role="status" class="text-sm text-gray-600 dark:text-gray-300"></p>
    <a href="{{ route('operator.whatsapp') }}" class="block text-sm text-brand-500">WhatsApp Asistanına dön</a>
</section>
<script>
(() => {
    const settings = {{ Illuminate\Support\Js::from([
        'appId' => $appId, 'configId' => $configId, 'version' => $graphVersion, 'mode' => $attempt['mode'],
        'completeUrl' => route('operator.whatsapp.complete', ['attempt' => $attempt['id']]),
        'phoneUrl' => route('operator.whatsapp.select-phone', ['attempt' => $attempt['id']]),
    ]) }};
    const status = document.getElementById('wa-signup-status');
    const login = document.getElementById('wa-facebook-login');
    const retry = document.getElementById('wa-retry-handoff');
    let code = null, session = null, saving = false, launched = false, timer = null;
    const say = message => { status.textContent = message; };
    async function post(url, payload) {
        const controller = new AbortController();
        const deadline = setTimeout(() => controller.abort(), 20000);
        let response;
        try {
        response = await fetch(url, {
            signal: controller.signal,
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: JSON.stringify(payload),
        });
        } catch (_) {
            throw new Error('Sunucudan yanıt alınamadı. Bağlantı sonucunu tekrar kaydedebilirsiniz.');
        } finally { clearTimeout(deadline); }
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const fieldError = data.errors ? Object.values(data.errors).flat()[0] : null;
            throw new Error(fieldError || data.message || 'Sonuç kaydedilemedi. Oturumunuzun açık olduğundan emin olup tekrar deneyin.');
        }
        return data;
    }
    async function handoff() {
        if (!code || !session || saving) return;
        saving = true;
        clearTimeout(timer);
        if (retry) retry.hidden = true;
        say('Meta onayı alındı. Hesap bağlantısı arka plana aktarılıyor…');
        try {
            const data = await post(settings.completeUrl, { code, waba_id: session.waba_id, phone_number_id: session.phone_number_id || null, event: session.event });
            code = null;
            session = null;
            window.location.assign(data.redirect);
        } catch (error) {
            say(error.message);
            if (retry) retry.hidden = false;
        } finally { saving = false; }
    }
    if (retry) retry.addEventListener('click', handoff);
    window.addEventListener('message', event => {
        if (!launched || !['https://www.facebook.com', 'https://web.facebook.com', 'https://facebook.com'].includes(event.origin)) return;
        let message;
        try { message = typeof event.data === 'string' ? JSON.parse(event.data) : event.data; } catch (_) { return; }
        if (!message || message.type !== 'WA_EMBEDDED_SIGNUP') return;
        if (['FINISH', 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING'].includes(message.event)) {
            const data = message.data || {};
            if (!/^[0-9]{5,40}$/.test(String(data.waba_id || ''))) {
                say('Meta WABA bilgisini iletmedi. Panele dönüp bağlantıyı yeniden başlatın.');
                return;
            }
            session = { waba_id: String(data.waba_id), phone_number_id: data.phone_number_id ? String(data.phone_number_id) : null, event: message.event };
            handoff();
        } else if (message.event === 'CANCEL' || message.event === 'ERROR') {
            clearTimeout(timer);
            say('Meta bağlantısı tamamlanmadı. Meta ekranındaki uyarıyı kontrol edip yeniden deneyin.');
            login.disabled = false;
        }
    });
    if (login) {
        window.fbAsyncInit = () => {
            FB.init({ appId: settings.appId, cookie: true, xfbml: true, version: settings.version });
            login.disabled = false;
            login.textContent = 'Facebook ile devam et';
            say('Bağlantı penceresini açabilirsiniz.');
        };
        const sdk = document.createElement('script');
        sdk.src = 'https://connect.facebook.net/tr_TR/sdk.js';
        sdk.async = true;
        sdk.defer = true;
        sdk.onerror = () => say('Facebook yüklenemedi. Tarayıcıdaki engelleyicileri ve bağlantınızı kontrol edip sayfayı yenileyin.');
        document.head.appendChild(sdk);
        login.addEventListener('click', () => {
            code = null; session = null; launched = true;
            login.disabled = true;
            say('Meta penceresinde hesap bağlantısını tamamlayın.');
            const extras = { setup: {} };
            if (settings.mode === 'coexistence') extras.featureType = 'whatsapp_business_app_onboarding';
            // Call synchronously from the click so browsers permit the OAuth popup.
            try {
            FB.login(response => {
                if (response.authResponse && typeof response.authResponse.code === 'string') {
                    code = response.authResponse.code;
                    handoff();
                    if (!session) timer = setTimeout(() => say('Meta yetki kodunu verdi ancak hesap seçimi gelmedi. Panele dönüp yeniden bağlayın.'), 30000);
                } else {
                    say('Facebook izni tamamlanmadı veya pencere kapatıldı. Tekrar deneyebilirsiniz.');
                    login.disabled = false;
                }
            }, { config_id: settings.configId, response_type: 'code', override_default_response_type: true, extras });
            } catch (_) {
                say('Facebook penceresi açılamadı. Açılır pencere iznini ve Meta App ID ayarını kontrol edin.');
                login.disabled = false;
            }
        });
    }
    const select = document.getElementById('wa-select-phone');
    if (select) select.addEventListener('click', async () => {
        const phoneId = document.getElementById('wa-selected-phone').value;
        if (!phoneId) { say('Önce işletme numarasını seçin.'); return; }
        select.disabled = true;
        try {
            const data = await post(settings.phoneUrl, { phone_number_id: phoneId });
            window.location.assign(data.redirect);
        } catch (error) { say(error.message); select.disabled = false; }
    });
})();
</script>
@endcomponent
