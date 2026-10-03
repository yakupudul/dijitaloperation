{{-- Phone / browser notifications (Web Push): only the important work alerts (site down, ad account stopped, bad review …). --}}
<div wire:ignore x-data="moxdopPush({ key: @js(route('push.key')), subscribe: @js(route('push.subscribe')), unsubscribe: @js(route('push.unsubscribe')), test: @js(route('push.test')) })" x-init="init()" class="flex items-center gap-2 text-xs" data-push-toggle>
    <template x-if="state === 'unsupported'">
        <span class="text-gray-500" x-text="hint"></span>
    </template>
    <template x-if="state === 'off' || state === 'denied'">
        <button type="button" x-on:click="enable()" :disabled="state === 'denied' || busy" class="rounded-lg px-3 py-2 font-semibold ring-1 ring-inset ring-gray-300 disabled:opacity-50 dark:ring-gray-700">Telefona bildirim aç</button>
    </template>
    <template x-if="state === 'on'">
        <span class="flex items-center gap-2">
            <span class="rounded-full bg-emerald-50 px-2 py-1 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">Bildirim açık</span>
            <button type="button" x-on:click="sendTest()" :disabled="busy" class="text-gray-500 hover:underline">Dene</button>
            <button type="button" x-on:click="disable()" :disabled="busy" class="text-gray-500 hover:underline">Kapat</button>
        </span>
    </template>
    <span x-show="note !== ''" x-text="note" class="text-gray-500"></span>
</div>

@once
<script>
    window.moxdopPush = (urls) => ({
        state: 'off', busy: false, note: '', hint: '',
        csrf() { return document.querySelector('meta[name="csrf-token"]')?.content ?? ''; },
        async post(url, body) {
            const response = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf() }, body: JSON.stringify(body ?? {}) });
            if (!response.ok) { throw new Error('HTTP ' + response.status); }
            return response.json().catch(() => ({}));
        },
        async init() {
            const ios = /iphone|ipad|ipod/i.test(navigator.userAgent);
            const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
            if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
                this.state = 'unsupported';
                this.hint = ios && !standalone ? 'iPhone: Safari’de Paylaş › Ana Ekrana Ekle, sonra oradan açıp bildirimi aç.' : 'Bu tarayıcı bildirim desteklemiyor.';
                return;
            }
            if (Notification.permission === 'denied') { this.state = 'denied'; this.note = 'Bildirim izni tarayıcı ayarlarında kapalı.'; return; }
            const registration = await navigator.serviceWorker.register('/sw.js');
            const existing = await registration.pushManager.getSubscription();
            this.state = existing ? 'on' : 'off';
            if (existing) { this.post(urls.subscribe, existing.toJSON()).catch(() => {}); }
        },
        key(base64) {
            const padded = (base64 + '='.repeat((4 - base64.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/');
            return Uint8Array.from(atob(padded), (c) => c.charCodeAt(0));
        },
        async enable() {
            this.busy = true; this.note = '';
            try {
                const permission = await Notification.requestPermission();
                if (permission !== 'granted') { this.state = permission === 'denied' ? 'denied' : 'off'; this.note = 'İzin verilmedi.'; return; }
                const registration = await navigator.serviceWorker.register('/sw.js');
                await navigator.serviceWorker.ready;
                const { key } = await (await fetch(urls.key, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })).json();
                const subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: this.key(key) });
                await this.post(urls.subscribe, subscription.toJSON());
                this.state = 'on'; this.note = 'Bu cihaza önemli iş bildirimleri gelecek.';
            } catch (error) {
                this.note = 'Açılamadı: ' + error.message;
            } finally { this.busy = false; }
        },
        async disable() {
            this.busy = true;
            try {
                const registration = await navigator.serviceWorker.getRegistration('/sw.js');
                const subscription = registration ? await registration.pushManager.getSubscription() : null;
                if (subscription) { await this.post(urls.unsubscribe, { endpoint: subscription.endpoint }); await subscription.unsubscribe(); }
                this.state = 'off'; this.note = 'Bu cihazda kapatıldı.';
            } catch (error) { this.note = 'Kapatılamadı: ' + error.message; } finally { this.busy = false; }
        },
        async sendTest() {
            this.busy = true;
            try { const result = await this.post(urls.test); this.note = result.sent > 0 ? 'Deneme bildirimi gönderildi.' : 'Gönderilemedi; bildirimi kapatıp yeniden açın.'; }
            catch (error) { this.note = 'Gönderilemedi: ' + error.message; } finally { this.busy = false; }
        },
    });
</script>
@endonce
