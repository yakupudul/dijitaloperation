// Moximu sosyal medya görsel üreticisi. Kullanım: node gen.cjs <icerik.json> <cikti-klasoru>
// Şablonlar: cover, item, end (kaydırmalı), split, date, case, three (tek görsel), story, story-score (1080x1920).
// AI görseli yok: yalnız tipografi, Moximu işareti (sarı #FDCF3C + teal) ve müşteri logoları.
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const [, , inFile, outDir] = process.argv;
const data = JSON.parse(fs.readFileSync(inFile, 'utf8'));
const A = path.join(__dirname, 'assets');
const mark = fs.readFileSync(path.join(A, 'mark-clean.svg'), 'utf8').replace(/width="1000" height="1000"/, '');
const img64 = (f) => 'data:image/png;base64,' + fs.readFileSync(path.join(A, f)).toString('base64');
const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

const css = `
*{box-sizing:border-box;margin:0;padding:0}
body{width:1080px;height:1350px;background:#F5F4F0;font-family:Inter,sans-serif;color:#111827;overflow:hidden;-webkit-font-smoothing:antialiased}
body.story{height:1920px}
.wrap{position:absolute;inset:0;padding:72px 72px 64px;display:flex;flex-direction:column}
.top{display:flex;align-items:center;justify-content:space-between}
.brand{display:flex;align-items:center;gap:14px;font-size:30px;font-weight:600;letter-spacing:-.01em}
.brand .m{width:46px;height:46px;border-radius:11px;overflow:hidden}
.brand .m svg{width:46px;height:46px;display:block}
.brand span{color:#8A8F98;font-weight:400}
.tag{font-size:22px;color:#0F5A52;background:#E3EFEC;border-radius:999px;padding:10px 22px;font-weight:500}
h1{font-size:92px;line-height:1.02;font-weight:500;letter-spacing:-.035em;margin-top:64px}
h1 .g{color:#8A8F98;display:block}
.lead{font-size:30px;line-height:1.4;color:#4B5160;margin-top:28px;max-width:860px}
.panel{margin-top:auto;border-radius:44px;padding:52px;position:relative;overflow:hidden;background:#FBF6E6}
.panel:before{content:"";position:absolute;inset:0;background:radial-gradient(circle at 92% 8%,rgba(253,207,60,.95),rgba(253,207,60,0) 46%),radial-gradient(circle at 6% 100%,rgba(15,90,82,.28),rgba(15,90,82,0) 48%)}
.panel>*{position:relative}
.card{background:#fff;border-radius:28px;padding:36px 40px;box-shadow:0 18px 50px rgba(17,24,39,.08)}
.li{display:flex;gap:18px;align-items:flex-start;font-size:31px;line-height:1.3;padding:13px 0}
.dot{flex:none;width:34px;height:34px;border-radius:50%;background:#0F5A52;color:#fff;font-size:18px;display:flex;align-items:center;justify-content:center;margin-top:2px}
.dot.no{background:#E5E7EB;color:#6B7280}
.foot{display:flex;align-items:center;justify-content:space-between;margin-top:40px;font-size:26px;color:#4B5160}
.foot b{color:#111827;font-weight:500}
.pill{background:#0F5A52;color:#fff;border-radius:999px;padding:20px 36px;font-size:27px;font-weight:500}
.num{font-size:150px;font-weight:300;letter-spacing:-.05em;color:#0F5A52;line-height:1;margin-top:90px}
.ih{font-size:68px;line-height:1.08;font-weight:500;letter-spacing:-.03em;margin-top:36px}
.ip{font-size:36px;line-height:1.45;color:#4B5160;margin-top:34px;max-width:900px}
.bar{position:absolute;left:72px;right:72px;bottom:64px;display:flex;justify-content:space-between;font-size:24px;color:#8A8F98}
.note{font-size:22px;color:#8A8F98;margin-top:22px;line-height:1.4}
.cols{display:grid;grid-template-columns:1fr 1fr;gap:24px}
.cols h3{font-size:24px;font-weight:600;margin-bottom:8px;color:#0F5A52}
.cols .card.n h3{color:#9CA3AF}
.cols .li{font-size:27px}
.big{font-size:210px;font-weight:300;letter-spacing:-.06em;line-height:.95;color:#111827}
.logo{height:92px;max-width:520px;object-fit:contain}
.row{display:flex;gap:24px;margin-top:34px}
.kpi{flex:1;background:#fff;border-radius:24px;padding:28px 32px}
.kpi b{display:block;font-size:64px;font-weight:400;letter-spacing:-.03em;color:#0F5A52}
.kpi span{font-size:25px;color:#4B5160}
.src{font-size:22px;color:#6B7280;margin-top:26px}
.three .t{display:flex;gap:26px;padding:26px 0;border-top:1px solid #ECE9E1}
.three .t:first-child{border-top:0}
.three .t i{flex:none;font-style:normal;width:64px;height:64px;border-radius:18px;background:#FDCF3C;font-size:28px;font-weight:600;display:flex;align-items:center;justify-content:center}
.three .t b{display:block;font-size:34px;font-weight:600;letter-spacing:-.01em}
.three .t span{display:block;font-size:27px;line-height:1.4;color:#4B5160;margin-top:6px}
.story .wrap{padding:110px 80px 120px}
.story h1{font-size:104px;margin-top:150px}
.story .ip{font-size:42px}
.story .cta{margin-top:auto;align-self:flex-start;background:#0F5A52;color:#fff;border-radius:999px;padding:28px 46px;font-size:36px;font-weight:500}
.story .disc{position:absolute;right:-170px;top:1050px;width:560px;height:560px;border-radius:50%;background:#FDCF3C;opacity:.9}
.deco{position:absolute;right:-140px;bottom:-140px;width:520px;height:520px;border-radius:50%;background:#FDCF3C;opacity:.85}
.deco2{position:absolute;right:300px;bottom:-200px;width:360px;height:360px;border-radius:50%;background:#0F5A52;opacity:.12}
.wrap{z-index:1}
.story .disc2{position:absolute;left:-200px;top:1180px;width:460px;height:460px;border-radius:50%;background:#0F5A52;opacity:.12}
`;

