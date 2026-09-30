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
