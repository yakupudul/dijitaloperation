// Moximu fotoğraflı (editoryal) Reels üreticisi. Kullanım: node reel-foto.cjs <reel.json> <cikti.mp4>
// 1080x1920, 30 fps, H.264 + sessiz ses izi. Sahneler:
//   foto    {img, pos, shade, top, bottom, dur}         tam ekran stok fotoğraf, yavaş yakınlaşma, iki köşede ince yazı
//   liste   {h, items:[...], dur}                       siyah zemin, sırayla gelen numaralı maddeler
//   telefon {img, h, dur}                               telefon çerçevesinde uzun site ekran görüntüsü kayar (vitrin)
//   son     {h, p, dur}                                 siyah zemin, büyüyen sarı çizgi, Moximu işareti
// Metinde \n satır sonu, *yıldız* sarı vurgu. Görseller assets/ altından. AI görseli yok.
const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');
const { chromium } = require('playwright');

const [, , inFile, outFile] = process.argv;
const reel = JSON.parse(fs.readFileSync(inFile, 'utf8'));
const A = path.join(__dirname, 'assets');
const mark = fs.readFileSync(path.join(A, 'mark-clean.svg'), 'utf8').replace(/width="1000" height="1000"/, '');
const img64 = (f) => `data:image/${/\.jpe?g$/i.test(f) ? 'jpeg' : 'png'};base64,` + fs.readFileSync(path.join(A, f)).toString('base64');
const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const fmt = (s) => esc(s).replace(/\*([^*]+)\*/g, '<span class="y">$1</span>').replace(/\n/g, '<br>');
const FPS = 30;

const css = `*{box-sizing:border-box;margin:0;padding:0}
body{width:1080px;height:1920px;overflow:hidden;background:#0d0d0e;color:#fff;font-family:"Inter Display",Inter,sans-serif;-webkit-font-smoothing:antialiased}
.s{position:absolute;inset:0;overflow:hidden;opacity:0;background:#0d0d0e}
.ph{position:absolute;inset:0;background-size:cover;background-repeat:no-repeat}
.sh{position:absolute;inset:0}
.t{position:absolute;font-weight:300;letter-spacing:-.045em;line-height:.98}
.y{color:#FDCF3C}
.tag{position:absolute;left:70px;top:150px;display:flex;align-items:center;gap:14px;font-size:30px;font-weight:500;z-index:5}
.tag .m{width:38px;height:38px;border-radius:9px;overflow:hidden}.tag .m svg{width:38px;height:38px;display:block}.tag span{font-weight:300;opacity:.8}
.li{display:flex;gap:30px;align-items:flex-start;margin-top:56px}
.li i{flex:none;font-style:normal;width:76px;height:76px;border-radius:50%;border:2px solid #FDCF3C;color:#FDCF3C;font-size:34px;display:flex;align-items:center;justify-content:center;font-weight:400}
.li b{font-size:62px;font-weight:300;letter-spacing:-.03em;line-height:1.1}
.li span{display:block;font-size:36px;font-weight:300;opacity:.72;margin-top:12px;line-height:1.3;letter-spacing:-.01em}
.phone{position:absolute;left:50%;top:430px;width:620px;height:1250px;margin-left:-310px;border-radius:70px;background:#1b1b1d;padding:18px;box-shadow:0 40px 120px rgba(0,0,0,.6)}
.phone .scr{width:100%;height:100%;border-radius:54px;overflow:hidden;position:relative;background:#fff}
.phone .scr img{position:absolute;left:0;top:0;width:100%}
`;

const tag = (label) => `<div class="tag"><div class="m">${mark}</div>Moximu <span>${esc(label)}</span></div>`;
const rise = (d) => `class="r" data-d="${d}"`;
function sceneHtml(s, i) {
  switch (s.t) {
    case 'foto':
      return `<div class="ph" style="background-image:url(${img64(s.img)});background-position:${s.pos || '50% 50%'}"></div><div class="sh" style="background:rgba(10,10,12,${s.shade ?? 0.32})"></div>
        ${i === 0 ? tag(reel.series) : ''}
        <div ${rise(0.3)}><div class="t" style="left:66px;top:${s.tt ?? 320}px;font-size:${s.ts ?? 140}px">${fmt(s.top)}</div></div>
        <div ${rise(1.0)}><div class="t" style="${s.right ? 'right:70px;text-align:right' : 'left:66px'};top:${s.bt ?? 1100}px;font-size:${s.bs ?? 140}px">${fmt(s.bottom)}</div></div>`;
    case 'liste':
      return `<div style="position:absolute;left:70px;right:70px;top:300px">
        <div ${rise(0.2)}><div style="font-size:96px;font-weight:300;letter-spacing:-.045em;line-height:1">${fmt(s.h)}</div></div>
        ${s.items.map(([b, p], k) => `<div ${rise(0.8 + k * 0.9)}><div class="li"><i>${k + 1}</i><div><b>${esc(b)}</b>${p ? `<span>${esc(p)}</span>` : ''}</div></div></div>`).join('')}</div>`;
    case 'telefon':
      return `<div ${rise(0.1)}><div class="t" style="left:0;right:0;top:220px;text-align:center;font-size:76px">${fmt(s.h)}</div></div>
        <div class="phone"><div class="scr"><img class="scroll" src="${img64(s.img)}"></div></div>
        ${s.url ? `<div style="position:absolute;left:0;right:0;top:1730px;text-align:center;font-size:34px;font-weight:300;opacity:.8">${esc(s.url)}</div>` : ''}`;
    case 'son':
      return `<div class="line" style="position:absolute;left:539px;top:330px;width:2px;height:0;background:#FDCF3C"></div>
        <div ${rise(0.7)}><div style="position:absolute;left:0;right:0;top:800px;text-align:center"><div style="display:inline-flex;align-items:center;gap:22px;font-size:72px;font-weight:500;letter-spacing:-.02em"><div style="width:84px;height:84px;border-radius:19px;overflow:hidden">${mark.replace('<svg', '<svg width="84" height="84"')}</div>Moximu</div></div></div>
        <div ${rise(1.1)}><div class="t" style="left:60px;right:60px;top:960px;text-align:center;font-size:62px;line-height:1.2">${fmt(s.h)}</div></div>
        <div ${rise(1.5)}><div style="position:absolute;left:0;right:0;top:${s.pTop ?? 1210}px;text-align:center;font-size:40px;font-weight:300;opacity:.8">${fmt(s.p || 'moximu.com')}</div></div>`;
  }
  throw new Error('Bilinmeyen sahne: ' + s.t);
}

