// Moximu hareketli Reels üreticisi (görsellerden, AI videosu yok). Kullanım: node reel.cjs <reel.json> <cikti.mp4>
// 1080x1920, 30 fps, H.264 + sessiz ses izi (Instagram Reels için). Sahneler: hook, item, kpi, end.
// Her kare zamana göre hesaplanır (deterministik), Playwright ekran görüntüsü → ffmpeg.
const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');
const { chromium } = require('playwright');

const [, , inFile, outFile] = process.argv;
const reel = JSON.parse(fs.readFileSync(inFile, 'utf8'));
const A = path.join(__dirname, 'assets');
const mark = fs.readFileSync(path.join(A, 'mark-clean.svg'), 'utf8').replace(/width="1000" height="1000"/, '');
const img64 = (f) => 'data:image/png;base64,' + fs.readFileSync(path.join(A, f)).toString('base64');
const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const FPS = 30;

const css = `
*{box-sizing:border-box;margin:0;padding:0}
body{width:1080px;height:1920px;background:#F5F4F0;font-family:Inter,sans-serif;color:#111827;overflow:hidden;-webkit-font-smoothing:antialiased}
.scene{position:absolute;inset:0;padding:200px 84px 520px;display:flex;flex-direction:column;justify-content:center;opacity:0}
.brand{position:absolute;left:84px;top:84px;display:flex;align-items:center;gap:16px;font-size:34px;font-weight:600;z-index:5}
.brand .m{width:54px;height:54px;border-radius:13px;overflow:hidden}.brand .m svg{width:54px;height:54px;display:block}
.prog{position:absolute;left:84px;right:84px;bottom:90px;height:6px;border-radius:3px;background:#E4E1D8;z-index:5;overflow:hidden}
.prog i{display:block;height:100%;background:#0F5A52;width:0}
.disc{position:absolute;border-radius:50%}
.tag{align-self:flex-start;font-size:30px;color:#0F5A52;background:#E3EFEC;border-radius:999px;padding:12px 28px;font-weight:500}
.h{font-size:112px;line-height:1.03;font-weight:500;letter-spacing:-.035em;margin-top:56px}
.h .g{color:#8A8F98;display:block}
.p{font-size:44px;line-height:1.42;color:#4B5160;margin-top:44px}
.n{font-size:220px;font-weight:300;letter-spacing:-.05em;color:#0F5A52;line-height:1}
.ih{font-size:86px;line-height:1.08;font-weight:500;letter-spacing:-.03em;margin-top:40px}
.card{background:#fff;border-radius:36px;padding:44px 48px;box-shadow:0 18px 50px rgba(17,24,39,.08)}
.logo{height:110px;max-width:600px;object-fit:contain}
.big{font-size:260px;font-weight:300;letter-spacing:-.06em;line-height:.95}
.lbl{font-size:52px;margin-top:16px}
.kpis{display:flex;gap:28px;margin-top:56px}
.kpi{flex:1;background:#fff;border-radius:30px;padding:36px 38px}
.kpi b{display:block;font-size:84px;font-weight:400;letter-spacing:-.03em;color:#0F5A52}
.kpi span{font-size:32px;color:#4B5160}
.src{font-size:28px;color:#6B7280;margin-top:40px;line-height:1.4}
.cta{align-self:flex-start;background:#0F5A52;color:#fff;border-radius:999px;padding:32px 52px;font-size:44px;font-weight:500;margin-top:70px}
.url{font-size:40px;color:#4B5160;margin-top:34px}.url b{color:#111827;font-weight:600}
.a{will-change:transform,opacity}
`;

// Sahne HTML'i; .a öğeleri sırayla girer (data-d: giriş gecikmesi sn).
function sceneHtml(s) {
  switch (s.t) {
    case 'hook':
      return `<div class="disc" style="right:-220px;top:1150px;width:700px;height:700px;background:#FDCF3C"></div>
        <div class="disc" style="left:-240px;top:1380px;width:520px;height:520px;background:#0F5A52;opacity:.12"></div>
        ${s.tag ? `<div class="tag a" data-d="0">${esc(s.tag)}</div>` : ''}
        <div class="h"><span class="a" data-d=".25" style="display:block">${esc(s.h1)}</span><span class="g a" data-d=".6">${esc(s.h2)}</span></div>
        ${s.p ? `<p class="p a" data-d="1.1">${esc(s.p)}</p>` : ''}`;
    case 'item':
      return `<div class="disc" style="right:-180px;bottom:-160px;width:620px;height:620px;background:#FDCF3C;opacity:.9"></div>
        <div class="disc" style="right:360px;bottom:-240px;width:420px;height:420px;background:#0F5A52;opacity:.12"></div>
        <div class="n a" data-d="0">${esc(s.n)}</div><div class="ih a" data-d=".3">${esc(s.h)}</div>
        <p class="p a" data-d=".75">${esc(s.p)}</p>`;
    case 'kpi':
      return `<div class="disc" style="right:-200px;top:-160px;width:620px;height:620px;background:#FDCF3C;opacity:.9"></div>
        <div class="tag a" data-d="0">${esc(s.tag || 'Vaka')}</div>
        <div class="card a" data-d=".2" style="margin-top:48px;display:flex;align-items:center;justify-content:space-between"><img class="logo" src="${img64(s.logo)}"><span style="font-size:32px;color:#4B5160">${esc(s.who)}</span></div>
        <div class="big a" data-d=".6" style="margin-top:70px"><span data-count="${esc(s.big)}">${esc(s.big)}</span></div>
        <div class="lbl a" data-d=".8">${esc(s.label)}</div>
        <div class="kpis">${s.rows.map(([b, l], i) => `<div class="kpi a" data-d="${1.3 + i * .25}"><b data-count="${esc(b)}">${esc(b)}</b><span>${esc(l)}</span></div>`).join('')}</div>
        <p class="src a" data-d="1.9">${esc(s.src)}</p>`;
    case 'end':
      return `<div class="disc" style="right:-220px;top:1100px;width:700px;height:700px;background:#FDCF3C"></div>
        <div class="disc" style="left:-240px;top:1380px;width:520px;height:520px;background:#0F5A52;opacity:.12"></div>
        <div class="h a" data-d="0" style="font-size:96px;margin-top:0">${esc(s.h)}</div>
        ${s.p ? `<p class="p a" data-d=".35">${esc(s.p)}</p>` : ''}
        <div class="cta a" data-d=".7">${esc(s.cta)} ›</div>
        <p class="url a" data-d=".9"><b>moximu.com</b>${esc(s.path || '')}</p>`;
  }
  throw new Error('Bilinmeyen sahne: ' + s.t);
}

