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
})(this);

/*!
 * bp-attribution / tracker.js
 * Збір дотиків, зберігання з урахуванням згоди, приховані поля у формах,
 * dataLayer-події (lead_submit, contact_click). Без зовнішніх залежностей.
 */
(function (w, d) {
	'use strict';
	var C = w.BPAttrClassify;
	if (!C || w.bpAttr) return;
	var cfg = w.bpAttrConfig || {};
	var NAME = 'bp_attr';
	var SS_KEY = 'bp_attr_pending';
	var FIELDS_NS = 'bp_attr';
	var state, granted = false;

	function now() { return Date.now(); }
	function safe(fn, fb) { try { return fn(); } catch (e) { return fb; } }
	function dl() { return (w.dataLayer = w.dataLayer || []); }

	/* ---------------- згода ---------------- */
	function readCookie(n) {
		var m = new RegExp('(?:^|;\\s*)' + n + '=([^;]*)').exec(d.cookie);
		return m ? safe(function () { return decodeURIComponent(m[1]); }, m[1]) : null;
	}
	// 'granted' | 'denied' | '' (механізм згоди не виявлено)
	function consentStatus() {
		if (cfg.consent === 'off') return 'granted';
		var st = '', q = w.dataLayer || [];
		for (var i = 0; i < q.length; i++) {
			var e = q[i];
			if (e && e[0] === 'consent' && e[2] && e[2].analytics_storage) st = e[2].analytics_storage;
		}
		if (st) return st;
		var c = readCookie('cookieyes-consent');
		if (c) return /analytics:yes/.test(c) ? 'granted' : 'denied';
		c = readCookie('cmplz_statistics');
		if (c) return c === 'allow' ? 'granted' : 'denied';
		c = readCookie('CookieConsent');
		if (c) return /statistics:true/.test(c) ? 'granted' : 'denied';
		c = readCookie('cookie_notice_accepted');
		if (c) return c === 'true' ? 'granted' : 'denied';
		return '';
	}

	/* ---------------- сховище ---------------- */
	function writeCookie(v) {
		var last = state.h[state.h.length - 1];
		var exp = new Date((last ? last.ts : now()) + 90 * 864e5); // 90 днів від останнього дотику
		d.cookie = NAME + '=' + v + ';path=/;expires=' + exp.toUTCString() + ';SameSite=Lax' +
			(cfg.cookieDomain ? ';domain=' + cfg.cookieDomain : '') +
			(location.protocol === 'https:' ? ';Secure' : '');
	}
	function load() {
		var per = C.decode(readCookie(NAME)) || C.decode(safe(function () { return localStorage.getItem(NAME); }));
		var pend = C.decode(safe(function () { return sessionStorage.getItem(SS_KEY); }));
		return pend && (!per || pend.la >= per.la) ? pend : per;
	}
	function save() {
		if (!state || !state.h.length) return;
		var v = C.encode(state);
		if (granted) {
			writeCookie(v);
			safe(function () { localStorage.setItem(NAME, v); });
			safe(function () { sessionStorage.removeItem(SS_KEY); });
		} else {
			safe(function () { sessionStorage.setItem(SS_KEY, v); });
		}
	}
	function checkConsent(final) {
		if (granted) return;
		var st = consentStatus();
		// 'auto': якщо механізму згоди немає - вважаємо згоду наданою після завантаження сторінки
		if (st === 'granted' || (final && st === '' && cfg.consent !== 'require')) {
			granted = true;
			save();
		}
	}

	/* ---------------- поля ---------------- */
	function locationOf(f) {
		if (!f || !f.elements) return '';
		var re = cfg.locationRe ? new RegExp(cfg.locationRe, 'i') : /location|lokac|filial|branch|clinic|локац|філі/i;
		for (var i = 0; i < f.elements.length; i++) {
			var el = f.elements[i];
			if (!el.name || el.name.indexOf(FIELDS_NS + '[') === 0 || !re.test(el.name) || !el.value) continue;
			if (el.tagName === 'SELECT' || el.type === 'hidden' || (el.type === 'radio' && el.checked)) return el.value;
		}
		return '';
	}
	function fields(f) {
		return C.buildFields(state, { now: now(), pageUrl: location.href, location: locationOf(f) });
	}
	function formId(f) {
		var pop = f.closest && f.closest('[id^="popmake-"],[id^="pum-"]');
		var fid = f.querySelector('input[name="form_id"]');
		var id = (fid && fid.value) || f.getAttribute('data-bp-form-id') || f.id || f.getAttribute('name') || 'form';
		return (pop ? 'popmake-' + pop.id.replace(/\D/g, '') + ':' : '') + id;
	}
	function eligible(f) {
		return f && f.tagName === 'FORM' && f.getAttribute('data-bp-attr') !== 'off' &&
			f.getAttribute('role') !== 'search' && !f.querySelector('input[name="s"]') &&
			f.id !== 'commentform' && !/wp-login|wp-admin|\/cart|\/checkout/.test(f.getAttribute('action') || '');
	}
	function setField(f, name, val) {
		var n = FIELDS_NS + '[' + name + ']';
		var i = f.querySelector('input[name="' + n + '"]');
		if (!i) {
			i = d.createElement('input');
			i.type = 'hidden';
			i.name = n;
			f.appendChild(i);
		}
		i.value = val;
		// Якщо у формі Elementor є Hidden-поле з таким ID - теж заповнюємо, щоб значення
		// потрапили у вебхуки/CRM. Лише form_fields[...]: службові input-и форм (form_id тощо) не чіпаємо.
		var e = name !== 'form_id' && f.querySelector('input[type="hidden"][name="form_fields[' + name + ']"]');
		if (e) e.value = val;
	}
	function fill(f) {
		if (!eligible(f)) return;
		var v = fields(f);
		v.form_id = formId(f);
		for (var k in v) setField(f, k, v[k]);
	}

	/* ------- поле "Звідки ви дізналися про нас?" (опційно, за налаштуванням) ------- */
	var SR = cfg.selfReported || [];
	function injectSelfReported(f) {
		var forms = cfg.selfReportedForms || [];
		if (!SR.length || !forms.length || f.querySelector('[name$="[self_reported]"],[name="self_reported"]')) return;
		var id = formId(f);
		if (forms.indexOf('*') < 0 && !forms.some(function (x) { return id === x || id.split(':').pop() === x; })) return;
		var wrap = d.createElement('div');
		wrap.className = 'bp-attr-self-reported elementor-field-group elementor-column elementor-col-100';
		var sel = d.createElement('select');
		sel.name = FIELDS_NS + '[self_reported]';
		sel.className = 'elementor-field elementor-field-textual elementor-size-sm';
		sel.setAttribute('aria-label', cfg.selfReportedLabel);
		[['', cfg.selfReportedLabel]].concat(SR).forEach(function (o) {
			var op = d.createElement('option');
			op.value = o[0];
			op.textContent = o[1];
			sel.appendChild(op);
		});
		wrap.appendChild(sel);
		var btn = f.querySelector('[type="submit"]');
		var anchor = btn && (btn.closest('.elementor-field-group') || btn);
		if (anchor && anchor.parentNode) anchor.parentNode.insertBefore(wrap, anchor);
		else f.appendChild(wrap);
	}

	function prepare(root) {
		var list = root.tagName === 'FORM' ? [root] : root.querySelectorAll ? root.querySelectorAll('form') : [];
		for (var i = 0; i < list.length; i++) {
			if (!eligible(list[i])) continue;
			safe(function () { injectSelfReported(list[i]); });
			fill(list[i]);
		}
	}

	/* ---------------- dataLayer ---------------- */
	function summary(f) {
		var v = fields(f);
		return {
			ft_source: v.ft_source, ft_medium: v.ft_medium, ft_campaign: v.ft_campaign,
			lt_source: v.lt_source, lt_medium: v.lt_medium,
			touch_count: +v.touch_count, paid_in_path: +v.paid_in_path,
			days_to_convert: v.days_to_convert === '' ? undefined : +v.days_to_convert,
			specialty: v.specialty, location: v.location
		};
	}
	var sent = {};
	function lead(f, event, extra) {
		var id = typeof f === 'string' ? f : formId(f);
		var ev = event || 'lead_submit';
		if (sent[ev + id] && now() - sent[ev + id] < 3000) return;
		sent[ev + id] = now();
		var p = summary(typeof f === 'string' ? null : f);
		p.event = ev;
		p.form_id = id;
		for (var k in extra || {}) p[k] = extra[k];
		dl().push(p);
	}

	// Автоматичні lead_submit можна вимкнути (bpAttrConfig.pushLead=false), якщо сайт уже шле таку подію.
	function autoLead(f) { if (cfg.pushLead !== false) lead(f); }

	function contactType(href) {
		if (/^tel:/i.test(href)) return 'phone';
		if (/^viber:/i.test(href)) return 'viber';
		if (/^(https?:)?\/\/(t\.me|telegram\.me)\//i.test(href) || /^tg:/i.test(href)) return 'telegram';
		if (/^(https?:)?\/\/(wa\.me|api\.whatsapp\.com)\//i.test(href) || /^whatsapp:/i.test(href)) return 'whatsapp';
		return '';
	}
	function onClick(e) {
		var a = e.target && e.target.closest && e.target.closest('a[href]');
		var type = a && contactType(a.getAttribute('href'));
		if (!type) return;
		var v = fields(null), p = { event: 'contact_click', contact_type: type, specialty: v.specialty };
		for (var k in v) if (/^(ft|lt)_/.test(k)) p[k] = v[k];
		p.touch_count = +v.touch_count;
		p.paid_in_path = +v.paid_in_path;
		p.days_to_convert = v.days_to_convert === '' ? undefined : +v.days_to_convert;
		dl().push(p);
	}

	/* ---------------- ініціалізація ---------------- */
	function init() {
		granted = consentStatus() === 'granted';
		state = load();
		var t = C.classify({
			url: location.href, referrer: d.referrer, now: now(),
			siteDomain: cfg.domain || location.hostname, excludeReferrers: cfg.excludeReferrers || []
		});
		state = C.addTouch(state, t, now());
		save();

		// відстежуємо оновлення згоди (gtag('consent','update',...) іде через dataLayer.push)
		var q = dl(), push = q.push;
		q.push = function () {
			var r = push.apply(q, arguments);
			safe(function () { checkConsent(false); });
			return r;
		};
		['cookieyes_consent_update', 'cmplz_status_change', 'CookiebotOnAccept'].forEach(function (ev) {
			w.addEventListener(ev, function () { setTimeout(function () { checkConsent(false); }, 0); });
		});
		// Резерв на випадок, якщо GTM підмінить dataLayer.push без виклику нашої обгортки.
		var tries = 0, iv = setInterval(function () {
			checkConsent(false);
			if (granted || ++tries > 120) clearInterval(iv);
		}, 1000);
		var finalCheck = function () { setTimeout(function () { checkConsent(true); }, cfg.consentWait || 2000); };
		if (d.readyState === 'complete') finalCheck(); else w.addEventListener('load', finalCheck);

		d.addEventListener('submit', function (e) { fill(e.target); }, true);
		// Звичайні (не AJAX) форми: подія після того, як скрипти форми вирішили, чи перехоплювати сабміт.
		d.addEventListener('submit', function (e) {
			var f = e.target;
			if (!e.defaultPrevented && eligible(f) && !/elementor-form|wpcf7-form|wpforms-form/.test(f.className)) autoLead(f);
		}, false);
		d.addEventListener('focusin', function (e) { if (e.target.form) fill(e.target.form); }, true);
		d.addEventListener('click', onClick, true);
		// Успішні AJAX-сабміти: Contact Form 7 (DOM-подія), Elementor і WPForms (jQuery-події).
		d.addEventListener('wpcf7mailsent', function (e) { autoLead(e.target); });

		var start = function () {
			if (w.jQuery) {
				w.jQuery(d).on('submit_success', 'form', function () { autoLead(this); });
				w.jQuery(d).on('wpformsAjaxSubmitSuccess', 'form', function () { autoLead(this); });
			}
			prepare(d.body);
			var pending = false;
			new MutationObserver(function (muts) {
				if (pending) return;
				// реагуємо лише на появу нових форм (попапи Elementor/Popup Maker, AJAX-контент)
				var hasForm = muts.some(function (m) {
					return [].some.call(m.addedNodes, function (n) {
						return n.nodeType === 1 && (n.tagName === 'FORM' || !!n.querySelector('form'));
					});
				});
				if (!hasForm) return;
				pending = true;
				setTimeout(function () {
					pending = false;
					prepare(d.body);
				}, 50);
			}).observe(d.body, { childList: true, subtree: true });
		};
		if (d.body) start(); else d.addEventListener('DOMContentLoaded', start);

		w.bpAttr = {
			state: function () { return state; },
			fields: function (f) { return fields(f || null); },
			fill: fill,
			lead: lead,
			consent: function () { return granted; }
		};
		safe(function () { w.dispatchEvent(new CustomEvent('bp_attr_ready')); });
	}

	safe(init);
})(window, document);
