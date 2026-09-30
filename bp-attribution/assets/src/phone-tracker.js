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
