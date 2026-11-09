{{-- Moxi in the browser (yakup 2026-11-09): the mascot's moods, idle moves, sounds made on the fly (Web Audio, no files)
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
        boot() {
            document.addEventListener('pointerdown', ac, { once: true });
            try { const pick = () => { const vs = speechSynthesis.getVoices().filter(v => /^tr/i.test(v.lang)); this.trVoice = vs.find(v => /google|natural|online|neural/i.test(v.name)) || vs[0] || null; }; pick(); speechSynthesis.onvoiceschanged = pick; } catch (e) {}
            let frame = 0;
            document.addEventListener('pointermove', (e) => {
                if (frame) return;
                frame = requestAnimationFrame(() => {
                    frame = 0;
                    const r = this.$root.querySelector('.mx-mascot')?.getBoundingClientRect(); if (!r) return;
                    const m = this.$root.querySelector('.mx-mascot .moxi'); if (!m) return;
                    m.style.setProperty('--ex', Math.max(-4, Math.min(4, (e.clientX - (r.left + r.width / 2)) / 60)).toFixed(1) + 'px');
                    m.style.setProperty('--ey', Math.max(-3, Math.min(3, (e.clientY - (r.top + r.height * .45)) / 70)).toFixed(1) + 'px');
                });
            });
            setInterval(() => this.idle(), 9000);
            this.play('enter', 1200); setTimeout(() => this.play('bow', 1500), 1250);
            window.Livewire?.hook?.('commit', ({ succeed }) => succeed(() => queueMicrotask(() => this.scroll())));
            this.$nextTick(() => this.scroll());
        },
        scroll() { const log = this.$refs.log; if (log) log.scrollTop = log.scrollHeight; },
        play(mood, ms, label) {
            this.mood = mood; if (label) this.status = label;
            clearTimeout(this.timer);
            if (ms) this.timer = setTimeout(() => { if (this.mood === mood) { this.mood = ''; this.status = 'hazır'; } }, ms);
        },
        idle() {
            if (this.mood !== '' && this.mood !== 'sleep') return;
            if (this.mood === 'sleep') { if (Math.random() < .3) this.sfx('snore'); return; }
            if (!this.open && Date.now() - this.last > 180000) { this.mood = 'sleep'; return; }
            if (Math.random() < .5) { const v = ['v-look', 'v-hat', 'v-yawn', 'v-sword'][Math.floor(Math.random() * 4)]; this.play(v, v === 'v-sword' ? 1000 : 2600); }
        },
        wake() { this.last = Date.now(); if (this.mood === 'sleep') { this.sfx('wake'); this.play('wake', 700, 'uyandı!'); return true; } return false; },
        tap() {
            if (this.wake()) return;
            this.toggle();
            if (this.open) { this.sfx('bow'); this.play('bow', 1400, 'selam!'); this.$nextTick(() => { this.scroll(); this.$refs.input?.focus(); }); } else { this.play('poke', 700); }
        },
        toggle() { this.open = !this.open; store.set('open', this.open ? '1' : '0'); },
        typing() {
            if (this.wake()) return;
            const n = (this.$refs.input?.value || '').length;
            const m = this.$root.querySelector('.mx-mascot .moxi');
            m?.style.setProperty('--lx', (Math.min(n, 40) / 40 * 10 - 5) + 'px');
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
        voiceLabel() { return { speak: '🗣 Konuşuyor', babble: '🎵 Mırıltı', mute: '🔇 Sessiz' }[this.voice] || 'Ses'; },
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
            })[name]?.();
        },
    };
};
</script>
