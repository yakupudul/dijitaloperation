{{-- Moxi in the browser (yakup 2026-11-09): the corner icon's moods (the three logo cones look at the pointer, bounce while thinking, pulse while talking), sounds made on the fly (Web Audio, no files)
     and speech with the browser's own free Turkish voice. Ses modu (Konuşuyor / Mırıltı / Sessiz) and open/closed are
     remembered in this browser only. --}}
<script>
window.moxiChat = function () {
    const store = { get(k, d) { try { return localStorage.getItem('moxi.' + k) ?? d; } catch (e) { return d; } }, set(k, v) { try { localStorage.setItem('moxi.' + k, v); } catch (e) {} } };
    let AC = null;
    const ac = () => { if (!AC) { try { AC = new (window.AudioContext || window.webkitAudioContext)(); } catch (e) { return null; } } if (AC.state === 'suspended') AC.resume(); return AC; };
    return {
        open: store.get('open', '0') === '1',
        voice: store.get('voice', 'speak'),
        mood: '',
        status: 'hazır',
        last: Date.now(),
        timer: null,
        trVoice: null,
        speaking: false,
        book: false,
        menu: false,
        bubbles: store.get('bubbles', '1') === '1',
        bubbleText: '', bubbleUrl: null, bubbleKind: '', bubbleTimer: null,
        rank: 1,
        boot() {
            this.rank = +(this.$root.dataset.rank || 1);
            this.growth(true);
            // A bubble now and then: at most one every 12 minutes, never in the first 3, never while the chat is open,
            // while typing, while asleep or in a hidden tab.
            if (!store.get('bubbleAt')) store.set('bubbleAt', String(Date.now() - 9 * 60000));
            setInterval(() => this.maybeBubble(), 60000);
            document.addEventListener('pointerdown', ac, { once: true });
            try { const pick = () => { const vs = speechSynthesis.getVoices().filter(v => /^tr/i.test(v.lang)); this.trVoice = vs.find(v => /google|natural|online|neural/i.test(v.name)) || vs[0] || null; }; pick(); speechSynthesis.onvoiceschanged = pick; } catch (e) {}
            let frame = 0;
            document.addEventListener('pointermove', (e) => {
                if (frame) return;
                frame = requestAnimationFrame(() => {
                    frame = 0;
                    // The cone "looks" at the pointer: its ridge (the yellow line) turns toward it.
                    if (this.mood === 'sleep' || this.mood === 'listen') return;
                    this.look(e.clientX, e.clientY);
                });
            });
            setInterval(() => this.idle(), 9000);
            this.play('enter', 1200); setTimeout(() => this.play('bow', 1500), 1250);
            window.Livewire?.hook?.('commit', ({ succeed }) => succeed(() => queueMicrotask(() => { this.scroll(); this.growth(false); })));
            this.$nextTick(() => this.scroll());
        },
        growth(first) {
            const level = +(this.$root.dataset.rank || 1), name = this.$root.dataset.rankName || '', cards = +(this.$root.dataset.cards || 0);
            const was = +store.get('rank', '0'), wasCards = +store.get('cards', '-1');
            this.rank = level;
            store.set('rank', String(level)); store.set('cards', String(cards));
            if (was > 0 && level > was) { this.showBubble('Rütbe atladım: artık ' + name + '! Birlikte çok iş yaptık.', null, 'milestone'); this.sfx('sword'); this.play('happy', 1600); return; }
            if (wasCards < 0 || first) return;
            const marks = { 1: 'İlk işimizi birlikte yaptık! 🎉', 10: '10. iş tamam. Kılıç ısınıyor.', 50: '50 iş! Dojoda adımız konuşuluyor.', 100: '100. iş! Bu bir efsane.', 250: '250 iş. Şogun bile kıskanır.' };
            for (const m of Object.keys(marks).map(Number)) { if (wasCards < m && cards >= m) { this.showBubble(marks[m], null, 'milestone'); return; } }
        },
        toggleBubbles() { this.bubbles = !this.bubbles; store.set('bubbles', this.bubbles ? '1' : '0'); if (!this.bubbles) this.hideBubble(); },
        maybeBubble() {
            if (!this.bubbles || this.open || this.mood === 'sleep' || this.mood !== '' || document.visibilityState !== 'visible') return;
            if (Date.now() - this.last < 20000) return;
            if (Date.now() - +store.get('bubbleAt', '0') < 12 * 60000) return;
            store.set('bubbleAt', String(Date.now()));
            this.$wire.bubble(location.href).then((b) => { if (b && b.text && !this.open) this.showBubble(b.text, b.url, b.kind); }).catch(() => {});
        },
        showBubble(text, url, kind) {
            this.bubbleText = text; this.bubbleUrl = url || null; this.bubbleKind = kind || '';
            this.sfx('pop'); if (this.mood === '') this.play('talk', 1800);
            clearTimeout(this.bubbleTimer); this.bubbleTimer = setTimeout(() => this.hideBubble(), kind === 'milestone' ? 9000 : 6500);
        },
        hideBubble() { this.bubbleText = ''; this.bubbleUrl = null; clearTimeout(this.bubbleTimer); },
        bubbleClick() {
            const url = this.bubbleUrl; this.hideBubble();
            if (url) { window.Livewire?.navigate ? window.Livewire.navigate(url) : (location.href = url); return; }
            if (!this.open) this.tap();
        },
        gaze: 157,
        cone() { return this.$root.querySelector('.mx-launch'); },
        look(x, y) {
            const c = this.cone(); if (!c) return;
            const r = c.getBoundingClientRect(), cx = r.left + r.width / 2, cy = r.top + r.height / 2;
            const a = Math.atan2(x - cx, -(y - cy)) * 180 / Math.PI;
            const d = ((a - this.gaze) % 360 + 540) % 360 - 180;
            this.gaze += d; c.style.setProperty('--g', this.gaze.toFixed(1) + 'deg');
        },
        lookAt(el) { const r = el?.getBoundingClientRect(); if (r) this.look(r.left + r.width / 2, r.top + r.height / 2); },
        scroll() { const log = this.$refs.log; if (log) log.scrollTop = log.scrollHeight; },
        play(mood, ms, label) {
            this.mood = mood; if (label) this.status = label;
            clearTimeout(this.timer);
            if (ms) this.timer = setTimeout(() => { if (this.mood === mood) { this.mood = ''; this.status = 'hazır'; } }, ms);
        },
        idle() {
            if (this.mood !== '' && this.mood !== 'sleep') return;
            if (this.mood === 'sleep') { if (Math.random() < .3) this.sfx('snore'); return; }
            if (!this.open && Date.now() - this.last > 180000) { this.mood = 'sleep'; const c = this.cone(); if (c) { this.gaze = Math.round(this.gaze / 360) * 360 + 180; c.style.setProperty('--g', this.gaze + 'deg'); } return; }
            if (Math.random() < .55) { const v = ['v-shine', 'v-hop', 'v-shine'][Math.floor(Math.random() * 3)]; this.play(v, 1500); }
        },
        wake() { this.last = Date.now(); if (this.mood === 'sleep') { this.sfx('wake'); this.play('wake', 700, 'uyandı!'); return true; } return false; },
        tap() {
            this.hideBubble();
            if (this.wake()) return;
            this.toggle();
            if (this.open) { this.sfx('bow'); this.play('bow', 1400, 'selam!'); this.$nextTick(() => { this.scroll(); this.$refs.input?.focus(); }); } else { this.play('poke', 700); }
        },
        toggle() { this.open = !this.open; store.set('open', this.open ? '1' : '0'); },
        typing() {
            if (this.wake()) return;
            // Listening: the cone turns to the text box and nods with each key.
            this.lookAt(this.$refs.input);
            const c = this.$root.querySelector('.mx-launch .mx-c3'); if (c) { c.classList.remove('tick'); void c.offsetWidth; c.classList.add('tick'); }
            this.play('listen', 2200, 'dinliyor…');
        },
        submit() {
            if (!(this.$wire.text || '').trim()) return;
            this.last = Date.now();
            this.$wire.send(location.href);
        },
        think() { this.sfx('think'); this.play('think', 0, 'düşünüyor…'); },
        said(d) {
            const text = (d && d.text) || '';
            if (d && d.mood === 'confused') { this.sfx('confused'); this.play('confused', 2600, 'anlamadı'); }
            this.speak(text);
        },
        happy(d) { this.sfx('sword'); this.play('happy', 1600, 'yaptı!'); if (d && d.text) this.speak(d.text, true); },
        speak(text, keepMood) {
            if (!text) { if (!keepMood) this.play('', 0, 'hazır'); return; }
            if (!keepMood && this.mood !== 'confused') this.play('talk', 0, 'konuşuyor…');
            const mouth = this.$root.querySelector('.mx-mascot .m-mouth');
            const shapes = ['open', 'round', 'wide', '', ''];
            let alive = true;
            const flap = () => { if (!alive || !mouth) return; mouth.classList.remove('open', 'round', 'wide'); const c = shapes[Math.floor(Math.random() * shapes.length)]; if (c) mouth.classList.add(c); setTimeout(flap, 110); };
            const done = () => { alive = false; mouth?.classList.remove('open', 'round', 'wide'); if (this.mood === 'talk') this.play('', 0, 'hazır'); };
            flap();
            if (this.voice === 'speak' && this.trVoice) {
                try {
                    speechSynthesis.cancel();
                    const u = new SpeechSynthesisUtterance(text);
                    u.voice = this.trVoice; u.lang = this.trVoice.lang; u.rate = 1.07; u.pitch = 1.35;
                    u.onend = u.onerror = done; speechSynthesis.speak(u);
                    setTimeout(done, Math.min(25000, text.length * 95));
                    return;
                } catch (e) {}
            }
            if (this.voice !== 'mute') { let i = 0; const vow = { a: 330, e: 392, i: 440, 'ı': 370, o: 294, 'ö': 311, u: 262, 'ü': 277 }; for (const ch of text.slice(0, 160).toLocaleLowerCase('tr')) { if (vow[ch] && (i++ % 2 === 0)) this.tone(vow[ch] * (.96 + Math.random() * .08), vow[ch] * 1.06, .07, 'square', .025, i * .055); } }
            setTimeout(done, Math.min(6000, 600 + text.length * 30));
        },
        cycleVoice() {
            const order = ['speak', 'babble', 'mute'];
            this.voice = order[(order.indexOf(this.voice) + 1) % order.length]; store.set('voice', this.voice);
            try { speechSynthesis.cancel(); } catch (e) {}
            if (this.voice === 'speak') this.speak(this.trVoice ? 'Konuşma açık.' : '');
            if (this.voice === 'speak' && !this.trVoice) this.status = 'bu tarayıcıda Türkçe ses yok';
        },
        voiceLabel() { return { speak: 'Konuşur', babble: 'Mırıldanır', mute: 'Sessiz' }[this.voice] || 'Ses'; },
        tone(f1, f2, dur, type, vol, delay) {
            if (this.voice === 'mute') return; const a = ac(); if (!a || a.state !== 'running') return;
            const t = a.currentTime + (delay || 0), o = a.createOscillator(), g = a.createGain();
            o.type = type || 'triangle'; o.frequency.setValueAtTime(f1, t); if (f2) o.frequency.exponentialRampToValueAtTime(f2, t + dur);
            g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(vol || .08, t + .012); g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
            o.connect(g).connect(a.destination); o.start(t); o.stop(t + dur + .02);
        },
        noise(dur, freq, q, vol, sweepTo) {
            if (this.voice === 'mute') return; const a = ac(); if (!a || a.state !== 'running') return;
            const t = a.currentTime, len = Math.floor(a.sampleRate * dur), buf = a.createBuffer(1, len, a.sampleRate), d = buf.getChannelData(0);
            for (let i = 0; i < len; i++) d[i] = Math.random() * 2 - 1;
            const s = a.createBufferSource(); s.buffer = buf; const f = a.createBiquadFilter(); f.type = 'bandpass'; f.frequency.setValueAtTime(freq, t); if (sweepTo) f.frequency.exponentialRampToValueAtTime(sweepTo, t + dur); f.Q.value = q || 1;
            const g = a.createGain(); g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(vol || .1, t + .02); g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
            s.connect(f).connect(g).connect(a.destination); s.start(t);
        },
        sfx(name) {
            ({
                sword: () => { this.noise(.5, 2500, 6, .06, 7000); this.tone(1760, 2640, .9, 'sine', .04, .05); },
                bow: () => { this.tone(196, 180, .5, 'sine', .12); this.noise(.05, 900, 2, .06); },
                think: () => { this.tone(523, 523, .08, 'sine', .035); this.tone(587, 587, .08, 'sine', .035, .12); this.tone(659, 659, .08, 'sine', .035, .24); },
                confused: () => { this.tone(500, 330, .25, 'triangle', .06); this.tone(420, 560, .22, 'triangle', .05, .28); },
                wake: () => { this.tone(392, 784, .12, 'square', .035); this.tone(523, 1046, .14, 'square', .035, .1); },
                snore: () => this.noise(1.6, 220, 3, .04, 120),
                pop: () => { this.tone(880, 1320, .08, 'sine', .035); },
            })[name]?.();
        },
    };
};
</script>
