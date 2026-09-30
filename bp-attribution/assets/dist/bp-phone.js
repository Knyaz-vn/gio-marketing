/*!
 * bp-attribution / phone-core.js
 * Чисті функції модуля відстеження кліків по телефону (без DOM): нормалізація номерів,
 * пошук номерів у тексті, тип сторінки, місце кнопки, дедуплікація, сесія, джерело трафіку.
 */
(function (root) {
	'use strict';

	// Український номер у будь-якому форматі сайту: 050-211-99-22, (050) 211 9922, 0 (800) 337 617,
	// 050- 211-99-22, +38 (050) 211-99-22, +380502119922. Між цифрами - до 2 роздільників.
	// Без lookbehind (старі Safari його не підтримують): група 1 - символ перед номером.
	var PHONE_RE = /(^|[^\d+])((?:\+?\s?3\s?8[\s\-.]{0,2})?\(?0(?:[\s\-.()]{0,2}\d){9})(?!\d)/g;

	/** Будь-який запис номера -> E.164 ('' якщо не номер). */
	function normalize(raw) {
		raw = String(raw || '');
		try { raw = decodeURIComponent(raw); } catch (e) { /* як є */ }
		raw = raw.replace(/^tel:/i, '').split(/[;,?]/)[0];
		var d = raw.replace(/\D/g, '');
		if (/^380\d{9}$/.test(d)) return '+' + d;
		if (/^80\d{9}$/.test(d)) return '+3' + d;
		if (/^0\d{9}$/.test(d)) return '+38' + d;
		if (/^\s*\+/.test(raw) && d.length >= 8 && d.length <= 15) return '+' + d;
		return '';
	}

	/** Номери в тексті: [{raw, e164, index}]. */
	function findPhones(text) {
		var out = [], m;
		text = String(text || '');
		PHONE_RE.lastIndex = 0;
		while ((m = PHONE_RE.exec(text))) {
			var e164 = normalize(m[2]);
			if (e164) out.push({ raw: m[2], e164: e164, index: m.index + m[1].length });
		}
		return out;
	}

	/** Номер -> запис конфігу; невідомий -> location 'unknown'. */
	function lookup(e164, cfg) {
		var list = (cfg && cfg.phones) || [];
		for (var i = 0; i < list.length; i++) {
			if (normalize(list[i].number) === e164) return { phone_label: list[i].label, location: list[i].location, known: true };
		}
		return { phone_label: e164, location: 'unknown', known: false };
	}

	function device(ua, touchPoints) {
		ua = ua || '';
		if (/iPad|Tablet|PlayBook|Silk|Kindle/i.test(ua) || (/Macintosh/.test(ua) && touchPoints > 1) ||
			(/Android/i.test(ua) && !/Mobile/i.test(ua))) return 'tablet';
		if (/Mobi|iPhone|iPod|Android|Opera Mini|IEMobile/i.test(ua)) return 'mobile';
		return 'desktop';
	}

	function startsWithAny(path, list) {
		return (list || []).some(function (p) {
			p = String(p).toLowerCase().replace(/\/$/, '');
			return p && (path === p || path.indexOf(p + '/') === 0);
		});
	}

	/**
	 * Контекст сторінки: {page_type, specialty, doctor_slug}.
	 * @param {string} path location.pathname
	 * @param {Object} cfg phones.json
	 * @param {Object} hint підказка сервера (bpPhoneConfig.page), має пріоритет
	 * @param {string} bodyClass
	 */
	function pageContext(path, cfg, hint, bodyClass) {
		cfg = cfg || {};
		hint = hint || {};
		var p = String(path || '/').toLowerCase().split(/[?#]/)[0];
		try { p = decodeURIComponent(p); } catch (e) { /* як є */ }
		p = p.replace(/^\/(uk|ua|ru|en)(?=\/|$)/, '') || '/';
		var types = cfg.page_types || {};
		var ctx = { page_type: 'other', specialty: '', doctor_slug: '' };
		var m;
		if (p === '/' || /(^|\s)home(\s|$)/.test(bodyClass || '')) ctx.page_type = 'home';
		else if ((m = /^\/departments\/([^\/]+)/.exec(p))) { ctx.page_type = 'department'; ctx.specialty = m[1]; }
		else if (startsWithAny(p, cfg.doctor_path_prefixes)) {
			ctx.page_type = 'doctor';
			(cfg.doctor_path_prefixes || []).some(function (pre) {
				pre = String(pre).toLowerCase().replace(/\/?$/, '/');
				if (p.indexOf(pre) === 0) { ctx.doctor_slug = p.slice(pre.length).split('/')[0]; return true; }
				return false;
			});
			ctx.specialty = (cfg.doctor_specialty || {})[ctx.doctor_slug] || '';
		}
		else if (startsWithAny(p, types.contacts)) ctx.page_type = 'contacts';
		else if (startsWithAny(p, types.promo)) ctx.page_type = 'promo';
		else if (startsWithAny(p, types.article) || /(^|\s)single-post(\s|$)/.test(bodyClass || '')) ctx.page_type = 'article';
		if (hint.page_type) ctx.page_type = hint.page_type;
		if (hint.specialty) ctx.specialty = hint.specialty;
		if (hint.doctor_slug) ctx.doctor_slug = hint.doctor_slug;
		return ctx;
	}

	/**
	 * Місце кнопки за найближчими контейнерами.
	 * @param {Array} chain предки від елемента до <html>: {tag, id, cls, role, type, pos}
	 *   type - data-elementor-type, pos - computed position.
	 */
	function element(chain) {
		function any(test) { return chain.some(test); }
		function cls(a, re) { return re.test(' ' + (a.cls || '') + ' '); }
		if (any(function (a) {
			return /^(popmake-|pum-)/.test(a.id || '') || a.role === 'dialog' || a.type === 'popup' ||
				cls(a, /\s(pum|pum-container|popmake|elementor-popup-modal|dialog-widget|modal)\s/);
		})) return 'popup';
		if (any(function (a) {
			return a.tag === 'header' || a.type === 'header' || /^(masthead|header|site-header)$/.test(a.id || '') ||
				cls(a, /\s(elementor-location-header|site-header)\s/);
		})) return 'header';
		if (any(function (a) {
			return a.tag === 'footer' || a.type === 'footer' || /^(colophon|footer|site-footer)$/.test(a.id || '') ||
				cls(a, /\s(elementor-location-footer|site-footer)\s/);
		})) return 'footer';
		if (any(function (a) { return a.pos === 'fixed' || a.pos === 'sticky' || cls(a, /\s(sticky|is-sticky|fixed|floating|sticky-button|floating-button|elementor-sticky--active)\s/); })) return 'sticky';
		if (any(function (a) { return cls(a, /[\s-](banner|hero|elementor-widget-call-to-action|elementor-slides|elementor-widget-slides|swiper)[\s-]/); })) return 'banner';
		return 'content';
	}

	/**
	 * Дедуплікація: той самий номер у тій самій сесії протягом windowMs - один раз.
	 * @return {{dup: boolean, store: Object}}
	 */
	function dedupe(store, sessionId, e164, now, windowMs) {
		var s = {};
		for (var k in store || {}) if (now - store[k] < windowMs) s[k] = store[k]; // прибираємо старі
		var key = sessionId + '|' + e164;
		if (s[key] != null) return { dup: true, store: s };
		s[key] = now;
		return { dup: false, store: s };
	}

	/** Сесія: випадковий id, живе minutes хв від останньої активності. */
	function session(store, now, minutes, newId) {
		if (store && store.id && now - store.la < minutes * 60000) return { id: store.id, la: now };
		return { id: newId(), la: now };
	}

	function uuid(rnd) {
		rnd = rnd || Math.random;
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
			var r = rnd() * 16 | 0;
			return (c === 'x' ? r : (r & 3 | 8)).toString(16);
		});
	}

	/**
	 * Джерело: зі стану bp_attr (cookie / sessionStorage). Якщо його немає -
	 * класифікація поточного заходу тими самими правилами classify.js.
	 */
	function sourceFields(C, state, visit) {
		if (!state || !state.h || !state.h.length) {
			var t = C.classify(visit) || { source: '(direct)', medium: '(none)', campaign: '', term: '', content: '', click_id_type: '', click_id: '', landing_path: '', ts: visit.now };
			state = C.addTouch(null, t, visit.now);
		}
		var f = C.buildFields(state, { now: visit.now });
		return {
			ft_source: f.ft_source, ft_medium: f.ft_medium, ft_campaign: f.ft_campaign,
			lt_source: f.lt_source, lt_medium: f.lt_medium, lt_campaign: f.lt_campaign, lt_term: f.lt_term,
			touch_count: +f.touch_count, paid_in_path: +f.paid_in_path,
			gclid: f.gclid, gbraid: f.gbraid, wbraid: f.wbraid
		};
	}

	var api = {
		PHONE_RE: PHONE_RE, normalize: normalize, findPhones: findPhones, lookup: lookup, device: device,
		pageContext: pageContext, element: element, dedupe: dedupe, session: session, uuid: uuid,
		sourceFields: sourceFields
	};
	if (typeof module === 'object' && module.exports) module.exports = api;
	else root.BPPhoneCore = api;
})(this);

/*!
 * bp-attribution / phone-tracker.js
 * Кліки по tel: (і опційно месенджерах), копіювання номера -> dataLayer 'phone_click' + sendBeacon.
 * Перехід за tel: НЕ блокується і не затримується: усе синхронно, без preventDefault.
 */
(function (w, d) {
	'use strict';
	var P = w.BPPhoneCore, C = w.BPAttrClassify, cfg = w.bpPhoneConfig;
	if (!P || !C || !cfg || w.bpPhone) return;
	var mem = {};

	function ss(k, v) {
		try {
			if (v === undefined) return JSON.parse(sessionStorage.getItem(k) || 'null');
			sessionStorage.setItem(k, JSON.stringify(v));
		} catch (e) { /* приватний режим - працюємо з mem */ }
		return null;
	}
	function newId() {
		return w.crypto && w.crypto.randomUUID ? w.crypto.randomUUID() : P.uuid();
	}
	function sessionId(now) {
		var s = P.session(ss('bp_phone_s') || mem.s, now, cfg.session_minutes || 30, newId);
		mem.s = s;
		ss('bp_phone_s', s);
		return s.id;
	}
	function attrState() {
		if (w.bpAttr) return w.bpAttr.state();
		var m = /(?:^|;\s*)bp_attr=([^;]*)/.exec(d.cookie);
		return m ? C.decode(m[1]) : null;
	}
	function chain(el) {
		var out = [];
		for (; el && el.nodeType === 1; el = el.parentElement) {
			var pos = '';
			try { pos = w.getComputedStyle(el).position; } catch (e) { /* ignore */ }
			out.push({
				tag: el.tagName.toLowerCase(), id: el.id || '', cls: el.getAttribute('class') || '',
				role: el.getAttribute('role') || '', type: el.getAttribute('data-elementor-type') || '', pos: pos
			});
		}
		return out;
	}
	function send(ev) {
		var body = JSON.stringify(ev), ok = false;
		try { ok = navigator.sendBeacon(cfg.endpoint, new Blob([body], { type: 'application/json' })); } catch (e) { /* fallback */ }
		if (!ok && w.fetch) {
			try {
				fetch(cfg.endpoint, { method: 'POST', body: body, keepalive: true, credentials: 'omit', headers: { 'Content-Type': 'application/json' } })['catch'](function () {});
			} catch (e) { /* ignore */ }
		}
	}

	var dev = P.device(navigator.userAgent, navigator.maxTouchPoints || 0);

	function track(action, e164, el, extra) {
		var now = Date.now();
		var sid = sessionId(now);
		var r = P.dedupe(ss('bp_phone_dd') || mem.d, sid, e164 || (extra && extra.phone_label) || '', now, (cfg.dedupe_seconds || 60) * 1000);
		mem.d = r.store;
		ss('bp_phone_dd', r.store);
		if (r.dup) return null;

		var info = e164 ? P.lookup(e164, cfg) : { phone_label: '', location: 'unknown' };
		var ctx = P.pageContext(location.pathname, cfg, cfg.page, d.body ? d.body.className : '');
		var src = P.sourceFields(C, attrState(), {
			url: location.href, referrer: d.referrer, now: now,
			siteDomain: cfg.domain || location.hostname, excludeReferrers: cfg.excludeReferrers || []
		});
		var ev = {
			event_id: newId(), ts: new Date(now).toISOString(), action: action,
			phone_e164: e164 || '', phone_label: info.phone_label, location: info.location,
			device: dev, page_path: location.pathname, page_type: ctx.page_type,
			specialty: ctx.specialty, doctor_slug: ctx.doctor_slug, element: P.element(chain(el)),
			session_id: sid
		};
		var k;
		for (k in src) ev[k] = src[k];
		for (k in extra || {}) ev[k] = extra[k];

		var push = { event: 'phone_click' };
		for (k in ev) push[k] = ev[k];
		(w.dataLayer = w.dataLayer || []).push(push); // до переходу за tel:
		send(ev);
		return ev;
	}

	function messenger(href) {
		if (/^viber:/i.test(href)) return 'viber';
		if (/^(https?:)?\/\/(t\.me|telegram\.me)\//i.test(href) || /^tg:/i.test(href)) return 'telegram';
		if (/^(https?:)?\/\/(wa\.me|api\.whatsapp\.com)\//i.test(href) || /^whatsapp:/i.test(href)) return 'whatsapp';
		return '';
	}

	// Делегований слухач у capture-фазі: ловить і динамічні попапи Elementor / Popup Maker.
	d.addEventListener('click', function (e) {
		var a = e.target && e.target.closest && e.target.closest('a[href]');
		if (!a) return;
		var href = a.getAttribute('href') || '';
		try {
			if (/^tel:/i.test(href)) {
				var e164 = P.normalize(href);
				if (!e164) return;
				track(dev === 'desktop' ? 'click' : 'tap', e164, a);
			} else if (cfg.track_messengers) {
				var m = messenger(href);
				if (m) {
					var num = P.normalize((/(?:number=|wa\.me\/|phone=|t\.me\/\+?)([+\d%]{8,})/.exec(href) || [])[1] || '');
					track('messenger', num, a, num ? null : { phone_label: m });
				}
			}
		} catch (err) { /* трекінг ніколи не ламає перехід */ }
	}, true);

	// Копіювання номера з конфігу (десктоп: номер виділили і скопіювали).
	d.addEventListener('copy', function (e) {
		try {
			var sel = String(w.getSelection ? w.getSelection() : '');
			var seen = {};
			P.findPhones(sel).forEach(function (p) {
				if (seen[p.e164] || !P.lookup(p.e164, cfg).known) return;
				seen[p.e164] = 1;
				var node = w.getSelection().anchorNode || e.target;
				track('copy', p.e164, node && node.nodeType === 3 ? node.parentElement : node);
			});
		} catch (err) { /* ignore */ }
	}, true);

	w.bpPhone = { track: track };
})(window, document);
