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
	// Основний канал - REST (fetch keepalive: сторінка не вивантажується при переході за tel:).
	// Якщо REST заблоковано (плагін безпеки, WAF, "Disable REST API") - резервний admin-ajax через sendBeacon.
	function send(ev) {
		var body = JSON.stringify(ev);
		var fallback = function (why) {
			var ok = false;
			try { ok = navigator.sendBeacon(cfg.ajax, new Blob([body], { type: 'text/plain' })); } catch (e) { /* ignore */ }
			debug.sent(ev, 'REST ' + why + ' → admin-ajax ' + (ok ? 'відправлено' : 'НЕ відправлено'));
		};
		if (w.fetch) {
			try {
				fetch(cfg.endpoint, { method: 'POST', body: body, keepalive: true, credentials: 'omit', headers: { 'Content-Type': 'application/json' } })
					.then(function (r) {
						if (r.ok) debug.sent(ev, 'REST ' + r.status + ' OK');
						else if (r.status === 400 || r.status === 429) debug.sent(ev, 'REST ' + r.status + ' (відхилено сервером)');
						else fallback(r.status);
					}, function () { fallback('мережа/блок'); });
				return;
			} catch (e) { /* старий браузер */ }
		}
		var queued = false;
		try { queued = navigator.sendBeacon(cfg.endpoint, new Blob([body], { type: 'application/json' })); } catch (e) { /* ignore */ }
		if (queued) debug.sent(ev, 'REST beacon в черзі');
		else fallback('beacon');
	}

	/* ---- Налагодження: ?bp_debug=1 показує панель на сторінці (зручно з телефону), ?bp_debug=0 вимикає ---- */
	var debug = (function () {
		var on = /[?&]bp_debug=1/.test(location.search) || (!/[?&]bp_debug=0/.test(location.search) && ss('bp_debug') === 1);
		ss('bp_debug', on ? 1 : 0);
		var box, lines = {}, t0 = Math.round(w.performance && performance.now ? performance.now() : 0), state0 = d.readyState;
		function render() {
			if (!on || !d.body) return;
			if (!box) {
				box = d.createElement('div');
				box.id = 'bp-debug';
				box.setAttribute('style', 'position:fixed;left:8px;right:8px;bottom:8px;z-index:2147483647;max-width:520px;' +
					'background:#111;color:#eee;font:12px/1.45 monospace;padding:8px 10px;border-radius:8px;opacity:.93;white-space:pre-wrap;word-break:break-word');
				box.addEventListener('dblclick', function () { box.style.display = 'none'; });
				d.body.appendChild(box);
			}
			var out = [];
			for (var k in lines) out.push(lines[k]);
			box.textContent = out.join('\n');
		}
		function set(k, v) { lines[k] = v; render(); }
		function page() {
			var tels = d.querySelectorAll('a[href]'), n = 0, known = 0, unknown = [];
			for (var i = 0; i < tels.length; i++) {
				var h = (tels[i].getAttribute('href') || '').trim();
				if (!/^tel:/i.test(h)) continue;
				n++;
				var e = P.normalize(h);
				if (e && P.lookup(e, cfg).known) known++;
				else if (unknown.indexOf(e || h) < 0) unknown.push(e || h);
			}
			var src = P.sourceFields(C, attrState(), { url: location.href, referrer: d.referrer, now: Date.now(), siteDomain: cfg.domain || location.hostname, excludeReferrers: cfg.excludeReferrers || [] });
			set('a', 'BP debug · bp-phone ' + (cfg.version || '?') + ' · подвійний тап - сховати');
			set('b', 'Скрипт запущено через ' + t0 + ' мс (стан сторінки: ' + state0 + ')' + (t0 > 4000 ? '  ⚠ схоже на відкладений запуск (Delay JS)' : ''));
			set('c', 'Джерело: ' + src.lt_source + '/' + src.lt_medium + ' · перше: ' + src.ft_source + '/' + src.ft_medium + ' · дотиків: ' + src.touch_count + (w.bpAttr ? '' : '  ⚠ bp-attribution не запущено'));
			set('d', 'tel:-посилань: ' + n + ' (з конфігу: ' + known + (unknown.length ? ', невідомі: ' + unknown.join(', ') : '') + ')');
			if (!lines.e) set('e', 'Натисніть на номер - тут з\'явиться результат.');
		}
		if (on) {
			if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', page); else page();
		}
		return {
			on: on,
			tracked: function (ev, dup, e164) {
				if (!on) return;
				set('e', dup ? 'Клік ' + e164 + ': повтор протягом 60 с у цій сесії - не рахується (так задумано)'
					: 'phone_click: ' + ev.action + ' · ' + ev.phone_label + ' · ' + ev.location + ' · ' + ev.element + ' · ' + ev.page_type + (ev.specialty ? '/' + ev.specialty : '') +
					'\n  джерело ' + ev.lt_source + '/' + ev.lt_medium + ' · dataLayer: ' + (w.dataLayer ? 'так' : 'ні'));
				set('f', dup ? '' : 'Відправка: …');
			},
			sent: function (ev, msg) { if (on) set('f', 'Відправка: ' + msg); }
		};
	})();

	var dev = P.device(navigator.userAgent, navigator.maxTouchPoints || 0);

	function track(action, e164, el, extra) {
		var now = Date.now();
		var sid = sessionId(now);
		var r = P.dedupe(ss('bp_phone_dd') || mem.d, sid, e164 || (extra && extra.phone_label) || '', now, (cfg.dedupe_seconds || 60) * 1000);
		mem.d = r.store;
		ss('bp_phone_dd', r.store);
		if (r.dup) {
			debug.tracked(null, true, e164);
			return null;
		}

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
		debug.tracked(ev, false);
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
		var href = (a.getAttribute('href') || '').trim();
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
