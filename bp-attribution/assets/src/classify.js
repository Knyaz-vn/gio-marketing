/*!
 * bp-attribution / classify.js
 * Чисті функції без доступу до DOM: класифікація дотику, історія дотиків,
 * побудова полів заявки, серіалізація cookie. Працює і в браузері, і в Node (тести).
 *
 * Класифікація ТІЛЬКИ за фактичними параметрами URL і document.referrer.
 */
(function (root) {
	'use strict';

	var MIN30 = 30 * 60 * 1000;
	var DAY = 864e5;
	var MAX_HISTORY = 10;
	var MAX_COOKIE = 3800;

	var PAID = { cpc: 1, paid_social: 1, display: 1, video: 1 };
	var MEDIUM_MAP = {
		cpc: 'cpc', ppc: 'cpc', paid: 'cpc', paidsearch: 'cpc',
		paid_social: 'paid_social', paidsocial: 'paid_social', cpm: 'paid_social'
	};
	// Порядок = пріоритет, якщо в URL кілька click id.
	var CLICK_IDS = ['gclid', 'gbraid', 'wbraid', 'msclkid', 'fbclid'];
	var UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

	var SEARCH = [
		[/^(www\.)?google\.[a-z]{2,3}(\.[a-z]{2})?$/, 'google'],
		[/^((www|cn|m)\.)?bing\.[a-z]{2,3}(\.[a-z]{2})?$/, 'bing'],
		[/^((www|html|lite)\.)?duckduckgo\.com$/, 'duckduckgo'],
		[/^(([a-z]{2}\.)?search\.)?yahoo\.[a-z]{2,3}(\.[a-z]{2})?$/, 'yahoo']
	];
	var SOCIAL = [
		[/(^|\.)(facebook\.com|fb\.com|fb\.me)$/, 'facebook'],
		[/(^|\.)instagram\.com$/, 'instagram'],
		[/^(t\.co|(www\.)?twitter\.com|(www\.)?x\.com)$/, 'twitter'],
		[/(^|\.)linkedin\.com$|^lnkd\.in$/, 'linkedin'],
		[/(^|\.)tiktok\.com$/, 'tiktok']
	];

	function clean(v, max) {
		if (v == null) return '';
		v = String(v).replace(/[\u0000-\u001f]/g, '').trim();
		return v.length > (max || 100) ? v.slice(0, max || 100) : v;
	}
	function lc(v) { return clean(v).toLowerCase(); }

	function parseUrl(u) {
		var m = /^([a-z][a-z0-9+.-]*:)?\/\/([^\/?#:]+)(:\d+)?([^?#]*)(\?[^#]*)?/i.exec(u || '');
		if (!m) return null;
		return { host: m[2].toLowerCase(), path: m[4] || '/', query: m[5] || '' };
	}

	function parseQuery(q) {
		var out = {};
		(q || '').replace(/^\?/, '').split('&').forEach(function (pair) {
			if (!pair) return;
			var i = pair.indexOf('=');
			var k = i < 0 ? pair : pair.slice(0, i);
			var v = i < 0 ? '' : pair.slice(i + 1);
			try { k = decodeURIComponent(k.replace(/\+/g, ' ')).toLowerCase(); } catch (e) { return; }
			try { v = decodeURIComponent(v.replace(/\+/g, ' ')); } catch (e) { /* лишаємо як є */ }
			if (!(k in out)) out[k] = v;
		});
		return out;
	}

	function normMedium(m) {
		m = lc(m);
		return MEDIUM_MAP[m] || m;
	}

	function isInternal(host, siteDomain) {
		host = host.replace(/^www\./, '');
		siteDomain = (siteDomain || '').replace(/^www\./, '').toLowerCase();
		return host === siteDomain || host.slice(-siteDomain.length - 1) === '.' + siteDomain;
	}

	function match(list, host) {
		for (var i = 0; i < list.length; i++) if (list[i][0].test(host)) return list[i][1];
		return null;
	}

	/**
	 * Класифікує поточний захід.
	 * @param {Object} o {url, referrer, siteDomain, excludeReferrers[], now}
	 * @return {Object|null} дотик; null = внутрішній перехід (не дотик).
	 *   Прямий захід повертається як дотик з source '(direct)', medium '(none)'.
	 */
	function classify(o) {
		var url = parseUrl(o.url) || { path: '/', query: '' };
		var q = parseQuery(url.query);
		var t = {
			source: '', medium: '', campaign: clean(q.utm_campaign), term: clean(q.utm_term),
			content: clean(q.utm_content), click_id_type: '', click_id: '',
			landing_path: clean(url.path, 200), ts: o.now || Date.now()
		};
		var i, hasUtm = false;
		for (i = 0; i < CLICK_IDS.length; i++) {
			if (q[CLICK_IDS[i]]) { t.click_id_type = CLICK_IDS[i]; t.click_id = clean(q[CLICK_IDS[i]], 150); break; }
		}
		for (i = 0; i < UTM.length; i++) if (UTM[i] in q) hasUtm = true;

		var ref = parseUrl(o.referrer);
		var refHost = ref ? ref.host : '';
		var external = !!ref && !isInternal(refHost, o.siteDomain) &&
			!(o.excludeReferrers || []).some(function (d) { return d && isInternal(refHost, d); });

		var id = t.click_id_type;
		// 1. Google Ads (auto-tagging). msclkid - Microsoft Ads (фактичний параметр платного кліку).
		if (id === 'gclid' || id === 'gbraid' || id === 'wbraid') {
			t.source = 'google'; t.medium = 'cpc';
		} else if (id === 'msclkid' && !q.utm_source) {
			t.source = 'bing'; t.medium = 'cpc';
		// 2. UTM як є.
		} else if (q.utm_source) {
			t.source = lc(q.utm_source); t.medium = normMedium(q.utm_medium) || '(not set)';
		// 3. fbclid без utm - НЕ платний (Meta додає його і до органічних переходів).
		} else if (id === 'fbclid') {
			t.source = /(^|\.)instagram\.com$/.test(refHost) ? 'instagram' : 'facebook';
			t.medium = 'social';
		} else if (hasUtm) {
			// utm_* без utm_source: джерело не вказане, беремо що є.
			t.source = '(not set)'; t.medium = normMedium(q.utm_medium) || '(not set)';
		} else if (external) {
			var maps = refHost === 'maps.app.goo.gl' || /^maps\.google\./.test(refHost) ||
				(/^(www\.)?google\./.test(refHost) && /^\/maps/.test(ref.path));
			var s;
			if (maps) { t.source = 'google'; t.medium = 'maps'; } // 6
			else if ((s = match(SEARCH, refHost))) { t.source = s; t.medium = 'organic'; } // 4
			else if ((s = match(SOCIAL, refHost))) { t.source = s; t.medium = 'social'; } // 5
			else { t.source = refHost.replace(/^www\./, ''); t.medium = 'referral'; } // 7
		} else if (ref) {
			return null; // внутрішній перехід або виключений домен
		} else {
			t.source = '(direct)'; t.medium = '(none)'; // 8
		}
		return t;
	}

	function isDirect(t) { return !!t && t.medium === '(none)' && t.source === '(direct)'; }
	function isPaid(medium) { return !!PAID[medium]; }
	function key(t) { return [t.source, t.medium, t.campaign].join('|'); }

	function emptyState() { return { v: 1, n: 0, h: [], l: null, p: 0, la: 0, ids: {} }; }

	/**
	 * Додає дотик до стану (повертає новий об'єкт).
	 * touch === null означає внутрішній перегляд сторінки (оновлюємо лише активність).
	 */
	function addTouch(state, touch, now) {
		var s = state && state.h ? JSON.parse(JSON.stringify(state)) : emptyState();
		s.ids = s.ids || {};
		now = now || Date.now();
		var last = s.h[s.h.length - 1];
		var lastActive = Math.max(s.la || 0, last ? last.ts : 0);
		var inSession = !!last && now - lastActive < MIN30;

		if (touch) {
			if (touch.click_id_type) s.ids[touch.click_id_type] = touch.click_id;
			var skip = isDirect(touch)
				? inSession // прямий захід посеред сесії (нова вкладка тощо) - не дотик
				: inSession && key(last) === key(touch);
			if (!skip) {
				s.h.push(touch);
				s.n = (s.n || 0) + 1;
				if (!isDirect(touch)) s.l = touch; // direct НЕ перезаписує last touch
				if (isPaid(touch.medium)) s.p = 1;
				// first touch (h[0]) не витісняється ніколи
				while (s.h.length > MAX_HISTORY) s.h.splice(1, 1);
			}
		}
		s.la = now;
		return s;
	}

	function label(t) { return isDirect(t) ? 'direct' : t.source + '/' + t.medium; }

	function iso(ts) { return ts ? new Date(ts).toISOString().replace(/\.\d{3}Z$/, 'Z') : ''; }

	function specialtyFromPath(path) {
		var m = /\/departments\/([^\/?#]+)/.exec(path || '');
		if (!m) return '';
		try { return decodeURIComponent(m[1]).toLowerCase(); } catch (e) { return m[1].toLowerCase(); }
	}

	/**
	 * Поля для прихованих input-ів заявки.
	 * @param {Object} s стан
	 * @param {Object} ctx {now, pageUrl, location}
	 */
	function buildFields(s, ctx) {
		ctx = ctx || {};
		s = s && s.h ? s : emptyState();
		var now = ctx.now || Date.now();
		var ft = s.h[0] || null;
		var lt = s.l || s.h[s.h.length - 1] || null;
		var f = {};
		[['ft', ft], ['lt', lt]].forEach(function (p) {
			var t = p[1] || {};
			f[p[0] + '_source'] = t.source || '';
			f[p[0] + '_medium'] = t.medium || '';
			f[p[0] + '_campaign'] = t.campaign || '';
			f[p[0] + '_term'] = t.term || '';
			f[p[0] + '_content'] = t.content || '';
			f[p[0] + '_landing'] = t.landing_path || '';
			f[p[0] + '_ts'] = iso(t.ts);
			f[p[0] + '_click_id'] = t.click_id || '';
		});
		var path = s.h.map(label);
		if ((s.n || 0) > s.h.length && path.length > 1) path.splice(1, 0, '…');
		f.touch_path = path.join(' > ');
		f.touch_count = String(s.n || 0);
		f.days_to_convert = ft ? String(Math.max(0, Math.floor((now - ft.ts) / DAY))) : '';
		f.paid_in_path = s.p || s.h.some(function (t) { return isPaid(t.medium); }) ? '1' : '0';
		f.gclid = s.ids.gclid || '';
		f.gbraid = s.ids.gbraid || '';
		f.wbraid = s.ids.wbraid || '';
		f.fbclid = s.ids.fbclid || '';
		f.page_url = clean(ctx.pageUrl, 500);
		var pu = parseUrl(ctx.pageUrl);
		f.specialty = specialtyFromPath(pu ? pu.path : ctx.pageUrl);
		f.location = clean(ctx.location);
		return f;
	}

	/* ---------- серіалізація: JSON -> base64url, компактні ключі ---------- */
	var SHORT = { source: 's', medium: 'm', campaign: 'c', term: 't', content: 'o', click_id_type: 'k', click_id: 'i', landing_path: 'p', ts: 'ts' };

	function pack(t) {
		if (!t) return null;
		var o = {};
		for (var k in SHORT) if (t[k]) o[SHORT[k]] = t[k];
		return o;
	}
	function unpack(o) {
		if (!o) return null;
		var t = {};
		for (var k in SHORT) t[k] = o[SHORT[k]] || (k === 'ts' ? 0 : '');
		return t;
	}
	function b64enc(str) {
		var b = typeof btoa === 'function' ? btoa(unescape(encodeURIComponent(str)))
			: Buffer.from(str, 'utf8').toString('base64');
		return b.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	}
	function b64dec(str) {
		str = String(str).replace(/-/g, '+').replace(/_/g, '/');
		while (str.length % 4) str += '=';
		return typeof atob === 'function' ? decodeURIComponent(escape(atob(str)))
			: Buffer.from(str, 'base64').toString('utf8');
	}

	function encode(s) {
		var o = { v: 1, n: s.n, p: s.p, la: s.la, ids: s.ids, l: pack(s.l), h: s.h.map(pack) };
		var out = b64enc(JSON.stringify(o));
		// Обмеження розміру cookie: викидаємо найстаріші дотики після першого.
		while (out.length > MAX_COOKIE && o.h.length > 2) {
			o.h.splice(1, 1);
			out = b64enc(JSON.stringify(o));
		}
		return out;
	}

	function decode(str) {
		if (!str) return null;
		try {
			var o = JSON.parse(b64dec(str));
			if (!o || !o.h || !o.h.length) return null;
			return { v: 1, n: o.n || o.h.length, p: o.p ? 1 : 0, la: o.la || 0, ids: o.ids || {}, l: unpack(o.l), h: o.h.map(unpack) };
		} catch (e) {
			return null;
		}
	}

	var api = {
		classify: classify, addTouch: addTouch, buildFields: buildFields,
		encode: encode, decode: decode, isPaid: isPaid, isDirect: isDirect,
		specialtyFromPath: specialtyFromPath, emptyState: emptyState,
		MIN30: MIN30, MAX_HISTORY: MAX_HISTORY
	};
	if (typeof module === 'object' && module.exports) module.exports = api;
	else root.BPAttrClassify = api;
})(typeof window !== 'undefined' ? window : this);
