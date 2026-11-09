{{-- Moxi, the corner assistant (yakup 2026-11-09). The corner button is a chat icon made from the Moximu cone (no character); sounds and speech: operator/partials/moxi-script. --}}
<div class="mx-root @if ($alert) has-alert @endif" x-data="moxiChat()" x-init="boot()"
    @moxi-think.window="think()" @moxi-said.window="said($event.detail)" @moxi-happy.window="happy($event.detail)"
    data-rank="{{ $rank['level'] ?? 1 }}" data-rank-name="{{ $rank['name'] ?? 'Çırak' }}" data-cards="{{ $doneCards }}"
    wire:poll.{{ $busy ? '2s' : ($waitingJobs ? '30s' : '90s') }}="refresh" data-moxi>
    <style>
/* Moxi'nin açma düğmesi (yakup 2026-11-09 "logodaki huniyle bütünleşmiş chat ikonu, karakter yok"): Moximu işaretinin
   sarı karesi, içinde tek bir huni (yukarıdan bakılan koni, logodaki gibi açıdan renk geçişi) kuyruklu bir sohbet balonu
   olur; ince sarı çizgi koninin sırtıdır. Düşünürken huni döner, konuşurken nefes alır, uykuda söner. */
@property --mxa{syntax:'<angle>'; inherits:true; initial-value:0deg}
.mx-cone{position:relative; display:inline-block; width:56px; height:56px; flex:none}
.mx-cone i{position:absolute; left:0; top:0; width:56px; height:56px; background:conic-gradient(from var(--mxa) at 30px 26px, #0b302e, #155552 30%, #1f6461 55%, #2e8a85 80%, #4fb0a8 98%, #0b302e);
  clip-path:path('M7.4 34.2 A24 24 0 1 1 19.9 47.7 L4 54 Z')}
.mx-cone b{position:absolute; left:29.3px; top:2px; width:1.4px; height:24px; background:#FDCF3C; transform-origin:50% 100%; transform:rotate(var(--mxa)); border-radius:1px}
.mx-cone.sm{width:22px; height:22px} .mx-cone.sm i,.mx-cone.sm b{zoom:.393}
.mx-cone.is-typing,.mx-launch.think .mx-cone{animation:mxspin 1.4s linear infinite}
@keyframes mxspin{to{--mxa:360deg}}
.mx-launch{position:relative; display:grid; place-items:center; width:64px; height:64px; margin:0 0 0 auto; padding:0; border:0; border-radius:18px; background:#FDCF3C; cursor:pointer;
  box-shadow:0 10px 26px rgba(15,61,58,.25); transition:transform .18s ease, box-shadow .18s ease}
.mx-launch > .mx-cone{margin:4px 0 0 4px; transition:opacity .15s, transform .2s}
.mx-launch:hover{transform:translateY(-2px); box-shadow:0 14px 30px rgba(15,61,58,.3)}
.mx-launch:focus-visible{outline:3px solid #1f6461; outline-offset:3px}
.mx-launch .mx-x{position:absolute; inset:0; display:grid; place-items:center; color:#0f3d3a; opacity:0; transition:opacity .15s}
.mx-launch.is-open > .mx-cone{opacity:0; transform:scale(.6)} .mx-launch.is-open .mx-x{opacity:1}
.mx-launch.talk > .mx-cone{animation:mxbreath 1.1s ease-in-out infinite}
@keyframes mxbreath{50%{transform:scale(1.06)}}
.mx-launch.happy,.mx-launch.bow,.mx-launch.wake{animation:mxpop .6s ease-out}
@keyframes mxpop{40%{transform:scale(1.12)}}
.mx-launch.confused > .mx-cone{animation:mxshake .4s ease-in-out 2}
@keyframes mxshake{25%{transform:translateX(-3px)}75%{transform:translateX(3px)}}
.mx-launch.sleep{filter:saturate(.4); opacity:.8}
.mx-tip{position:absolute; right:74px; top:50%; translate:0 -50%; white-space:nowrap; background:#0f3d3a; color:#fff; font-size:13px; font-weight:500; padding:7px 11px; border-radius:10px;
  opacity:0; pointer-events:none; transition:opacity .15s}
.mx-launch:hover .mx-tip,.mx-launch:focus-visible .mx-tip{opacity:1}
.mx-launch.is-open .mx-tip{display:none}
.mx-alert-dot{position:absolute; top:-3px; right:-3px; width:14px; height:14px; border-radius:50%; background:#e2553f; border:2px solid #fff; display:none}
.mx-root.has-alert .mx-alert-dot{display:block}
.mx-typing .mx-cone{margin:2px}
.mx-bubble .mx-cone{margin-top:1px}
@keyframes dot{0%,100%{transform:translateY(0);opacity:.4}40%{transform:translateY(-6px);opacity:1}}
@media (prefers-reduced-motion: reduce){ .mx-cone,.mx-launch{animation:none!important} }
[x-cloak]{display:none!important}
.mx-root{position:fixed; right:max(12px, env(safe-area-inset-right, 0px)); bottom:max(6px, env(safe-area-inset-bottom, 0px)); z-index:9000; font-size:14px; line-height:1.45; color:#1f2a2a}
.mx-panel{position:absolute; right:0; bottom:66px; width:min(390px, calc(100vw - 24px)); height:min(600px, calc(100vh - 140px)); display:flex; flex-direction:column;
  background:#fff; border:1px solid #e6e3d6; border-radius:16px; box-shadow:0 18px 50px rgba(20,40,38,.18); overflow:hidden}
.mx-head{display:flex; align-items:center; gap:8px; padding:10px 12px; border-bottom:1px solid #eeebe0}
.mx-head b{font-size:16px; font-weight:700}
.mx-head .mx-status{color:#6b7473; font-size:12px; flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis}
.mx-icon{border:1px solid #e6e3d6; background:#fff; border-radius:8px; padding:4px 8px; font-size:12px; color:#1f2a2a; cursor:pointer; white-space:nowrap}
.mx-icon:hover{background:#f6f5ef}
.mx-log{flex:1; overflow-y:auto; padding:12px; display:flex; flex-direction:column; gap:9px; background:#fbfaf6}
.mx-empty{color:#6b7473; font-size:13px; text-align:center; padding:24px 10px}
.mx-msg{max-width:88%; padding:8px 11px; border-radius:14px; overflow-wrap:anywhere}
.mx-me{align-self:flex-end; background:#1f6461; color:#fff; border-bottom-right-radius:4px}
.mx-bot{align-self:flex-start; background:#fff; border:1px solid #eeebe0; border-bottom-left-radius:4px}
.mx-bot .moxi-link{color:#1f6461; font-weight:600; text-decoration:underline}
.mx-card{align-self:stretch; background:#fff; border:1px solid #e6e3d6; border-radius:12px; padding:10px 12px; display:grid; gap:6px}
.mx-card.audit{border-color:#FDCF3C; box-shadow:inset 3px 0 0 #FDCF3C}
.mx-card.request{border-style:dashed}
.mx-k{font-size:11px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:#1f6461}
.mx-card ul{margin:0; padding-left:16px; display:grid; gap:2px}
.mx-acts{display:flex; gap:6px; flex-wrap:wrap; align-items:center}
.mx-btn{border:1px solid #e6e3d6; background:#fff; border-radius:8px; padding:5px 11px; font-weight:600; font-size:13px; cursor:pointer; color:#1f2a2a}
.mx-btn.pri{background:#1f6461; border-color:#1f6461; color:#fff}
.mx-btn:disabled{opacity:.5; cursor:default}
.mx-tag{font-size:12px; color:#6b7473}
.mx-tag.ok{color:#1f6461} .mx-tag.bad{color:#b4412b}
.mx-dots{display:flex; gap:4px; padding:4px 2px} .mx-dots i{width:7px; height:7px; border-radius:50%; background:#1f6461; animation:dot 1s infinite}
.mx-dots i:nth-child(2){animation-delay:.15s} .mx-dots i:nth-child(3){animation-delay:.3s}
.mx-form{display:flex; gap:8px; padding:10px 12px; border-top:1px solid #eeebe0; background:#fff}
.mx-form textarea{flex:1; min-width:0; resize:none; font:inherit; padding:8px 10px; border-radius:10px; border:1px solid #e6e3d6; background:#fbfaf6; color:#1f2a2a; max-height:120px}
.mx-form textarea:focus{outline:2px solid #FDCF3C; outline-offset:1px}
@media (max-width:480px){ .mx-panel{height:calc(100vh - 96px)} }
.mx-bubble{display:flex; gap:9px; align-items:flex-start}
.mx-bubble-t{display:block}
.mx-rank{font-size:11px; font-weight:700; color:#1f6461; background:#e8f3f1; border-radius:20px; padding:2px 8px; white-space:nowrap}
/* konuşma balonu */
.mx-bubble{position:absolute; right:0; bottom:66px; width:max-content; max-width:min(260px, calc(100vw - 90px)); background:#fff; color:#1f2a2a; border:2px solid #1f6461;
  border-radius:14px 14px 4px 14px; padding:9px 12px; font-size:13px; font-weight:500; box-shadow:0 8px 24px rgba(20,40,38,.18); cursor:pointer; text-align:left}
.mx-bubble.tip{border-color:#FDCF3C; box-shadow:inset 3px 0 0 #FDCF3C, 0 8px 24px rgba(20,40,38,.18)}
.mx-bubble.milestone{border-color:#c8452c}
.mx-bubble small{display:block; font-weight:500; color:#1f6461; margin-top:2px}
/* defter */
.mx-book{flex:1; overflow-y:auto; padding:12px; background:#fbfaf6; display:grid; gap:8px; align-content:start}
.mx-book li{display:flex; gap:8px; align-items:flex-start; background:#fff; border:1px solid #eeebe0; border-radius:10px; padding:7px 10px; list-style:none}
.mx-book ul{margin:0; padding:0; display:grid; gap:6px}
.mx-book li span{flex:1}
.mx-book li em{font-style:normal; font-size:11px; font-weight:700; color:#1f6461; text-transform:uppercase; letter-spacing:.04em}
.mx-book li button{border:0; background:none; color:#b4412b; cursor:pointer; font-size:13px}

    </style>

    <section class="mx-panel" x-show="open" x-cloak x-transition.origin.bottom.right role="dialog" aria-label="Moxi">
        <header class="mx-head">
            <b>Moxi</b>
            <span class="mx-rank" title="{{ $rank ? $rank['points'].' puan'.($rank['next'] !== null ? ' · '.$rank['next_name'].' için '.$rank['next'] : '') : '' }}">{{ $rank['name'] ?? 'Çırak' }}</span>
            <span class="mx-status" x-text="status">{{ $busy ? 'düşünüyor…' : 'hazır' }}</span>
            <button type="button" class="mx-icon" @click="book = !book" :aria-pressed="book" title="Moxi'nin defteri: senden öğrendikleri">Defter</button>
            <button type="button" class="mx-icon" @click="toggleBubbles()" x-text="bubbles ? 'Balon: açık' : 'Balon: kapalı'" title="Ara sıra konuşma balonları"></button>
            <button type="button" class="mx-icon" @click="cycleVoice()" x-text="voiceLabel()" title="Ses: konuşma, mırıltı ya da sessiz"></button>
            <button type="button" class="mx-icon" wire:click="clear" title="Ekranı temizle (Moxi yine hatırlar)">Temizle</button>
            <button type="button" class="mx-icon" @click="toggle()" aria-label="Kapat">✕</button>
        </header>

        <div class="mx-book" x-show="book" x-cloak>
            <div class="mx-tag">Moxi'nin senden öğrendikleri. Her cevapta bunlara uyar; yanlışsa ✕ ile unuttur.</div>
            @if ($notebook === [])
                <div class="mx-empty">Defter boş. "Bundan sonra fiyatları +KDV yaz" gibi şeyler söyledikçe buraya not eder.</div>
            @else
                <ul>
                    @foreach ($notebook as $note)
                        <li wire:key="mx-note-{{ $note['id'] }}"><span><em>{{ ['bilgi' => 'Bilgi', 'tercih' => 'Tercih', 'ders' => 'Ders'][$note['kind']] ?? 'Bilgi' }}</em><br>{{ $note['text'] }}</span>
                            <button type="button" wire:click="forget({{ $note['id'] }})" title="Unut" aria-label="Unut">✕</button></li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="mx-log" x-ref="log" x-show="!book">
            @forelse ($rows as $row)
                @switch($row->role)
                    @case('user')
                        <div class="mx-msg mx-me" wire:key="mx-{{ $row->id }}">{{ $row->body }}</div>
                        @break
                    @case('moxi')
                        <div class="mx-msg mx-bot" wire:key="mx-{{ $row->id }}">{!! \App\Livewire\Operator\Moxi::html((string) $row->body) !!}</div>
                        @break
                    @case('card')
                        <div class="mx-card" wire:key="mx-{{ $row->id }}">
                            <span class="mx-k">{{ $row->body }}</span>
                            <ul>@foreach ((array) data_get($row->meta, 'lines', []) as $line)<li>{{ $line }}</li>@endforeach</ul>
                            <div class="mx-acts">
                                @if ($row->status === 'pending')
                                    <button type="button" class="mx-btn pri" wire:click="confirm({{ $row->id }})" wire:loading.attr="disabled">Yap</button>
                                    <button type="button" class="mx-btn" wire:click="cancel({{ $row->id }})">Vazgeç</button>
                                @elseif ($row->status === 'done')
                                    <span class="mx-tag ok">✓ {{ data_get($row->meta, 'result', 'Yapıldı') }}</span>
                                    @if (data_get($row->meta, 'url'))<a class="moxi-link mx-tag ok" href="{{ data_get($row->meta, 'url') }}" wire:navigate>Aç</a>@endif
                                @elseif ($row->status === 'failed')
                                    <span class="mx-tag bad">Yapılamadı: {{ data_get($row->meta, 'result') }}</span>
                                @else
                                    <span class="mx-tag">Vazgeçildi</span>
                                @endif
                            </div>
                        </div>
                        @break
                    @case('request')
                        <div class="mx-card request" wire:key="mx-{{ $row->id }}">
                            <span class="mx-k">Claude'a iş</span>
                            <div><strong>{{ $row->body }}</strong></div>
                            @if ($row->status === 'done')
                                <div class="mx-msg mx-bot" style="max-width:100%">{!! \App\Livewire\Operator\Moxi::html((string) data_get($row->meta, 'answer', '')) !!}</div>
                            @else
                                <span class="mx-tag">Claude'un sıradaki turunda yapılacak (08:27, 12:27, 16:27, 21:27).</span>
                            @endif
                        </div>
                        @break
                    @case('audit')
                        <div class="mx-card audit" wire:key="mx-{{ $row->id }}">
                            <span class="mx-k">Moxi'nin önerisi</span>
                            <div><strong>{{ $row->body }}</strong></div>
                            <div class="mx-tag">{{ \Illuminate\Support\Str::limit((string) data_get($row->meta, 'detail'), 280) }}</div>
                            <div class="mx-acts">
                                @if ($row->status === 'pending')
                                    <button type="button" class="mx-btn pri" wire:click="decide({{ $row->id }}, true)">Onayla</button>
                                    <button type="button" class="mx-btn" wire:click="decide({{ $row->id }}, false)">Sonra</button>
                                @elseif ($row->status === 'done')
                                    <span class="mx-tag ok">✓ Geliştirme havuzunda</span>
                                @else
                                    <span class="mx-tag">Havuzda bekliyor</span>
                                @endif
                                <a class="moxi-link mx-tag ok" href="{{ route('operator.settings.improvements') }}" wire:navigate>Havuz</a>
                            </div>
                        </div>
                        @break
                @endswitch
            @empty
                <div class="mx-empty">Selam! Marka durumu sorabilir, link isteyebilir ya da iş verebilirsin.<br>Örnek: "Gelen kutusu linki", "Portakal Diş'in durumu ne?", "Ayşe Hanım'ı müşteri yap, 14.000, 6 ay".</div>
            @endforelse
            @if ($busy)
                <div class="mx-msg mx-bot mx-typing" aria-label="Moxi yazıyor">@include('operator.partials.moxi-cone', ['class' => 'sm is-typing'])</div>
            @endif
        </div>

        <form class="mx-form" @submit.prevent="submit()">
            <textarea rows="1" wire:model="text" x-ref="input" placeholder="Moxi'ye yaz…" aria-label="Moxi'ye mesaj"
                @input="typing()" @keydown.enter.prevent="if (! $event.shiftKey) $el.form.requestSubmit()" @if ($busy) disabled @endif></textarea>
            <button type="submit" class="mx-btn pri" @if ($busy) disabled @endif>Gönder</button>
        </form>
    </section>

    <div wire:ignore>
        <button type="button" class="mx-bubble" x-show="bubbleText && !open" x-cloak x-transition.origin.bottom.right :class="bubbleKind" @click="bubbleClick()">
            @include('operator.partials.moxi-cone', ['class' => 'sm'])<span class="mx-bubble-t"><span x-text="bubbleText"></span><small x-show="bubbleUrl">Göster →</small></span>
        </button>
    </div>

    <button type="button" class="mx-mascot mx-launch" :class="[open ? 'is-open' : '', mood]" wire:ignore @click="tap()" :aria-label="open ? 'Moxi\'yi kapat' : 'Moxi\'ye sor'" aria-label="Moxi'ye sor">
        @include('operator.partials.moxi-cone')
        <span class="mx-x" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg></span>
        <span class="mx-tip">Moxi'ye sor</span>
        <span class="mx-alert-dot"></span>
    </button>
</div>