const brand = (label = 'Rehber') => `<div class="brand"><div class="m">${mark}</div>Moximu <span>${label}</span></div>`;
const top = (tag, label) => `<div class="top">${brand(label)}${tag ? `<div class="tag">${esc(tag)}</div>` : ''}</div>`;
const lis = (arr, cls = '') => arr.map((x, i) => `<div class="li"><div class="dot ${cls}">${cls === 'no' ? '×' : '✓'}</div><div>${esc(x)}</div></div>`).join('');

function slideHtml(s, idx, total) {
  const pager = total > 1 ? `<div class="bar"><span>Moximu Rehber</span><span>${idx + 1} / ${total}</span></div>` : '';
  switch (s.t) {
    case 'cover':
      return `<div class="wrap">${top(s.tag)}<h1>${esc(s.h1)}<span class="g">${esc(s.h2)}</span></h1><p class="lead">${esc(s.lead)}</p>
        <div class="panel"><div class="card">${s.list.map((x, i) => `<div class="li"><div class="dot">${i + 1}</div><div>${esc(x)}</div></div>`).join('')}</div></div>
        <div class="foot"><span><b>moximu.com</b>/rehber</span><span class="pill">Kaydırın ›</span></div></div>`;
    case 'item':
      return `<div class="deco"></div><div class="deco2"></div><div class="wrap">${top(null)}<div class="num">${esc(s.n)}</div><div class="ih">${esc(s.h)}</div><p class="ip">${esc(s.p)}</p></div>${pager}`;
    case 'end':
      return `<div class="wrap">${top(null)}<h1 style="font-size:80px">${esc(s.h)}</h1>
        <div class="panel"><div class="card">${lis(s.list)}</div>${s.note ? `<p class="note">${esc(s.note)}</p>` : ''}</div>
        <div class="foot"><span><b>${esc(s.url.split('/')[0])}</b>/${esc(s.url.split('/').slice(1).join('/'))}</span><span class="pill">Rehberi okuyun ›</span></div></div>`;
    case 'split':
      return `<div class="wrap">${top(s.tag)}<h1 style="font-size:84px">${esc(s.h1)}<span class="g">${esc(s.h2)}</span></h1>
        <div class="panel"><div class="cols"><div class="card"><h3>Serbest</h3>${lis(s.yes)}</div><div class="card n"><h3>Kurallara aykırı</h3>${lis(s.no, 'no')}</div></div></div>
        <div class="foot"><span><b>moximu.com</b>/rehber</span><span class="pill">Rehberi okuyun ›</span></div></div>`;
    case 'date':
      return `<div class="wrap">${top(s.tag, 'Gündem')}<div class="num" style="margin-top:70px;font-size:120px;color:#111827">${esc(s.big)}</div>
        <h1 style="margin-top:20px;font-size:80px">${esc(s.h1)}<span class="g">${esc(s.h2)}</span></h1>
        <div class="panel"><div class="card">${lis(s.list)}</div></div>
        <div class="foot"><span><b>moximu.com</b>/saglik-reklam-mevzuati</span><span class="pill">Özeti okuyun ›</span></div></div>`;
    case 'case':
      return `<div class="wrap">${top(s.tag, 'Vaka')}
        <div class="card" style="margin-top:64px;display:flex;align-items:center;justify-content:space-between;padding:44px 48px"><img class="logo" src="${img64(s.logo)}"><span style="font-size:26px;color:#4B5160">${esc(s.who)}</span></div>
        <div class="panel" style="margin-top:36px"><div class="big">${esc(s.big)}</div><div style="font-size:40px;margin-top:10px">${esc(s.label)}</div>
        <div class="row">${s.rows.map(([b, l]) => `<div class="kpi"><b>${esc(b)}</b><span>${esc(l)}</span></div>`).join('')}</div>
        <p class="src">${esc(s.src)} · yüzdeler Google'ın kendi verisinden</p></div>
        <div class="foot"><span><b>moximu.com</b>/referanslar</span><span class="pill">Vakayı okuyun ›</span></div></div>`;
    case 'three':
      return `<div class="wrap">${top(s.tag)}<h1 style="font-size:88px">${esc(s.h1)}<span class="g">${esc(s.h2)}</span></h1>
        <div class="panel three"><div class="card">${s.items.map(([b, t], i) => `<div class="t"><i>${i + 1}</i><div><b>${esc(b)}</b><span>${esc(t)}</span></div></div>`).join('')}</div></div>
        <div class="foot"><span><b>moximu.com</b>/rehber</span><span class="pill">Rehberi okuyun ›</span></div></div>`;
    case 'story':
      return `<div class="disc"></div><div class="disc2"></div><div class="wrap">${top(s.tag)}<h1>${esc(s.h1)}<span class="g">${esc(s.h2)}</span></h1><p class="ip">${esc(s.p)}</p><div class="cta">${esc(s.cta)} ›</div></div>`;
    case 'story-score':
      return `<div class="disc"></div><div class="disc2"></div><div class="wrap">${top(s.tag, s.tag === 'Vaka' ? 'Vaka' : '')}<div class="big" style="margin-top:200px;font-size:${String(s.big).length > 4 ? 230 : 300}px">${esc(s.big)}</div><div style="font-size:46px;margin-top:20px">${esc(s.label)}</div><p class="ip">${esc(s.p)}</p><div class="cta">${esc(s.cta)} ›</div></div>`;
  }
  throw new Error('Bilinmeyen şablon: ' + s.t);
}

(async () => {
  fs.mkdirSync(outDir, { recursive: true });
  const browser = await chromium.launch();
  const page = await browser.newPage({ deviceScaleFactor: 1 });
  const manifest = [];
  for (const post of data.posts) {
    const files = [];
    const story = post.kind === 'story';
    for (let i = 0; i < post.slides.length; i++) {
      await page.setViewportSize({ width: 1080, height: story ? 1920 : 1350 });
      await page.setContent(`<!doctype html><html lang="tr"><head><meta charset="utf-8"><link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"><style>${css}</style></head><body class="${story ? 'story' : ''}">${slideHtml(post.slides[i], i, post.slides.length)}</body></html>`, { waitUntil: 'networkidle' });
      await page.evaluate(() => document.fonts.ready);
      const file = path.join(outDir, `${post.slug}-${i + 1}.png`);
      await page.screenshot({ path: file });
      files.push(file);
    }
    manifest.push({ ...post, files });
    console.log(post.slug, files.length);
  }
  fs.writeFileSync(path.join(outDir, 'manifest.json'), JSON.stringify(manifest, null, 2));
  await browser.close();
})();
