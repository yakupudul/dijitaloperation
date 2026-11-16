/* Dr. Serap Korkmaz randevu sayfaları — küçük davranışlar. Komut dosyası çalışmasa da sayfa eksiksiz görünür. */
(function () {
  var d = document, root = d.documentElement;
  root.classList.add('js');
  var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var hasIO = 'IntersectionObserver' in window;

  /* 1. Yerine oturma: yalnız ekranın altında kalan bloklar 22px aşağıdan gelir, görünürlük hiç değişmez. */
  if (!calm && hasIO) {
    var vh = window.innerHeight || root.clientHeight;
    var low = [].slice.call(d.querySelectorAll('.rv')).filter(function (el) {
      return el.getBoundingClientRect().top > vh * 0.94;
    });
    low.forEach(function (el) { el.classList.add('is-low'); });
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) { e.target.classList.remove('is-low'); io.unobserve(e.target); }
      });
    }, { rootMargin: '0px 0px -5% 0px', threshold: 0.04 });
    low.forEach(function (el) { io.observe(el); });
  }

  /* 2. İlk mesaj: seçilen soruya göre cümle ve WhatsApp bağlantısı değişir. */
  var ask = d.querySelector('[data-ask]');
  if (ask) {
    var out = ask.querySelector('[data-ask-msg]');
    var link = ask.querySelector('[data-ask-link]');
    var base = link.getAttribute('data-wa');
    /* Karekod yalnız fareyle kullanılan geniş ekranda gösterilir; telefon bu görselleri hiç indirmez. */
    var qr = ask.querySelector('[data-ask-qr]');
    var wide = window.matchMedia ? window.matchMedia('(min-width: 861px) and (hover: hover) and (pointer: fine)') : null;
    var qrImg = null;
    var setQr = function () {
      if (!qr || !wide) return;
      var c = ask.querySelector('input[type=radio]:checked');
      var src = c && c.getAttribute('data-qr');
      if (!wide.matches || !src) { qr.hidden = true; return; }
      if (!qrImg) {
        qrImg = new Image(160, 160);
        qrImg.alt = qr.getAttribute('data-alt') || '';
        qr.querySelector('[data-ask-qr-code]').appendChild(qrImg);
      }
      if (qrImg.getAttribute('src') !== src) qrImg.src = src;
      qr.hidden = false;
    };
    var set = function (animate) {
      var c = ask.querySelector('input[type=radio]:checked');
      if (!c) return;
      out.textContent = c.value;
      link.href = base + encodeURIComponent(c.value);
      setQr();
      if (animate && !calm) {
        out.classList.remove('is-in');
        void out.offsetWidth;
        out.classList.add('is-in');
      }
    };
    ask.addEventListener('change', function () { set(true); });
    if (wide) { if (wide.addEventListener) wide.addEventListener('change', setQr); else if (wide.addListener) wide.addListener(setQr); }
    set(false);
  }

  /* 3. Telefonda alt çubuk: ilk ekrandaki ya da en alttaki düğme görünürken gizlenir. */
  var bar = d.querySelector('[data-bar]');
  var cta = d.querySelector('[data-cta]');
  if (bar && cta && hasIO) {
    var seen = { top: true, end: false };
    var endBtn = ask ? ask.querySelector('[data-ask-link]') : null;
    var upd = function () { bar.classList.toggle('is-off', seen.top || seen.end); };
    var io2 = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.target === cta) seen.top = e.isIntersecting; else seen.end = e.isIntersecting;
      });
      upd();
    }, { threshold: 0 });
    io2.observe(cta);
    if (endBtn) io2.observe(endBtn);
    upd();
  }

  /* 4. Hekimin videosu: dokununca sayfanın içinde oynar, bitince ilk kareye döner.
        Video dosyası oynat denene kadar hiç indirilmez (preload="none"). */
  var clips = [].slice.call(d.querySelectorAll('[data-clip]'));
  clips.forEach(function (box) {
    var v = box.querySelector('video');
    var b = box.querySelector('[data-clip-play]');
    if (!v || !b) return;
    var rest = function () { v.controls = false; b.hidden = false; };
    rest();
    b.addEventListener('click', function () {
      clips.forEach(function (o) {
        var ov = o.querySelector('video');
        if (ov && ov !== v && !ov.paused) ov.pause();
      });
      b.hidden = true;
      v.controls = true;
      var p = v.play();
      if (p && p.catch) p.catch(function () {});
      try { v.focus({ preventScroll: true }); } catch (err) {}
    });
    v.addEventListener('ended', function () { v.load(); rest(); });
    /* Dosya açılamazsa ilk kare fotoğraf olarak kalır; varsa yedek bağlantı görünür. */
    var off = false;
    var fail = function () {
      if (off) return;
      off = true;
      v.controls = false;
      b.hidden = true;
      var alt = box.querySelector('[data-clip-alt]');
      if (alt) alt.hidden = false;
    };
    v.addEventListener('error', fail);
    var srcEl = v.querySelector('source');
    if (srcEl) srcEl.addEventListener('error', fail);
  });
})();

/* Ölçüm: hangi hazır sorunun tıklandığı dataLayer'a yazılır (ucret, gun, uygunluk, tarih). Sağlık bilgisi yazılmaz.
   WhatsApp ve arama tıklamalarını Google Tag Manager kapsayıcısı bağlantı adresinden kendisi yakalar. */
(function () {
  document.addEventListener('click', function (ev) {
    var a = ev.target.closest ? ev.target.closest('a') : null;
    if (!a) return;
    var soru = a.getAttribute('data-soru');
    if (!soru && a.hasAttribute('data-ask-link')) {
      var r = document.querySelector('[data-ask] input[name="soru"]:checked');
      var m = r && (r.getAttribute('data-qr') || '').match(/qr-[a-z]+-([a-z]+)\.svg/);
      soru = m ? m[1] : '';
    }
    if (soru) (window.dataLayer = window.dataLayer || []).push({ event: 'hazir_soru', soru: soru });
  }, true);
})();

/* Randevu formu: ad ve zaman WhatsApp mesajına yazılır; sunucuya hiçbir şey gitmez.
   Ölçüm için yalnız olay adı ve uygulama gider (ad gönderilmez). */
(function () {
  var f = document.querySelector('[data-lf]');
  if (!f) return;
  f.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var ad = (f.ad.value || '').replace(/\s+/g, ' ').trim().slice(0, 40);
    var z = f.zaman.value;
    var svc = f.getAttribute('data-svc');
    var msg = 'Merhaba, ' + (ad ? 'ben ' + ad + '. ' : '') + 'Randevu sayfanızdan yazıyorum. ' +
      (/bilmiyorum/i.test(z) ? svc + ' hakkında bilgi almak istiyorum.' : svc + ' için ' + z.toLowerCase() + ' uygun bir gününüz var mı?');
    var url = f.getAttribute('data-wa') + encodeURIComponent(msg);
    (window.dataLayer = window.dataLayer || []).push({ event: 'randevu_formu', uygulama: svc });
    var w = window.open(url, '_blank');
    if (w) { try { w.opener = null; } catch (e) {} } else { location.href = url; }
  });
})();
