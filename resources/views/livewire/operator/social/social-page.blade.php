@php
    // Sosyal medya (ADR-083). The question: "What goes out on Moximu's accounts in the coming weeks, and what waits for
    // my approval?" One card per post: visual, text, channels, time. A week is approved with one click.
    $chipClass = fn (string $status): string => match ($status) {
        'draft', 'failed', 'partial', 'manual' => 'tone-look',
        'published', 'done' => 'tone-ok',
        default => 'tone-info',
    };
    $tabs = ['plan' => 'Plan', 'gecmis' => 'Yayınlananlar', 'yeni' => 'Yeni gönderi', 'hesaplar' => 'Hesaplar'];
    $meta = $accounts->whereIn('platform', ['instagram', 'facebook']);
@endphp
<div class="dark:text-gray-200" data-social-page>
    <div class="space-y-8">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
                <p class="mb-1 text-xs text-gray-500"><a href="{{ route('operator.marketing', ['sekme' => 'sosyal']) }}" wire:navigate class="link-neutral" data-social-marketing-link>Pazarlama</a> › Sosyal medya</p>
                <h1 class="page-title">Sosyal medya</h1>
                <p class="page-sub">Instagram, Facebook ve LinkedIn. Onayladığınız gönderiler kendi saatinde yayınlanır{{ $drafts > 0 ? '; '.$drafts.' gönderi onayınızı bekliyor.' : '.' }}</p>
            </div>
        </header>

        <nav class="flex flex-wrap gap-1.5" role="tablist" aria-label="Sekmeler">
            @foreach ($tabs as $key => $label)
                <button type="button" wire:click="setTab('{{ $key }}')" @class(['seg', 'seg-active' => $tab === $key]) role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" @if ($tab === $key) aria-current="page" @endif>{{ $label }}</button>
            @endforeach
            {{-- Messages and comments are answered in the Gelen kutusu (Aşama 2). --}}
            <a href="{{ route('operator.inbox') }}" wire:navigate class="seg" data-social-inbox-link>Mesajlar{{ $waitingCount > 0 ? ' ('.$waitingCount.')' : '' }} →</a>
        </nav>

        @if ($message !== '')
            <p role="status" class="tone-info rounded-lg border border-gray-200 px-3.5 py-2.5 text-sm dark:border-gray-800">{{ $message }}</p>
        @endif

        @if ($meta->isEmpty() && $tab !== 'hesaplar')
            <p class="tone-look rounded-lg border border-gray-200 px-3.5 py-2.5 text-sm dark:border-gray-800">Instagram ve Facebook henüz bağlı değil; gönderiler hazırlanır ama yayınlanamaz. <button type="button" wire:click="setTab('hesaplar')" class="underline">Hesapları bağlayın</button>.</p>
        @endif

        @if ($tab === 'plan')
            @if ($manual->isNotEmpty())
                <section class="space-y-3" data-manual>
                    <h2 class="section-title">LinkedIn’de paylaşmanız bekleniyor</h2>
                    @foreach ($manual as $target)
                        <div class="card space-y-3 p-4" x-data="{ copied: false }">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-sm font-semibold">{{ $target->post?->title ?? 'Gönderi' }} · {{ $target->account?->displayName() }}</p>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" class="btn" x-on:click="navigator.clipboard.writeText($refs.text.value); copied = true"><span x-text="copied ? 'Kopyalandı' : 'Metni kopyala'"></span></button>
                                    @foreach ($target->post?->mediaItems() ?? [] as $i => $item)
                                        <a class="btn" href="{{ $item['url'] }}" download>{{ $item['type'] === 'video' ? 'Videoyu' : 'Görseli' }} indir{{ count($target->post->mediaItems()) > 1 ? ' '.($i + 1) : '' }}</a>
                                    @endforeach
                                    <a class="btn" href="{{ $target->account?->profileUrl() ?? 'https://www.linkedin.com/' }}" target="_blank" rel="noopener">LinkedIn’i aç ↗</a>
                                    @if ($canWrite)<button type="button" class="btn-primary" wire:click="markDone({{ $target->id }})">Paylaştım</button>@endif
                                </div>
                            </div>
                            <textarea x-ref="text" readonly rows="5" class="w-full rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-800 dark:bg-gray-900">{{ $target->post?->linkedin_text }}</textarea>
                        </div>
                    @endforeach
                </section>
            @endif

            @forelse ($weeks as $weekStart => $posts)
                @php
                    $start = \Illuminate\Support\Carbon::parse($weekStart, $tz);
                    $weekDrafts = $posts->where('status', 'draft')->count();
                @endphp
                <section class="space-y-3" data-week="{{ $weekStart }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="section-title">{{ $start->translatedFormat('j F') }} – {{ $start->copy()->addDays(6)->translatedFormat('j F') }} haftası · {{ $posts->count() }} gönderi</h2>
                        @if ($canWrite && $weekDrafts > 0)
                            <button type="button" class="btn-primary" x-on:click="if (confirm('Bu haftanın onay bekleyen {{ $weekDrafts }} gönderisi onaylansın mı? Her biri kendi saatinde yayınlanır.')) $wire.approveWeek('{{ $weekStart }}')">Haftayı onayla ({{ $weekDrafts }})</button>
                        @endif
                    </div>
                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($posts as $post)
                            @include('livewire.operator.social.partials.post-card', ['post' => $post])
                        @endforeach
                    </div>
                </section>
            @empty
                <x-ta.empty-state size="sm" title="Planlı gönderi yok." />
            @endforelse
        @elseif ($tab === 'gecmis')
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @forelse ($history as $post)
                    @include('livewire.operator.social.partials.post-card', ['post' => $post])
                @empty
                    <x-ta.empty-state size="sm" title="Henüz yayınlanan gönderi yok." />
                @endforelse
            </div>
        @elseif ($tab === 'yeni')
            <form wire:submit="createPost" class="card max-w-3xl space-y-4 p-5">
                <div class="grid gap-4 sm:grid-cols-3">
                    <label class="block text-sm">Tür
                        <select wire:model="newKind" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                            <option value="image">Gönderi (tek görsel)</option><option value="carousel">Kaydırmalı (2-10)</option><option value="reels">Reels (video)</option><option value="story">Story</option>
                        </select>
                    </label>
                    <label class="block text-sm">Başlık
                        <select wire:model="newPillar" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                            <option value="">—</option><option value="rehber">Rehber</option><option value="vaka">Vaka</option><option value="gundem">Gündem</option><option value="anlatim">Yakup anlatıyor</option><option value="guven">Güven</option>
                        </select>
                    </label>
                    <label class="block text-sm">Yayın zamanı
                        <input type="datetime-local" wire:model="newTime" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                    </label>
                </div>
                <label class="block text-sm">Kısa ad (yalnız burada görünür)
                    <input type="text" wire:model="newTitle" maxlength="190" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                </label>
                <label class="block text-sm">Görsel ya da video (jpg, png, mp4; kaydırmalıda sırayla)
                    <input type="file" wire:model="newFiles" multiple accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime" class="mt-1 block w-full text-sm">
                </label>
                @error('newFiles')<p class="text-sm text-error-600">{{ $message }}</p>@enderror
                @error('newFiles.*')<p class="text-sm text-error-600">{{ $message }}</p>@enderror
                <label class="block text-sm">Instagram ve Facebook metni
                    <textarea wire:model="newCaption" rows="6" maxlength="2200" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900"></textarea>
                </label>
                <label class="block text-sm">LinkedIn metni (boş bırakılırsa LinkedIn’e gitmez)
                    <textarea wire:model="newLinkedin" rows="6" maxlength="3000" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900"></textarea>
                </label>
                <div class="flex gap-2"><button type="submit" class="btn-primary" wire:loading.attr="disabled">Plana ekle</button><span wire:loading wire:target="newFiles" class="text-sm text-gray-500">Dosyalar yükleniyor…</span></div>
            </form>
        @else
            <section class="space-y-4" data-accounts>
                <div class="card divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($accounts as $account)
                        <div class="flex flex-wrap items-center justify-between gap-3 p-4">
                            <div class="flex min-w-0 items-center gap-3">
                                @if ($account->picture_url)<img src="{{ $account->picture_url }}" alt="" class="h-9 w-9 rounded-full">@endif
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold">{{ $account->platformLabel() }} · {{ $account->displayName() }}</p>
                                    <p class="text-xs text-gray-500">{{ $account->isManual() ? 'Kopyala ve aç ile paylaşılır' : 'Otomatik yayın' }}{{ $account->connected_at ? ' · bağlandı '.$account->connected_at->timezone($tz)->format('d.m.Y') : '' }}</p>
                                </div>
                            </div>
                            @if ($canWrite)<button type="button" wire:click="toggleAccount({{ $account->id }})" class="btn">{{ $account->enabled ? 'Kapat' : 'Aç' }}</button>@endif
                        </div>
                    @empty
                        <x-ta.empty-state size="sm" title="Bağlı hesap yok." />
                    @endforelse
                </div>

                @if ($canWrite)
                    <div class="card space-y-3 p-5">
                        <h2 class="section-title">Instagram ve Facebook</h2>
                        <p class="text-sm text-gray-600 dark:text-gray-300">Meta girişinde Moximu Facebook sayfasını ve ona bağlı Instagram işletme hesabını seçin. Reklam bağlantısından ayrıdır; ona dokunmaz.</p>
                        <a href="{{ route('integrations.meta.social.authorize') }}" class="btn-primary inline-flex">Meta’yı bağla</a>
                        <details class="text-sm">
                            <summary class="cursor-pointer text-gray-600 dark:text-gray-300">Meta uygulama ayarı (bir kez)</summary>
                            <div class="mt-2 space-y-2">
                                <p>Giriş şu uygulamayla açılıyor: <b>App ID {{ $metaAppId ?: 'tanımlı değil' }}</b> ({{ $ownSocialApp ? 'Entegrasyonlardaki WhatsApp uygulaması ya da aşağıda kaydedilen uygulama' : 'Entegrasyonlar › Meta’daki reklam uygulaması' }}). Yapılandırma başka bir uygulamada açılırsa Meta “Özellik kullanılmıyor” der.</p>
                                <p>WhatsApp entegrasyonunda App ID ve App Secret kayıtlıysa o uygulama kendiliğinden kullanılır; aşağıdaki alan yalnız başka bir uygulama için gerekir. Reklam bağlantısı değişmez.</p>
                                <div class="flex flex-wrap gap-2"><input type="text" wire:model="socialAppId" inputmode="numeric" placeholder="App ID" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900"><input type="password" wire:model="socialAppSecret" autocomplete="off" placeholder="{{ $ownSocialApp ? 'App Secret (kayıtlı, değiştirmek için yazın)' : 'App Secret' }}" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900"><button type="button" wire:click="saveSocialApp" class="btn">Uygulamayı kaydet</button></div>
                                @error('socialAppSecret')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                                <ol class="list-decimal space-y-1 pl-5">
                                    <li>Meta for Developers › bu uygulama › <b>Kullanım durumları › Add use cases</b>: “Manage everything on your Page” ve “Manage messaging &amp; content on Instagram” kullanım durumlarını ekleyin; sonra her birinde <b>Customize › Permissions</b>’ta şu izinleri <b>Add</b> deyin (uygulamada rolünüz olduğu için inceleme gerekmez): <code class="text-xs">{{ implode(', ', $scopes) }}</code>.</li>
                                    <li><b>Configurations › Create configuration</b>: ad “Moximu sosyal”, giriş çeşidi <b>General</b>, erişim anahtarı <b>User access token</b> (System-user değil), varlıklar <b>Pages</b> ve <b>Instagram accounts</b>, izinler yukarıdakiler. <b>WhatsApp izinlerini seçmeyin</b>; WhatsApp yapılandırmasının kimliği burada çalışmaz.</li>
                                    <li>Yeni yapılandırmanın <b>Configuration ID</b>’sini kopyalayıp buraya kaydedin, sonra “Meta’yı bağla”. Yönlendirme adresi zaten kayıtlı: <code class="text-xs">{{ $callbackUrl }}</code>.</li>
                                </ol>
                                <div class="flex flex-wrap gap-2"><input type="text" wire:model="configId" inputmode="numeric" placeholder="Yapılandırma kimliği" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900"><button type="button" wire:click="saveConfigId" class="btn">Kaydet</button></div>
                            </div>
                        </details>
                    </div>
                    <form wire:submit="addLinkedin" class="card space-y-3 p-5">
                        <h2 class="section-title">LinkedIn şirket sayfası</h2>
                        <p class="text-sm text-gray-600 dark:text-gray-300">LinkedIn otomatik paylaşım iznini onaylayana kadar gönderiler saatinde burada “kopyala ve aç” olarak belirir.</p>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <input type="text" wire:model="linkedinName" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900" placeholder="Ad">
                            <input type="url" wire:model="linkedinUrl" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900" placeholder="https://www.linkedin.com/company/…">
                        </div>
                        <button type="submit" class="btn">Ekle</button>
                    </form>
                @endif
            </section>
        @endif
    </div>
</div>