(async () => {
  const scenes = reel.scenes;
  let acc = 0;
  const starts = scenes.map((s) => { const a = acc; acc += s.dur; return a; });
  const total = acc;
  const html = `<!doctype html><html lang="tr"><head><meta charset="utf-8"><style>${css}</style></head><body>
    ${scenes.map((s, i) => `<div class="s" id="s${i}" data-t="${s.t}" data-a="${starts[i]}" data-dur="${s.dur}">${sceneHtml(s, i)}</div>`).join('')}
    <script>
      const cl=x=>Math.max(0,Math.min(1,x)), ease=x=>1-Math.pow(1-cl(x),3), io=x=>{x=cl(x);return x<.5?4*x*x*x:1-Math.pow(-2*x+2,3)/2};
      const F=.35, N=${scenes.length};
      window.render=(t)=>{document.querySelectorAll('.s').forEach((el,i)=>{
        const a=+el.dataset.a, d=+el.dataset.dur, lt=t-a;
        let o=i===0?1:cl(lt/F); if(i<N-1&&t>a+d-F/2) o=Math.min(o,1-cl((t-(a+d-F/2))/F));
        if(lt<-F||lt>d+F){el.style.opacity=0;return;}
        el.style.opacity=o; el.style.zIndex=i;
        const ph=el.querySelector('.ph'); if(ph) ph.style.transform='scale('+(1+.08*cl(lt/d))+')';
        el.querySelectorAll('.r').forEach(r=>{const k=ease((lt-parseFloat(r.dataset.d))/.6);r.style.opacity=k;r.style.transform='translateY('+(1-k)*40+'px)';});
        const ln=el.querySelector('.line'); if(ln) ln.style.height=(ease((lt-.2)/.9)*380)+'px';
        const sc=el.querySelector('.scroll'); if(sc){const max=Math.min(sc.offsetHeight-sc.parentNode.offsetHeight,3600);sc.style.transform='translateY('+(-max*io((lt-.8)/(d-1.6)))+'px)';}
      });};
    </script></body></html>`;
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1080, height: 1920 }, deviceScaleFactor: 1 });
  await page.setContent(html, { waitUntil: 'load' });
  await page.evaluate(() => document.fonts.ready);
  const frames = Math.round(total * FPS);
  const ff = spawn('ffmpeg', ['-y', '-loglevel', 'error', '-f', 'image2pipe', '-framerate', String(FPS), '-c:v', 'mjpeg', '-i', '-',
    '-f', 'lavfi', '-i', 'anullsrc=channel_layout=stereo:sample_rate=44100',
    '-shortest', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-profile:v', 'high', '-crf', '20', '-preset', 'medium',
    '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart', outFile], { stdio: ['pipe', 'inherit', 'inherit'] });
  for (let f = 0; f < frames; f++) {
    await page.evaluate((t) => window.render(t), f / FPS);
    const buf = await page.screenshot({ type: 'jpeg', quality: 90 });
    if (!ff.stdin.write(buf)) await new Promise((r) => ff.stdin.once('drain', r));
  }
  ff.stdin.end();
  await new Promise((r, j) => ff.on('close', (c) => (c === 0 ? r() : j(new Error('ffmpeg ' + c)))));
  await page.evaluate((t) => window.render(t), Math.min(scenes[0].dur - 0.4, 2.2));
  await page.screenshot({ path: outFile.replace(/\.mp4$/, '-kapak.jpg'), type: 'jpeg', quality: 92 });
  await browser.close();
  console.log(outFile, total + ' sn', frames + ' kare');
})().catch((e) => { console.error(e); process.exit(1); });