(async () => {
  const scenes = reel.scenes;
  const starts = [];
  let total = 0;
  for (const s of scenes) { starts.push(total); total += s.dur; }
  const html = `<!doctype html><html><head><meta charset="utf-8"><link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"><style>${css}</style></head><body>
    <div class="brand"><div class="m">${mark}</div>Moximu</div>
    ${scenes.map((s, i) => `<section class="scene" id="s${i}">${sceneHtml(s)}</section>`).join('')}
    <div class="prog"><i id="pg"></i></div>
    <script>
      const S=${JSON.stringify(scenes.map((s, i) => ({ start: starts[i], dur: s.dur })))}, T=${total};
      const ease=x=>1-Math.pow(1-Math.min(Math.max(x,0),1),3);
      const fmt=(tpl,v)=>tpl.replace(/[0-9][0-9.,]*/, m=>{const n=Math.round(v*parseFloat(m.replace(/\\./g,'').replace(',','.')));return n.toLocaleString('tr-TR');});
      document.querySelectorAll('[data-count]').forEach(e=>e.dataset.tpl=e.dataset.count);
      window.render=(t)=>{
        S.forEach((s,i)=>{
          const el=document.getElementById('s'+i), lt=t-s.start;
          const fadeIn=ease(lt/.35), fadeOut=i===S.length-1?1:1-ease((lt-(s.dur-.3))/.3);
          const on=lt>=0&&lt<s.dur+.0001;
          el.style.opacity=on?Math.min(fadeIn,fadeOut):0;
          el.style.transform='translateY('+((1-fadeIn)*40 - (1-fadeOut)*40)+'px)';
          if(!on)return;
          el.querySelectorAll('.a').forEach(a=>{const k=ease((lt-parseFloat(a.dataset.d||0))/.55);a.style.opacity=k;a.style.transform='translateY('+(1-k)*60+'px)';});
          el.querySelectorAll('.disc').forEach((d,j)=>{d.style.transform='translate('+Math.sin(lt*.6+j)*18+'px,'+Math.cos(lt*.5+j)*18+'px) scale('+(0.92+0.08*ease(lt/1.2))+')';});
          el.querySelectorAll('[data-count]').forEach(c=>{const host=c.closest('.a');const d=parseFloat(host?host.dataset.d:0);c.textContent=fmt(c.dataset.tpl,ease((lt-d)/1.4));});
        });
        document.getElementById('pg').style.width=(t/T*100)+'%';
      };
    </script></body></html>`;
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1080, height: 1920 }, deviceScaleFactor: 1 });
  await page.setContent(html, { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
  const frames = Math.round(total * FPS);
  const ff = spawn('ffmpeg', ['-y', '-loglevel', 'error', '-f', 'image2pipe', '-framerate', String(FPS), '-c:v', 'mjpeg', '-i', '-',
    '-f', 'lavfi', '-i', 'anullsrc=channel_layout=stereo:sample_rate=44100',
    '-shortest', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-profile:v', 'high', '-crf', '18', '-preset', 'medium',
    '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart', outFile], { stdio: ['pipe', 'inherit', 'inherit'] });
  for (let f = 0; f < frames; f++) {
    await page.evaluate((t) => window.render(t), f / FPS);
    const buf = await page.screenshot({ type: 'jpeg', quality: 92 });
    if (!ff.stdin.write(buf)) await new Promise((r) => ff.stdin.once('drain', r));
  }
  ff.stdin.end();
  await new Promise((r, j) => ff.on('close', (c) => (c === 0 ? r() : j(new Error('ffmpeg ' + c)))));
  // Kapak: ilk sahnenin tam hâli.
  await page.evaluate((t) => window.render(t), Math.min(scenes[0].dur - 0.5, 2.2));
  await page.screenshot({ path: outFile.replace(/\.mp4$/, '-kapak.jpg'), type: 'jpeg', quality: 92 });
  await browser.close();
  console.log(outFile, total + ' sn', frames + ' kare');
})().catch((e) => { console.error(e); process.exit(1); });
