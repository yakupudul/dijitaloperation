/* Moximu Kadın Hastalıkları ve Doğum: menüler, şeritler, form. Bağımlılık yok. */
(function () {
	'use strict';
	var d = document;
	var dl = function (o) { (window.dataLayer = window.dataLayer || []).push(o); };

	/* Açılır menüler (dokunmatik ve klavye için tıklayarak aç/kapat) */
	d.addEventListener('click', function (e) {
		var ac = e.target.closest('.bk-menu-ac');
		d.querySelectorAll('.bk-menu .alt-var.acik').forEach(function (li) {
			if (!ac || li !== ac.parentNode) { li.classList.remove('acik'); li.querySelector('.bk-menu-ac').setAttribute('aria-expanded', 'false'); }
		});
		if (ac) {
			var li = ac.parentNode, acik = li.classList.toggle('acik');
			ac.setAttribute('aria-expanded', acik ? 'true' : 'false');
		}
	});
	/* Hizmet menüsü: dokunmatik ekranda ilk dokunuş paneli açar, ikincisi kategoriye gider */
	d.querySelectorAll('.bk-hm-ust').forEach(function (a) {
		a.addEventListener('touchend', function (e) {
			var li = a.parentNode;
			if (!li.classList.contains('acik')) {
				e.preventDefault();
				d.querySelectorAll('.bk-hmenu-liste>li.acik').forEach(function (x) { x.classList.remove('acik'); });
				li.classList.add('acik');
			}
		});
	});
	d.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') {
			d.querySelectorAll('.alt-var.acik').forEach(function (x) { x.classList.remove('acik'); });
			ocKapat();
			var b = d.querySelector('.bk-buyut'); if (b) b.remove();
		}
	});

	/* Mobil yan menü */
	var oc = d.getElementById('bk-oc'), ham = d.querySelector('.bk-hamburger');
	function ocAc() { if (!oc) return; oc.classList.add('acik'); oc.setAttribute('aria-hidden', 'false'); d.body.classList.add('oc-acik'); ham && ham.setAttribute('aria-expanded', 'true'); var k = oc.querySelector('.bk-oc-kapat'); k && k.focus(); }
	function ocKapat() { if (!oc || !oc.classList.contains('acik')) return; oc.classList.remove('acik'); oc.setAttribute('aria-hidden', 'true'); d.body.classList.remove('oc-acik'); if (ham) { ham.setAttribute('aria-expanded', 'false'); ham.focus(); } }
	ham && ham.addEventListener('click', ocAc);
	oc && oc.addEventListener('click', function (e) {
		if (e.target.closest('[data-kapat]')) ocKapat();
		var b = e.target.closest('.bk-oc-kat-ac');
		if (b) {
			var ic = b.nextElementSibling, acik = b.getAttribute('aria-expanded') === 'true';
			b.setAttribute('aria-expanded', acik ? 'false' : 'true');
			ic.hidden = acik;
		}
	});

	/* Kaydırmalı şeritler */
	d.querySelectorAll('[data-serit]').forEach(function (s) {
		var yol = s.querySelector('.bk-serit-yol'), sol = s.querySelector('.sol'), sag = s.querySelector('.sag');
		function adim() { var c = yol.firstElementChild; return c ? c.getBoundingClientRect().width + 16 : yol.clientWidth; }
		function guncelle() {
			sol.disabled = yol.scrollLeft < 4;
			sag.disabled = yol.scrollLeft + yol.clientWidth >= yol.scrollWidth - 4;
		}
		sol.addEventListener('click', function () { yol.scrollBy({ left: -adim(), behavior: 'smooth' }); });
		sag.addEventListener('click', function () { yol.scrollBy({ left: adim(), behavior: 'smooth' }); });
		yol.addEventListener('scroll', guncelle, { passive: true });
		window.addEventListener('resize', guncelle);
		guncelle();
	});

	/* Görsel büyütme */
	d.addEventListener('click', function (e) {
		var a = e.target.closest('[data-buyut]');
		if (!a) return;
		e.preventDefault();
		var k = d.createElement('div');
		k.className = 'bk-buyut';
		k.setAttribute('role', 'dialog');
		k.innerHTML = '<img alt=""><button type="button" aria-label="Kapat">×</button>';
		k.querySelector('img').src = a.href;
		k.addEventListener('click', function () { k.remove(); });
		d.body.appendChild(k);
		k.querySelector('button').focus();
	});

	/* Form */
	d.querySelectorAll('.bk-form').forEach(function (f) {
		f.addEventListener('submit', function (e) {
			if (!window.fetch || !f.dataset.rest) return;
			e.preventDefault();
			var sonuc = f.querySelector('.bk-form-sonuc'), btn = f.querySelector('.bk-form-gonder');
			sonuc.classList.remove('hata');
			if (!f.checkValidity()) { sonuc.textContent = 'Lütfen zorunlu alanları doldurun ve KVKK onayını işaretleyin.'; sonuc.classList.add('hata'); f.reportValidity && f.reportValidity(); return; }
			btn.disabled = true; sonuc.textContent = 'Gönderiliyor…';
			fetch(f.dataset.rest, { method: 'POST', body: new FormData(f), credentials: 'same-origin' })
				.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
				.then(function (x) {
					sonuc.textContent = x.j && x.j.mesaj ? x.j.mesaj : 'Gönderilemedi.';
					if (x.ok) {
						f.reset();
						dl({ event: 'form_gonderildi', form_turu: f.querySelector('[name=kaynak]').value });
						dl({ event: 'generate_lead', form_turu: f.querySelector('[name=kaynak]').value });
					} else { sonuc.classList.add('hata'); }
				})
				.catch(function () { sonuc.textContent = 'Bağlantı hatası. Lütfen bizi arayın: ' + (d.querySelector('.bk-alt-tel a') || {}).textContent; sonuc.classList.add('hata'); })
				.then(function () { btn.disabled = false; });
		});
	});

	/* Soru sayfası: "Cevabı nasıl buldunuz?" */
	d.querySelectorAll('[data-tepki]').forEach(function (t) {
		t.addEventListener('click', function (e) {
			var b = e.target.closest('button');
			if (!b) return;
			dl({ event: 'soru_tepki', tepki: b.dataset.deger });
			t.querySelector('p').textContent = 'Teşekkürler, geri bildiriminiz alındı.';
			t.classList.add('tamam');
		});
	});

	/* İçindekiler: bağlantıya tıklayınca masaüstünde açık kalsın, telefonda kapansın */
	d.querySelectorAll('.bk-toc a').forEach(function (a) {
		a.addEventListener('click', function () { if (window.innerWidth < 768) a.closest('details').open = false; });
	});
})();
