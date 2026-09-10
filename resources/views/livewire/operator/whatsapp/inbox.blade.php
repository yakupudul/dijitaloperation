<div class="space-y-5" @if(!$showSettings) wire:poll.10s @endif>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">WhatsApp Asistanı</h1>
            <p class="mt-1 text-sm text-gray-500">Görüşmeyi oku, öneriyi kontrol et, cevabını kopyala.</p>
        </div>
        <button type="button" wire:click="$toggle('showSettings')" class="rounded-lg border border-gray-300 px-4 py-2 text-sm dark:border-gray-700 dark:text-gray-200">{{ $showSettings ? 'Ayarları gizle' : 'Bağlantı ve hizmet bilgileri' }}</button>
    </div>
    @if($notice)
        <p role="status" class="rounded-lg bg-blue-50 p-3 text-sm text-blue-800 dark:bg-blue-900/20 dark:text-blue-200">{{ $notice }}</p>
    @endif
    @if($errors->any())
        <div role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-800">
            @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif
    @if($showSettings)
        <section class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">
            <h2 class="mb-3 text-lg font-semibold">Meta Cloud API bağlantısı</h2>
            <p class="mb-4 text-sm text-gray-500">Mevcut WhatsApp Business API hesabının bilgilerini girin. Bu ekran yeni numara kaydı veya Coexistence başvurusu yapmaz.</p>
            <form class="space-y-4" autocomplete="off" x-data="{ saving: false, saveError: '', saveSuccess: '', dirty: false, entered: {}, visible: {} }" @input="dirty = true; saveSuccess = ''" @change="dirty = true; saveSuccess = ''"
                @submit.prevent="
                    if (saving) return;
                    saving = true; saveError = ''; saveSuccess = '';
                    try {
                        const saved = await $wire.saveSettings({
                            access_token: $refs.accessToken.value,
                            app_secret: $refs.appSecret.value,
                            verify_token: $refs.verifyToken.value
                        });
                        if (saved === true) {
                            $refs.accessToken.value = '';
                            $refs.appSecret.value = '';
                            $refs.verifyToken.value = '';
                            for (const input of [$refs.accessToken, $refs.appSecret, $refs.verifyToken]) input.dispatchEvent(new Event('input'));
                            visible = {};
                            dirty = false;
                            saveSuccess = 'Ayarlar kaydedildi. Gizli bilgiler kayıtlı; tekrar girmeniz gerekmiyor.';
                        } else {
                            saveError = 'Kaydedilemedi. İşaretli alanları düzeltin. Girdiğiniz bilgiler korunuyor.';
                        }
                    } catch (error) {
                        saveError = 'Kayıt tamamlanamadı. Girdiğiniz değerler bu formda duruyor; tekrar deneyin.';
                    } finally { saving = false; }
                ">
                <p x-cloak x-show="saveError" x-text="saveError" role="alert" class="text-sm text-red-600"></p>
                <p x-cloak x-show="dirty" role="status" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Kaydedilmemiş değişiklikler var. API kontrolünden önce ayarları kaydedin.</p>
                <p class="text-sm text-gray-600 dark:text-gray-300">Bu bölüm yalnız WhatsApp uygulamanıza aittir. Meta App Secret alanına ayrı oluşturduğunuz WhatsApp uygulamasının secret bilgisini girin.</p>
                <fieldset :disabled="saving" class="space-y-4">
                <div class="grid gap-4 md:grid-cols-3">
                    <div class="text-sm">
                        <label for="wa-waba_id">WhatsApp Business Account ID (WABA)</label>
                        <input id="wa-waba_id" wire:model="waba_id" required inputmode="numeric" class="mt-1 w-full rounded-lg border border-gray-300 bg-transparent p-2" />
                        <p class="mt-1 text-xs text-gray-500">Kayıtlı değer: {{ $config['waba_id'] ?? 'Henüz kayıtlı değil' }}</p>
                        @error('waba_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="text-sm">
                        <label for="wa-phone_number_id">Phone Number ID</label>
                        <input id="wa-phone_number_id" wire:model="phone_number_id" required inputmode="numeric" class="mt-1 w-full rounded-lg border border-gray-300 bg-transparent p-2" />
                        <p class="mt-1 text-xs text-gray-500">Kayıtlı değer: {{ $config['phone_number_id'] ?? 'Henüz kayıtlı değil' }}</p>
                        @error('phone_number_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="text-sm">
                        <label for="wa-business_phone">İşletme numarası (ülke koduyla, yalnız rakam)</label>
                        <input id="wa-business_phone" wire:model="business_phone" required inputmode="numeric" class="mt-1 w-full rounded-lg border border-gray-300 bg-transparent p-2" />
                        <p class="mt-1 text-xs text-gray-500">Kayıtlı değer: {{ $config['business_phone'] ?? 'Henüz kayıtlı değil' }}</p>
                        @error('business_phone')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="text-sm">
                        <label for="wa-access_token">Access Token</label>
                        <div wire:ignore>
                            <div class="mt-1 flex gap-2">
                                <input id="wa-access_token" type="password" :type="visible.access_token ? 'text' : 'password'" x-ref="accessToken" @input="entered.access_token = $el.value.trim().length > 0" autocomplete="new-password" placeholder="Yeni değer girmek için kullanın" class="min-w-0 w-full rounded-lg border border-gray-300 bg-transparent p-2" />
                                <button type="button" @click="visible.access_token = !visible.access_token" :aria-pressed="!!visible.access_token" class="rounded-lg border border-gray-300 px-3 py-2" x-text="visible.access_token ? 'Gizle' : 'Göster'">Göster</button>
                            </div>
                            <p x-cloak x-show="entered.access_token" class="mt-1 text-xs font-medium text-amber-700">Yeni değer girildi — henüz kaydedilmedi.</p>
                        </div>
                        <p class="mt-2 inline-flex rounded-md px-2 py-1 text-xs font-semibold {{ $credentialStatus['access_token'] ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800' }}">{{ $credentialStatus['access_token'] ? 'Kayıtlı' : 'Henüz kayıtlı değil — gerekli' }}</p>
                        <p class="mt-1 text-xs text-gray-500">{{ $credentialStatus['access_token'] ? 'Güvenlik için gösterilmez. Boş bırakırsanız kayıtlı değer kullanılır.' : 'Bu bağlantı için değeri girip kaydedin.' }}</p>
                        @error('access_token')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="text-sm">
                        <label for="wa-app_secret">Meta App Secret</label>
                        <div wire:ignore>
                            <div class="mt-1 flex gap-2">
                                <input id="wa-app_secret" type="password" :type="visible.app_secret ? 'text' : 'password'" x-ref="appSecret" @input="entered.app_secret = $el.value.trim().length > 0" autocomplete="new-password" placeholder="Yeni değer girmek için kullanın" class="min-w-0 w-full rounded-lg border border-gray-300 bg-transparent p-2" />
                                <button type="button" @click="visible.app_secret = !visible.app_secret" :aria-pressed="!!visible.app_secret" class="rounded-lg border border-gray-300 px-3 py-2" x-text="visible.app_secret ? 'Gizle' : 'Göster'">Göster</button>
                            </div>
                            <p x-cloak x-show="entered.app_secret" class="mt-1 text-xs font-medium text-amber-700">Yeni değer girildi — henüz kaydedilmedi.</p>
                        </div>
                        <p class="mt-2 inline-flex rounded-md px-2 py-1 text-xs font-semibold {{ $credentialStatus['app_secret'] ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800' }}">{{ $credentialStatus['app_secret'] ? 'Kayıtlı' : 'Henüz kayıtlı değil — gerekli' }}</p>
                        <p class="mt-1 text-xs text-gray-500">{{ $credentialStatus['app_secret'] ? 'Güvenlik için gösterilmez. Boş bırakırsanız kayıtlı değer kullanılır.' : 'Bu bağlantı için değeri girip kaydedin.' }}</p>
                        @error('app_secret')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="text-sm">
                        <label for="wa-verify_token">Webhook Verify Token</label>
                        <div wire:ignore>
                            <div class="mt-1 flex gap-2">
                                <input id="wa-verify_token" type="password" :type="visible.verify_token ? 'text' : 'password'" x-ref="verifyToken" @input="entered.verify_token = $el.value.trim().length > 0" autocomplete="new-password" placeholder="Yeni değer girmek için kullanın" class="min-w-0 w-full rounded-lg border border-gray-300 bg-transparent p-2" />
                                <button type="button" @click="visible.verify_token = !visible.verify_token" :aria-pressed="!!visible.verify_token" class="rounded-lg border border-gray-300 px-3 py-2" x-text="visible.verify_token ? 'Gizle' : 'Göster'">Göster</button>
                            </div>
                            <p x-cloak x-show="entered.verify_token" class="mt-1 text-xs font-medium text-amber-700">Yeni değer girildi — henüz kaydedilmedi.</p>
                        </div>
                        <p class="mt-2 inline-flex rounded-md px-2 py-1 text-xs font-semibold {{ $credentialStatus['verify_token'] ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800' }}">{{ $credentialStatus['verify_token'] ? 'Kayıtlı' : 'Henüz kayıtlı değil — gerekli' }}</p>
                        <p class="mt-1 text-xs text-gray-500">{{ $credentialStatus['verify_token'] ? 'Güvenlik için gösterilmez. Boş bırakırsanız kayıtlı değer kullanılır.' : 'Bu bağlantı için değeri girip kaydedin.' }}</p>
                        @error('verify_token')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>
                <label class="block text-sm">AI için hizmetler, fiyatlar ve konuşma üslubu
                    <textarea wire:model="business_context" rows="5" maxlength="12000" required class="mt-1 w-full rounded-lg border border-gray-300 bg-transparent p-3"></textarea>
                </label>
                <div class="flex flex-wrap gap-5 text-sm">
                    <label class="flex items-center gap-2"><input type="checkbox" wire:model="enabled" /> Mesaj alımını etkinleştir</label>
                    <label class="flex items-center gap-2"><input type="checkbox" wire:model="automatic_suggestions" /> Yeni mesajlarda otomatik AI önerisi hazırla</label>
                </div>
                <p class="text-xs text-gray-500">Otomatik öneriler mevcut AI sağlayıcınızın API kullanımını oluşturur. Kapattığınızda görüşme içindeki düğmeyle öneri isteyebilirsiniz.</p>
                <p x-cloak x-show="saveSuccess" x-text="saveSuccess" role="status" class="rounded-lg bg-green-50 p-3 text-sm font-medium text-green-800"></p>
                <div class="flex flex-wrap gap-3">
                    <button type="submit" :disabled="saving" wire:loading.attr="disabled" class="rounded-lg bg-brand-500 px-4 py-2 text-sm text-white disabled:cursor-wait disabled:opacity-60" x-text="saving ? 'Kaydediliyor…' : 'Ayarları kaydet'">Ayarları kaydet</button>
                    <button type="button" wire:click="checkConnection" @click.capture="if (saving || dirty) { $event.preventDefault(); $event.stopImmediatePropagation(); }" :disabled="saving || dirty" wire:loading.attr="disabled" class="rounded-lg border border-gray-300 px-4 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-50"><span wire:loading.remove wire:target="checkConnection">API erişimini kontrol et</span><span wire:loading wire:target="checkConnection">Kontrol sıraya alınıyor…</span></button>
                    <button type="button" wire:click="refreshConnectionStatus" wire:loading.attr="disabled" class="rounded-lg border border-gray-300 px-4 py-2 text-sm disabled:opacity-60"><span wire:loading.remove wire:target="refreshConnectionStatus">Kontrol sonucunu yenile</span><span wire:loading wire:target="refreshConnectionStatus">Yenileniyor…</span></button>
                    <a href="{{ url('/integrations/openai') }}" class="px-3 py-2 text-sm text-brand-500">Mevcut AI bağlantısı</a>
                </div>
                </fieldset>
            </form>
            <div @if(($config['connection_check'] ?? '') === 'queued') wire:poll.5s @endif class="mt-5 space-y-2 border-t border-gray-200 pt-4 text-sm dark:border-gray-700">
                <p>Meta Callback URL:</p>
                <input aria-label="Meta Callback URL" readonly value="{{ route('api.whatsapp.webhook') }}" class="w-full rounded-lg border border-gray-300 bg-transparent p-2 font-mono text-xs" onclick="this.select()" />
                <p class="text-gray-500">Meta'da bu adresi ve aynı Verify Token'ı kaydedin. <code>messages</code> alanına abone olun. Uygulamayla birlikte kullanım destekleniyorsa <code>smb_message_echoes</code> ve <code>history</code> alanlarını da bağlayın.</p>
                <p>API kontrolü: {{ ['queued' => 'Kontrol sırada / çalışıyor; sonuç otomatik yenileniyor', 'dispatch_failed' => 'Kontrol başlatılamadı; tekrar deneyin', 'verified' => 'Numara erişimi doğrulandı', 'phone_mismatch' => 'İşletme numarası eşleşmiyor', 'failed' => 'Erişim doğrulanamadı; token, sürüm ve numara yetkisini kontrol edin'][($config['connection_check'] ?? '')] ?? 'Henüz kontrol edilmedi' }}</p>
                @if(!empty($config['connection_checked_at']))
                    <p class="text-xs text-gray-500">Son kontrol: {{ \Carbon\CarbonImmutable::parse($config['connection_checked_at'])->timezone('Europe/Istanbul')->format('d.m.Y H:i:s') }}</p>
                @endif
                @if(($config['connection_check'] ?? '') === 'queued' && !empty($config['connection_check_requested_at']) && \Carbon\CarbonImmutable::parse($config['connection_check_requested_at'])->lessThan(now()->subMinutes(2)))
                    <p role="alert" class="text-sm text-amber-800">Kontrol henüz sonuçlanmadı. API erişimini kontrol et düğmesiyle yeniden deneyin; sürerse Horizon kuyruk hizmetini kontrol edin.</p>
                @endif
                @if(!empty($config['settings_saved_at']))
                    <p class="text-xs text-gray-500">Son kayıt: {{ \Carbon\CarbonImmutable::parse($config['settings_saved_at'])->timezone('Europe/Istanbul')->format('d.m.Y H:i:s') }}</p>
                @endif
                <p class="text-xs text-gray-500">API erişim kontrolü geçmiş mesajların aktarılmış olduğunu veya webhook aboneliğinin tamamlandığını doğrulamaz.</p>
            </div>
        </section>
    @endif
    <div class="rounded-lg border border-gray-200 p-3 text-xs text-gray-600 dark:border-gray-700 dark:text-gray-400">
        <span>{{ $integration?->isActive() ? 'Mesaj alımı açık.' : 'Mesaj alımı kapalı veya bağlantı henüz kurulmadı.' }}</span>
        <span>Geçmiş: {{ ($config['history_state'] ?? '') === 'provider_error' ? 'Meta geçmiş paylaşım hatası bildirdi.' : (($config['history_state'] ?? '') === 'received_partial' ? 'Geçmiş parçaları alındı; eksiksiz geçmiş doğrulanmadı.' : 'Geçmiş aktarımı henüz görülmedi.') }}</span>
        <span>{{ !empty($config['echo_seen_at']) ? 'Telefondan gönderilen mesaj olayı alındı.' : 'Telefondan gönderdiğiniz cevapların aktarımı henüz doğrulanmadı.' }}</span>
        <span>Görseller ve ses kayıtlarının içeriği okunmaz. Saatler Türkiye saatidir.</span>
    </div>
    <div class="grid gap-4 xl:grid-cols-12">
        <section class="min-w-0 rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900 xl:col-span-3">
            <div class="border-b border-gray-200 p-4 dark:border-gray-800">
                <h2 class="mb-3 font-semibold dark:text-gray-200">Görüşmeler · {{ $rows->total() }}</h2>
                <input aria-label="Görüşme ara" wire:model.live.debounce.400ms="q" maxlength="100" placeholder="İsim veya numara ara" class="w-full rounded-lg border border-gray-300 bg-transparent p-2 text-sm dark:text-gray-200" />
            </div>
            <div class="max-h-[600px] overflow-y-auto">
                @forelse($rows as $row)
                    <button type="button" wire:key="chat-{{ $row->id }}" wire:click="selectConversation({{ $row->id }})" class="block w-full border-b border-gray-100 p-4 text-left hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800 {{ $selected?->id === $row->id ? 'bg-blue-50 dark:bg-gray-800' : '' }}">
                        <span class="block truncate font-medium text-gray-900 dark:text-white">{{ $row->contact_name ?: $row->contact_id }}</span>
                        <span class="mt-1 block text-xs text-gray-500">{{ $row->last_message_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}</span>
                        <span class="mt-2 block text-xs text-brand-500">{{ ['pending' => 'Yeni öneri bekliyor', 'requested' => 'Öneri sırada', 'running' => 'Öneri hazırlanıyor', 'ready' => 'Öneri hazır', 'failed' => 'Öneri hazırlanamadı'][$row->suggestion_status] ?? $row->suggestion_status }}</span>
                    </button>
                @empty
                    <p class="p-5 text-sm text-gray-500">Henüz görüşme yok. Bağlantıyı tamamlayın; alınan mesajlar burada listelenecek.</p>
                @endforelse
            </div>
            <div class="p-3">{{ $rows->links() }}</div>
        </section>
        <section class="min-w-0 rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900 xl:col-span-5">
            <div class="border-b border-gray-200 p-4 dark:border-gray-800">
                <h2 class="font-semibold dark:text-white">{{ $selected?->contact_name ?: ($selected?->contact_id ?: 'Mesajlar') }}</h2>
                @if($selected)<p class="mt-1 text-xs text-gray-500">{{ $selected->contact_id }} · {{ $messages->total() }} kayıtlı mesaj</p>@endif
            </div>
            <div class="max-h-[650px] min-h-[350px] space-y-3 overflow-y-auto p-4">
                @if($messages)
                    @foreach($messages->getCollection()->reverse() as $message)
                        <div wire:key="message-{{ $message->id }}" class="max-w-[95%] rounded-xl p-3 {{ $message->direction === 'outgoing' ? 'ml-auto bg-blue-50 dark:bg-blue-900/20' : 'mr-auto bg-gray-100 dark:bg-gray-800' }}">
                            <p class="mb-1 text-xs font-semibold text-gray-500">{{ $message->direction === 'outgoing' ? 'Siz' : 'Karşı taraf' }}</p>
                            @if($message->reply_to_message_id)<p class="mb-1 text-xs text-gray-500">Önceki bir mesaja yanıt</p>@endif
                            <p class="whitespace-pre-wrap break-words text-sm text-gray-800 dark:text-gray-200">{{ $message->body }}</p>
                            <p class="mt-2 text-right text-xs text-gray-500">{{ $message->sent_at->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}</p>
                        </div>
                    @endforeach
                @else
                    <p class="pt-12 text-center text-sm text-gray-500">Mesajları ve öneriyi görmek için bir görüşme seçin.</p>
                @endif
            </div>
            @if($messages)<div class="border-t border-gray-200 p-3 dark:border-gray-800"><p class="mb-2 text-xs text-gray-500">1. sayfada en yeni mesajlar gösterilir. Sonraki sayfalarda eski mesajlar bulunur.</p>{{ $messages->links() }}</div>@endif
        </section>
        <section class="min-w-0 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900 xl:col-span-4">
            <h2 class="font-semibold dark:text-white">Önerilen cevap</h2>
            @if($selected)
                @if($selected->suggestion_status === 'ready' && $selected->suggested_revision === $selected->revision)
                    <div wire:key="suggestion-{{ $selected->id }}-{{ $selected->suggested_at?->timestamp }}" class="mt-4 space-y-4" x-data="{ copyStatus: '' }">
                        <p class="text-sm text-gray-600 dark:text-gray-300">{{ $selected->summary }}</p>
                        @if($selected->suggestion_action === 'wait')
                            <p class="rounded-lg bg-amber-50 p-3 text-sm font-medium text-amber-900">Şimdilik yeni mesaj yazma.</p>
                        @endif
                        @if(filled($selected->suggestion))
                            <textarea x-ref="reply" aria-label="Önerilen cevap; kopyalamadan önce düzenleyebilirsiniz" rows="9" class="w-full rounded-lg border border-gray-300 bg-transparent p-3 text-sm dark:text-gray-200">{{ $selected->suggestion }}</textarea>
                            <button type="button" class="w-full rounded-lg bg-brand-500 px-4 py-3 text-sm font-medium text-white" @click="navigator.clipboard.writeText($refs.reply.value).then(() => copyStatus = 'Kopyalandı').catch(() => { $refs.reply.select(); copyStatus = 'Metni seçtim. Ctrl+C ile kopyalayın.' })">Cevabı kopyala</button>
                            <p x-text="copyStatus" role="status" class="text-xs text-gray-500"></p>
                        @endif
                        <p class="text-sm text-gray-600 dark:text-gray-300">{{ $selected->rationale }}</p>
                        <p class="text-xs text-gray-500">{{ $selected->context_message_count }} mesaj üzerinden hazırlandı. {{ $selected->context_truncated ? 'Bağlam sınırı nedeniyle önceki mesajların bir bölümü AI tarafından okunmadı.' : '' }} {{ $selected->suggested_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}</p>
                    </div>
                @else
                    <p class="my-4 text-sm text-gray-500">{{ $selected->suggestion_status === 'running' ? 'Öneri arka planda hazırlanıyor.' : ($selected->suggestion_status === 'failed' ? 'Öneri hazırlanamadı. AI bağlantısını kontrol edip tekrar deneyin.' : 'Bu görüşme için güncel öneri bekleniyor.') }}</p>
                    @if($selected->error_code)<p class="mb-3 text-xs text-gray-500">{{ $selected->error_code }}</p>@endif
                @endif
                <button type="button" wire:click="generate({{ $selected->id }})" wire:loading.attr="disabled" @disabled($selected->suggestion_status === 'running') class="mt-5 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:text-gray-200">{{ $selected->suggestion_status === 'ready' ? 'Öneriyi yeniden hazırla' : 'Öneri hazırla / tekrar dene' }}</button>
                <p class="mt-3 text-xs text-gray-500">Cevap otomatik gönderilmez. Kopyalayıp WhatsApp'tan gönderebilirsiniz. AI en yeni 200 mesajı, toplam 60.000 karakter sınırıyla okur.</p>
            @else
                <p class="mt-4 text-sm text-gray-500">Seçtiğiniz görüşmeye uygun cevap burada hazırlanacak.</p>
            @endif
        </section>
    </div>
    @if($showSettings)
        <details class="rounded-lg border border-gray-200 p-4 text-sm dark:border-gray-800 dark:text-gray-200">
            <summary class="cursor-pointer">Son 10 mesaj aktarımı</summary>
            @forelse($receipts as $receipt)
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 py-2 dark:border-gray-800">
                    <span>{{ $receipt->created_at->timezone('Europe/Istanbul')->format('d.m.Y H:i') }} · {{ $receipt->status }} · {{ $receipt->accepted_count }} yeni mesaj · {{ $receipt->ignored_count }} işlenemeyen/başka kapsamdaki öğe</span>
                    @if($receipt->status === 'failed')<button type="button" wire:click="retryReceipt({{ $receipt->id }})" class="text-brand-500">Tekrar işle</button>@endif
                </div>
            @empty
                <p class="mt-3 text-gray-500">Henüz doğrulanmış webhook alınmadı.</p>
            @endforelse
        </details>
    @endif
</div>

