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
