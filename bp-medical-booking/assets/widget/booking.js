/*!
 * BP Medical: віджет онлайн-запису. Vanilla JS, без залежностей.
 * Вставка: <div class="bp-booking" data-doctor="slug" data-specialty="slug" data-entry="doctor_page"></div>
 * Модалка: будь-який елемент з класом .bp-booking-open (data-doctor / data-specialty / data-entry).
 */
(function () {
  'use strict';
  if (window.BPMBooking) return;

  var CFG = window.BPMB_CONFIG || {};
  var API = (CFG.api || '/wp-json/bp-booking/v1').replace(/\/$/, '');
  var ATTR_COOKIE = 'bpmb_attr';
  var ATTR_DAYS = 90;
  var CLICK_KEYS = ['gclid', 'gbraid', 'wbraid', 'fbclid'];
  var UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
  var MONTHS = ['Січень', 'Лютий', 'Березень', 'Квітень', 'Травень', 'Червень', 'Липень', 'Серпень', 'Вересень', 'Жовтень', 'Листопад', 'Грудень'];
  var MONTHS_GEN = ['січня', 'лютого', 'березня', 'квітня', 'травня', 'червня', 'липня', 'серпня', 'вересня', 'жовтня', 'листопада', 'грудня'];
  var WD_SHORT = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Нд'];
  var WD_LONG = ['понеділок', 'вівторок', 'середа', 'четвер', "п'ятниця", 'субота', 'неділя'];
  var uid = 0;

  // ---------------------------------------------------------------- утиліти

  function h(tag, attrs, children) {
    var el = document.createElement(tag);
    if (attrs) {
      for (var k in attrs) {
        if (!Object.prototype.hasOwnProperty.call(attrs, k) || attrs[k] == null || attrs[k] === false) continue;
        var v = attrs[k];
        if (k === 'class') el.className = v;
        else if (k === 'text') el.textContent = v;
        else if (k.slice(0, 2) === 'on') el.addEventListener(k.slice(2), v);
        else el.setAttribute(k, v === true ? '' : v);
      }
    }
    (children || []).forEach(function (c) {
      if (c == null || c === false) return;
      el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return el;
  }

  function nextId(p) { uid += 1; return 'bpb-' + p + '-' + uid; }

  function getCookie(name) {
    var m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[.$?*|{}()[\]\\/+^]/g, '\\$&') + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : null;
  }

  function setCookie(name, value, days) {
    var d = new Date(Date.now() + days * 864e5);
    document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + d.toUTCString() + '; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
  }

  function qs(name) {
    try { return new URLSearchParams(location.search).get(name); } catch (e) { return null; }
  }

  function dl(event, params) {
    // Лише знеособлені параметри: жодних імен і телефонів.
    window.dataLayer = window.dataLayer || [];
    var o = { event: event };
    for (var k in params) if (params[k] != null) o[k] = params[k];
    window.dataLayer.push(o);
  }

  function parseDate(s) { var p = s.split('-'); return new Date(Date.UTC(+p[0], +p[1] - 1, +p[2])); }
  function fmtDate(d) { return d.toISOString().slice(0, 10); }
  function addDays(s, n) { var d = parseDate(s); d.setUTCDate(d.getUTCDate() + n); return fmtDate(d); }
  function daysBetween(a, b) { return Math.round((parseDate(b) - parseDate(a)) / 864e5); }
  function weekdayIdx(s) { return (parseDate(s).getUTCDay() + 6) % 7; }
  function humanDate(s) { var d = parseDate(s); return d.getUTCDate() + ' ' + MONTHS_GEN[d.getUTCMonth()]; }
  function humanDateLong(s) { return WD_LONG[weekdayIdx(s)] + ', ' + humanDate(s); }

  function plural(n, one, few, many) {
    var m10 = n % 10, m100 = n % 100;
    if (m10 === 1 && m100 !== 11) return one;
    if (m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20)) return few;
    return many;
  }

  function api(method, path, body) {
    var ctrl = window.AbortController ? new AbortController() : null;
    var timer = ctrl ? setTimeout(function () { ctrl.abort(); }, 20000) : null;
    return fetch(API + path, {
      method: method,
      headers: body ? { 'Content-Type': 'application/json' } : {},
      body: body ? JSON.stringify(body) : undefined,
      credentials: 'same-origin',
      signal: ctrl ? ctrl.signal : undefined
    }).then(function (r) {
      if (timer) clearTimeout(timer);
      return r.json().catch(function () { return {}; }).then(function (data) {
        return { status: r.status, ok: r.ok, data: data };
      });
    }, function (e) {
      if (timer) clearTimeout(timer);
      return { status: 0, ok: false, data: { message: 'Немає з\'єднання. Перевірте інтернет і спробуйте ще раз.' } };
    });
  }

  var catalogPromise = null;
  function loadCatalog() {
    if (!catalogPromise) {
      catalogPromise = api('GET', '/catalog').then(function (r) {
        if (!r.ok) { catalogPromise = null; throw new Error(r.data.message || 'catalog'); }
        return r.data;
      });
    }
    return catalogPromise;
  }

  // ---------------------------------------------------------------- атрибуція

  /**
   * Зберігає click id / UTM з URL у first-party cookie на 90 днів (останній рекламний клік),
   * з fallback на вже наявні cookie сайту: _gcl_aw, _gcl_gb, _fbc, sbjs_current.
   */
  function captureAttribution() {
    var stored = {};
    try { stored = JSON.parse(getCookie(ATTR_COOKIE) || '{}') || {}; } catch (e) { stored = {}; }
    var fromUrl = {};
    var has = false;
    CLICK_KEYS.concat(UTM_KEYS).forEach(function (k) {
      var v = qs(k);
      if (v) { fromUrl[k] = v.slice(0, 255); has = true; }
    });
    if (has || !stored.landing_page) {
      var ref = document.referrer && document.referrer.indexOf(location.origin) !== 0 ? document.referrer : (stored.referrer || '');
      var next = has ? fromUrl : stored;
      next.landing_page = has || !stored.landing_page ? location.href.split('#')[0].slice(0, 1000) : stored.landing_page;
      next.referrer = ref.slice(0, 1000);
      stored = next;
      try { setCookie(ATTR_COOKIE, JSON.stringify(stored), ATTR_DAYS); } catch (e) { /* cookie заблоковано */ }
    }
    return stored;
  }

  function attribution() {
    var a = captureAttribution();
    var out = {};
    for (var k in a) out[k] = a[k];
    // Fallback: cookie Google Ads Conversion Linker і Meta Pixel.
    var aw = getCookie('_gcl_aw');
    if (!out.gclid && aw) out.gclid = aw.split('.').slice(2).join('.');
    var gb = getCookie('_gcl_gb');
    if (!out.gbraid && gb) out.gbraid = gb.split('.').slice(2).join('.');
    var fbc = getCookie('_fbc');
    if (!out.fbclid && fbc) out.fbclid = fbc.split('.').slice(3).join('.');
    // Sourcebuster (WooCommerce order attribution та ін.): typ=utm|||src=google|||mdm=cpc|||cmp=...
    var sb = getCookie('sbjs_current');
    if (sb && !out.utm_source) {
      var map = { src: 'utm_source', mdm: 'utm_medium', cmp: 'utm_campaign', trm: 'utm_term', cnt: 'utm_content' };
      sb.split('|||').forEach(function (pair) {
        var i = pair.indexOf('=');
        var key = map[pair.slice(0, i)];
        var val = pair.slice(i + 1);
        if (key && val && val !== '(none)') out[key] = val;
      });
    }
    return out;
  }

  // ---------------------------------------------------------------- телефон

  function phoneDigits(v) {
    var d = String(v || '').replace(/\D/g, '');
    if (d.indexOf('380') === 0) d = d.slice(3);
    else if (d.indexOf('80') === 0 && d.length > 9) d = d.slice(2);
    if (d.charAt(0) === '0') d = d.slice(1);
    return d.slice(0, 9);
  }

  function phoneFormat(d) {
    var s = '+380';
    if (d.length) s += ' ' + d.slice(0, 2);
    if (d.length > 2) s += ' ' + d.slice(2, 5);
    if (d.length > 5) s += ' ' + d.slice(5, 7);
    if (d.length > 7) s += ' ' + d.slice(7, 9);
    return s;
  }

  function phoneValid(d) { return /^[3-9]\d{8}$/.test(d); }

  function attachPhoneMask(input) {
    input.addEventListener('focus', function () { if (!input.value) input.value = '+380 '; });
    input.addEventListener('input', function () { input.value = phoneFormat(phoneDigits(input.value)); });
    input.addEventListener('blur', function () { if (!phoneDigits(input.value).length) input.value = ''; });
  }

  // ---------------------------------------------------------------- Turnstile

  var turnstileLoading = null;
  function loadTurnstile() {
    if (window.turnstile) return Promise.resolve(window.turnstile);
    if (!turnstileLoading) {
      turnstileLoading = new Promise(function (resolve, reject) {
        var s = document.createElement('script');
        s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
        s.async = true;
        s.onload = function () { resolve(window.turnstile); };
        s.onerror = reject;
        document.head.appendChild(s);
      });
    }
    return turnstileLoading;
  }

  // ---------------------------------------------------------------- віджет

  function Booking(root, opts) {
    this.root = root;
    this.opts = opts || {};
    this.state = { history: [] };
    this.cat = null;
    this.turnstileToken = '';
    this.startedAt = 0;
    root.classList.add('bpb');
    this.live = h('div', { class: 'bpb-sr', 'aria-live': 'polite', role: 'status' });
    this.view = h('div', { class: 'bpb-view' });
    root.innerHTML = '';
    root.appendChild(this.live);
    root.appendChild(this.view);
    this.init();
  }

  Booking.prototype.init = function () {
    var self = this;
    this.loading('Завантаження…');
    loadCatalog().then(function (cat) {
      self.cat = cat;
      self.index();
      self.applyPreselect();
    }, function () {
      self.fatal();
    });
  };

  Booking.prototype.index = function () {
    var c = this.cat;
    this.doctors = {};
    this.specs = {};
    this.locs = {};
    c.doctors.forEach(function (d) { this.doctors[d.id] = d; }, this);
    c.specialties.forEach(function (s) { this.specs[s.id] = s; }, this);
    c.locations.forEach(function (l) { this.locs[l.id] = l; }, this);
  };

  Booking.prototype.findDoctor = function (key) {
    if (!key) return null;
    if (this.doctors[key]) return this.doctors[key];
    for (var id in this.doctors) if (this.doctors[id].slug === key) return this.doctors[id];
    return null;
  };

  Booking.prototype.findSpec = function (key) {
    if (!key) return null;
    if (this.specs[key]) return this.specs[key];
    for (var id in this.specs) if (this.specs[id].slug === key) return this.specs[id];
    return null;
  };

  /** Лікар поточної сторінки за profile_url (для кнопок у шапці на сторінці лікаря). */
  Booking.prototype.doctorByPath = function () {
    var path = location.pathname.replace(/\/+$/, '/');
    for (var id in this.doctors) {
      var u = this.doctors[id].profile_url;
      if (!u) continue;
      try { if (new URL(u).pathname.replace(/\/+$/, '/') === path) return this.doctors[id]; } catch (e) { /* ignore */ }
    }
    return null;
  };

  Booking.prototype.applyPreselect = function () {
    var o = this.opts;
    var doctor = this.findDoctor(o.doctor || qs('doctor')) || (o.autodetect !== false && !o.specialty ? this.doctorByPath() : null);
    var spec = this.findSpec(o.specialty || qs('specialty'));
    var s = this.state;
    s.entry = o.entry || (doctor ? 'doctor_page' : spec ? 'specialty_page' : 'promo');
    if (doctor) {
      s.specialty = spec && doctor.specialties.indexOf(spec.id) >= 0 ? spec : this.specs[doctor.specialties[0]] || null;
      s.doctor = doctor;
      s.service = doctor.services[0] || null;
      s.lockedDoctor = true;
      this.go('date', true);
    } else if (spec) {
      s.specialty = spec;
      s.lockedSpecialty = true;
      if (!spec.doctor_ids.length) this.go('callback', true);
      else this.go('doctor', true);
    } else {
      this.go('specialty', true);
    }
    if (!o.modal) this.trackOpenWhenVisible();
  };

  Booking.prototype.trackOpenWhenVisible = function () {
    var self = this;
    var fire = function () { if (!self.opened) { self.opened = true; self.track('booking_open', { entry: self.state.entry }); } };
    if (!('IntersectionObserver' in window)) return fire();
    var io = new IntersectionObserver(function (es) {
      if (es.some(function (e) { return e.isIntersecting; })) { fire(); io.disconnect(); }
    }, { threshold: 0.3 });
    io.observe(this.root);
  };

  Booking.prototype.track = function (event, params) { dl(event, params); };

  Booking.prototype.an = function () {
    var s = this.state;
    return {
      specialty: s.specialty ? s.specialty.slug : undefined,
      doctor: s.doctor ? s.doctor.slug : undefined
    };
  };

  // ---- навігація

  Booking.prototype.go = function (step, replace) {
    var s = this.state;
    if (!replace && s.step) s.history.push(s.step);
    s.step = step;
    this.render();
  };

  Booking.prototype.back = function () {
    var s = this.state;
    var prev = s.history.pop();
    if (!prev) return;
    s.step = prev;
    this.render();
  };

  Booking.prototype.render = function () {
    var step = this.state.step;
    var map = {
      specialty: this.stepSpecialty, doctor: this.stepDoctor, service: this.stepService,
      date: this.stepDate, time: this.stepTime, contacts: this.stepContacts, done: this.stepDone, callback: this.stepCallback
    };
    this.stopHoldTimer();
    map[step].call(this);
  };

  Booking.prototype.frame = function (title, body, opts) {
    opts = opts || {};
    var self = this;
    var headingId = nextId('h');
    var steps = ['specialty', 'doctor', 'service', 'date', 'time', 'contacts'];
    var idx = steps.indexOf(this.state.step);
    var header = h('div', { class: 'bpb-head' }, [
      this.state.history.length && this.state.step !== 'done'
        ? h('button', { type: 'button', class: 'bpb-back', 'aria-label': 'Назад', onclick: function () { self.back(); } }, ['←'])
        : null,
      h('h2', { class: 'bpb-title', id: headingId, tabindex: '-1' }, [title])
    ]);
    var progress = idx >= 0 ? h('div', { class: 'bpb-progress', role: 'progressbar', 'aria-label': 'Крок ' + (idx + 1) + ' з ' + steps.length, 'aria-valuemin': '1', 'aria-valuemax': String(steps.length), 'aria-valuenow': String(idx + 1) }, [
      h('span', { style: 'width:' + Math.round(((idx + 1) / steps.length) * 100) + '%' })
    ]) : null;
    var footer = opts.noCallback ? null : h('div', { class: 'bpb-foot' }, [
      h('button', { type: 'button', class: 'bpb-link', onclick: function () { self.go('callback'); } }, ['Не знайшли зручний час? Замовте дзвінок'])
    ]);
    this.view.innerHTML = '';
    this.view.setAttribute('aria-labelledby', headingId);
    [header, progress, h('div', { class: 'bpb-body' }, body), footer].forEach(function (n) { if (n) this.view.appendChild(n); }, this);
    var hEl = this.view.querySelector('.bpb-title');
    if (this.didRender && hEl) hEl.focus({ preventScroll: false });
    this.didRender = true;
    this.say(title);
  };

  Booking.prototype.say = function (text) {
    var live = this.live;
    live.textContent = '';
    setTimeout(function () { live.textContent = text; }, 50);
  };

  Booking.prototype.loading = function (text) {
    this.view.innerHTML = '';
    this.view.appendChild(h('div', { class: 'bpb-loading', 'aria-busy': 'true' }, [h('span', { class: 'bpb-spin', 'aria-hidden': 'true' }), text]));
    this.say(text);
  };

  Booking.prototype.fatal = function () {
    this.view.innerHTML = '';
    var phone = CFG.phone || '';
    this.view.appendChild(h('div', { class: 'bpb-msg bpb-msg-err', role: 'alert' }, [
      'Онлайн-запис тимчасово недоступний. ',
      phone ? h('a', { href: 'tel:' + phone }, ['Зателефонуйте нам']) : 'Зателефонуйте нам',
      '.'
    ]));
  };

  Booking.prototype.alert = function (container, text, kind) {
    var old = container.querySelector('.bpb-msg');
    if (old) old.parentNode.removeChild(old);
    var el = h('div', { class: 'bpb-msg ' + (kind === 'ok' ? 'bpb-msg-ok' : 'bpb-msg-err'), role: 'alert' }, [text]);
    container.insertBefore(el, container.firstChild);
    return el;
  };

  // ---- крок 1: спеціальність

  Booking.prototype.stepSpecialty = function () {
    var self = this;
    var list = h('ul', { class: 'bpb-list', role: 'list' });
    var specs = this.cat.specialties.slice().sort(function (a, b) {
      return (b.doctor_ids.length ? 1 : 0) - (a.doctor_ids.length ? 1 : 0) || a.name.localeCompare(b.name, 'uk');
    });
    var draw = function (q) {
      list.innerHTML = '';
      q = (q || '').toLowerCase().trim();
      specs.forEach(function (sp) {
        if (q && sp.name.toLowerCase().indexOf(q) < 0) return;
        var n = sp.doctor_ids.length;
        list.appendChild(h('li', null, [h('button', {
          type: 'button', class: 'bpb-item',
          onclick: function () { self.pickSpecialty(sp); }
        }, [
          h('span', { class: 'bpb-item-title' }, [sp.name]),
          h('span', { class: 'bpb-item-sub' }, [n ? n + ' ' + plural(n, 'лікар', 'лікарі', 'лікарів') : 'запис через адміністратора'])
        ])]));
      });
      if (!list.children.length) list.appendChild(h('li', { class: 'bpb-empty' }, ['Нічого не знайдено']));
    };
    var searchId = nextId('q');
    var search = h('input', { id: searchId, class: 'bpb-input', type: 'search', placeholder: 'Пошук спеціальності', autocomplete: 'off', oninput: function (e) { draw(e.target.value); } });
    draw('');
    this.frame('Оберіть спеціальність', [
      h('label', { class: 'bpb-sr', for: searchId }, ['Пошук спеціальності']),
      search,
      list
    ]);
  };

  Booking.prototype.pickSpecialty = function (sp) {
    this.state.specialty = sp;
    this.state.doctor = null;
    this.track('booking_specialty_select', { specialty: sp.slug });
    if (!sp.doctor_ids.length) {
      this.state.callbackNote = 'Для спеціальності «' + sp.name + '» запис проводить адміністратор. Залиште номер, і ми передзвонимо.';
      this.go('callback');
    } else {
      this.go('doctor');
    }
  };

  // ---- крок 2: лікар

  Booking.prototype.stepDoctor = function () {
    var self = this;
    var sp = this.state.specialty;
    var docs = sp.doctor_ids.map(function (id) { return self.doctors[id]; }).filter(Boolean);
    var list = h('ul', { class: 'bpb-list bpb-doctors', role: 'list' });
    var nearestEls = {};
    docs.forEach(function (d) {
      var near = h('span', { class: 'bpb-item-sub bpb-near' }, ['Шукаємо найближчу дату…']);
      nearestEls[d.id] = near;
      list.appendChild(h('li', null, [h('button', {
        type: 'button', class: 'bpb-item bpb-doc',
        onclick: function () { self.pickDoctor(d); }
      }, [
        self.avatar(d),
        h('span', { class: 'bpb-doc-info' }, [
          h('span', { class: 'bpb-item-title' }, [d.full_name]),
          h('span', { class: 'bpb-item-sub' }, [d.position]),
          near
        ])
      ])]));
    });
    this.frame(sp.name + ': оберіть лікаря', [list]);
    api('GET', '/nearest?doctors=' + encodeURIComponent(docs.map(function (d) { return d.id; }).join(','))).then(function (r) {
      var map = (r.ok && r.data.nearest) || {};
      docs.forEach(function (d) {
        var date = map[d.id];
        nearestEls[d.id].textContent = date ? 'Найближча дата: ' + humanDate(date) : (r.ok ? 'Немає вільного часу онлайн' : '');
        if (!date && r.ok) nearestEls[d.id].classList.add('bpb-muted');
      });
    });
  };

  Booking.prototype.avatar = function (d) {
    if (d.photo_url) return h('img', { class: 'bpb-avatar', src: d.photo_url, alt: '', loading: 'lazy', width: '56', height: '56' });
    var initials = d.full_name.split(/\s+/).slice(0, 2).map(function (p) { return p.charAt(0); }).join('');
    return h('span', { class: 'bpb-avatar bpb-avatar-i', 'aria-hidden': 'true' }, [initials]);
  };

  Booking.prototype.pickDoctor = function (d) {
    var s = this.state;
    s.doctor = d;
    s.service = null;
    s.month = null;
    s.date = null;
    this.track('booking_doctor_select', this.an());
    if (d.services.length === 1) {
      s.service = d.services[0];
      this.go('date');
    } else {
      this.go('service');
    }
  };

  // ---- крок 3: послуга

  Booking.prototype.stepService = function () {
    var self = this;
    var list = h('ul', { class: 'bpb-list', role: 'list' });
    this.state.doctor.services.forEach(function (sv) {
      list.appendChild(h('li', null, [h('button', {
        type: 'button', class: 'bpb-item',
        onclick: function () { self.state.service = sv; self.state.month = null; self.state.date = null; self.go('date'); }
      }, [
        h('span', { class: 'bpb-item-title' }, [sv.name]),
        h('span', { class: 'bpb-item-sub' }, [sv.duration_min + ' хв' + (sv.price_from ? ' · від ' + sv.price_from + ' грн' : '')])
      ])]));
    });
    this.frame('Оберіть послугу', [this.doctorBadge(), list]);
  };

  Booking.prototype.doctorBadge = function (withService) {
    var s = this.state;
    var self = this;
    var d = s.doctor;
    var sub = [d.position];
    var kids = [this.avatar(d), h('span', { class: 'bpb-doc-info' }, [
      h('span', { class: 'bpb-item-title' }, [d.full_name]),
      h('span', { class: 'bpb-item-sub' }, sub)
    ])];
    var badge = h('div', { class: 'bpb-badge' }, kids);
    if (withService && s.service) {
      var change = d.services.length > 1
        ? h('button', { type: 'button', class: 'bpb-link bpb-small', onclick: function () { self.go('service'); } }, ['змінити'])
        : null;
      badge.appendChild(h('div', { class: 'bpb-badge-service' }, [s.service.name + ' · ' + s.service.duration_min + ' хв ', change]));
    }
    return badge;
  };

  // ---- крок 4: дата

  Booking.prototype.stepDate = function () {
    var self = this;
    var s = this.state;
    this.loading('Завантажуємо вільні дати…');
    var q = '/availability?doctor=' + encodeURIComponent(s.doctor.id) + '&service=' + encodeURIComponent(s.service.id);
    api('GET', q).then(function (r) {
      if (s.step !== 'date') return;
      if (!r.ok) {
        self.frame('Оберіть дату', [self.doctorBadge(true), h('div', { class: 'bpb-msg bpb-msg-err', role: 'alert' }, [r.data.message || 'Не вдалося завантажити дати'])]);
        return;
      }
      var days = r.data.days || [];
      var free = {};
      var any = false;
      days.forEach(function (d) { free[d.date] = d.free; if (d.free > 0) any = true; });
      s.today = r.data.today;
      s.lastDate = r.data.last_date;
      if (!any) {
        self.track('booking_no_slots', self.an());
        self.frame('Немає вільного часу', [
          self.doctorBadge(true),
          h('p', { class: 'bpb-text' }, ['На найближчі ' + (self.cat.settings.horizon_days || 30) + ' днів у лікаря немає вільного часу для онлайн-запису. Залиште номер, і адміністратор підбере зручний час.']),
          h('button', { type: 'button', class: 'bpb-btn', onclick: function () { self.go('callback'); } }, ['Замовити дзвінок'])
        ], { noCallback: true });
        return;
      }
      if (!s.month) {
        var first = days.filter(function (d) { return d.free > 0; })[0].date;
        s.month = first.slice(0, 7);
      }
      self.drawCalendar(free);
    });
  };

  Booking.prototype.drawCalendar = function (free) {
    var self = this;
    var s = this.state;
    var ym = s.month;
    var y = +ym.slice(0, 4), m = +ym.slice(5, 7) - 1;
    var firstDay = ym + '-01';
    var daysIn = new Date(Date.UTC(y, m + 1, 0)).getUTCDate();
    var minMonth = s.today.slice(0, 7), maxMonth = s.lastDate.slice(0, 7);
    var shift = function (delta) {
      var d = new Date(Date.UTC(y, m + delta, 1));
      s.month = fmtDate(d).slice(0, 7);
      self.drawCalendar(free);
      var nav = self.view.querySelector(delta < 0 ? '.bpb-cal-prev' : '.bpb-cal-next');
      if (nav && !nav.disabled) nav.focus(); else { var t = self.view.querySelector('.bpb-cal-title'); if (t) t.focus(); }
    };
    var grid = h('table', { class: 'bpb-cal', role: 'grid', 'aria-label': MONTHS[m] + ' ' + y });
    var thead = h('thead', null, [h('tr', null, WD_SHORT.map(function (w, i) { return h('th', { scope: 'col', abbr: WD_LONG[i] }, [w]); }))]);
    var tbody = h('tbody');
    var row = h('tr');
    for (var i = 0; i < weekdayIdx(firstDay); i++) row.appendChild(h('td'));
    for (var day = 1; day <= daysIn; day++) {
      var date = ym + '-' + (day < 10 ? '0' : '') + day;
      var n = free[date] || 0;
      var label = humanDateLong(date) + (n ? ': ' + n + ' ' + plural(n, 'вільний час', 'вільні часи', 'вільних часів') : ': немає вільного часу');
      var btn = h('button', {
        type: 'button', class: 'bpb-day' + (n ? '' : ' bpb-day-off') + (date === s.date ? ' bpb-day-sel' : '') + (date === s.today ? ' bpb-day-today' : ''),
        'aria-label': label, disabled: !n, 'aria-pressed': date === s.date ? 'true' : 'false'
      }, [String(day)]);
      if (n) btn.addEventListener('click', (function (d) { return function () { self.pickDate(d); }; })(date));
      row.appendChild(h('td', null, [btn]));
      if (weekdayIdx(date) === 6) { tbody.appendChild(row); row = h('tr'); }
    }
    if (row.children.length) tbody.appendChild(row);
    grid.appendChild(thead);
    grid.appendChild(tbody);
    var nav = h('div', { class: 'bpb-cal-nav' }, [
      h('button', { type: 'button', class: 'bpb-cal-prev', 'aria-label': 'Попередній місяць', disabled: ym <= minMonth, onclick: function () { shift(-1); } }, ['‹']),
      h('span', { class: 'bpb-cal-title', tabindex: '-1' }, [MONTHS[m] + ' ' + y]),
      h('button', { type: 'button', class: 'bpb-cal-next', 'aria-label': 'Наступний місяць', disabled: ym >= maxMonth, onclick: function () { shift(1); } }, ['›'])
    ]);
    this.frame('Оберіть дату', [this.doctorBadge(true), nav, grid, h('p', { class: 'bpb-hint' }, ['Сірі дні: немає вільного часу для онлайн-запису.'])]);
  };

  Booking.prototype.pickDate = function (date) {
    this.state.date = date;
    this.go('time');
  };

  // ---- крок 5: час

  Booking.prototype.stepTime = function () {
    var self = this;
    var s = this.state;
    this.loading('Завантажуємо вільний час…');
    var q = '/slots?doctor=' + encodeURIComponent(s.doctor.id) + '&service=' + encodeURIComponent(s.service.id) + '&date=' + s.date;
    api('GET', q).then(function (r) {
      if (s.step !== 'time') return;
      var slots = (r.ok && r.data.slots) || [];
      if (!slots.length) {
        self.frame('Вільного часу вже немає', [
          self.doctorBadge(true),
          h('p', { class: 'bpb-text' }, ['На ' + humanDate(s.date) + ' вільний час щойно зайняли. Оберіть іншу дату.']),
          h('button', { type: 'button', class: 'bpb-btn', onclick: function () { self.back(); } }, ['До календаря'])
        ]);
        return;
      }
      var groups = {};
      var order = [];
      slots.forEach(function (sl) {
        if (!groups[sl.location_id]) { groups[sl.location_id] = []; order.push(sl.location_id); }
        groups[sl.location_id].push(sl);
      });
      var body = [self.doctorBadge(true), h('p', { class: 'bpb-date' }, [humanDateLong(s.date)])];
      order.forEach(function (loc) {
        var l = self.locs[loc];
        if (l) body.push(h('p', { class: 'bpb-loc' }, [l.name + ', ' + l.address]));
        var grid = h('div', { class: 'bpb-times', role: 'group', 'aria-label': 'Вільний час' + (l ? ', ' + l.address : '') });
        groups[loc].forEach(function (sl) {
          grid.appendChild(h('button', { type: 'button', class: 'bpb-time', 'aria-label': sl.time + (l ? ', ' + l.address : ''), onclick: function (e) { self.pickSlot(sl, e.currentTarget); } }, [sl.time]));
        });
        body.push(grid);
      });
      self.frame('Оберіть час', body);
    });
  };

  Booking.prototype.pickSlot = function (slot, btn) {
    var self = this;
    var s = this.state;
    if (btn) { btn.disabled = true; btn.classList.add('bpb-busy'); }
    api('POST', '/holds', { doctor: s.doctor.id, service: s.service.id, start: slot.start }).then(function (r) {
      if (r.status === 201) {
        s.slot = r.data.slot;
        s.hold = { token: r.data.hold_token, expires: r.data.expires_at };
        self.track('booking_slot_select', {
          specialty: s.specialty ? s.specialty.slug : undefined,
          doctor: s.doctor.slug,
          location: s.slot.location_id,
          days_ahead: daysBetween(s.today || s.slot.date, s.slot.date)
        });
        self.go('contacts', s.step === 'contacts');
        return;
      }
      if (btn) { btn.disabled = false; btn.classList.remove('bpb-busy'); }
      if (r.status === 409) {
        self.showAlternatives(r.data, self.view.querySelector('.bpb-body'));
        return;
      }
      self.alert(self.view.querySelector('.bpb-body'), r.data.message || 'Не вдалося забронювати час. Спробуйте ще раз.');
    });
  };

  Booking.prototype.showAlternatives = function (data, container) {
    var self = this;
    var alts = data.alternatives || [];
    var el = this.alert(container, data.message || 'Цей час щойно зайняли.');
    if (alts.length) {
      el.appendChild(h('span', null, [' Найближчий вільний час:']));
      var wrap = h('div', { class: 'bpb-times bpb-alts' });
      alts.forEach(function (sl) {
        wrap.appendChild(h('button', { type: 'button', class: 'bpb-time', onclick: function (e) { self.state.date = sl.date; self.pickSlot(sl, e.currentTarget); } }, [humanDate(sl.date) + ', ' + sl.time]));
      });
      el.appendChild(wrap);
    }
    el.setAttribute('tabindex', '-1');
    el.focus();
  };

  Booking.prototype.startHoldTimer = function (el) {
    var s = this.state;
    var self = this;
    var tick = function () {
      var left = s.hold ? s.hold.expires - Math.floor(Date.now() / 1000) : 0;
      if (left <= 0) {
        el.textContent = 'Бронювання часу завершилося, але заявку ще можна надіслати: ми перевіримо, чи час вільний.';
        self.stopHoldTimer();
        return;
      }
      var mm = Math.floor(left / 60), ss = left % 60;
      el.textContent = 'Час утримується для вас ще ' + mm + ':' + (ss < 10 ? '0' : '') + ss;
    };
    tick();
    this.holdTimer = setInterval(tick, 1000);
  };

  Booking.prototype.stopHoldTimer = function () {
    if (this.holdTimer) { clearInterval(this.holdTimer); this.holdTimer = null; }
  };

  // ---- крок 6: контакти

  Booking.prototype.summary = function () {
    var s = this.state;
    var l = this.locs[s.slot.location_id];
    return h('dl', { class: 'bpb-summary' }, [
      h('dt', null, ['Лікар']), h('dd', null, [s.doctor.full_name]),
      h('dt', null, ['Послуга']), h('dd', null, [s.service.name]),
      h('dt', null, ['Дата і час']), h('dd', null, [humanDateLong(s.slot.date) + ', ' + s.slot.time]),
      l ? h('dt', null, ['Адреса']) : null, l ? h('dd', null, [l.address]) : null
    ]);
  };

  Booking.prototype.field = function (name, label, input, hint) {
    var id = nextId(name);
    input.id = id;
    input.name = name;
    var errId = id + '-err';
    var hintId = hint ? id + '-hint' : null;
    input.setAttribute('aria-describedby', [hintId, errId].filter(Boolean).join(' '));
    input.addEventListener('input', clearFieldError);
    return h('div', { class: 'bpb-field', 'data-field': name }, [
      h('label', { for: id, class: 'bpb-label' }, [label]),
      input,
      hint ? h('p', { id: hintId, class: 'bpb-hint' }, [hint]) : null,
      h('p', { id: errId, class: 'bpb-err', 'aria-live': 'polite' })
    ]);
  };

  function clearFieldError(e) {
    var f = e.target.closest('.bpb-field');
    var err = f && f.querySelector('.bpb-err');
    if (err && err.textContent) { err.textContent = ''; e.target.setAttribute('aria-invalid', 'false'); }
  }

  Booking.prototype.consentField = function () {
    var id = nextId('consent');
    var privacy = (this.cat && this.cat.settings.privacy_url) || CFG.privacyUrl || 'https://bpmedical.com.ua/polityka-konfidentsiynosti/';
    return h('div', { class: 'bpb-field bpb-check', 'data-field': 'consent' }, [
      h('input', { type: 'checkbox', id: id, name: 'consent', required: true, 'aria-describedby': id + '-err', onchange: clearFieldError }),
      h('label', { for: id }, ['Я погоджуюся на обробку персональних даних відповідно до ', h('a', { href: privacy, target: '_blank', rel: 'noopener' }, ['політики конфіденційності'])]),
      h('p', { id: id + '-err', class: 'bpb-err', 'aria-live': 'polite' })
    ]);
  };

  Booking.prototype.honeypot = function () {
    return h('div', { class: 'bpb-hp', 'aria-hidden': 'true' }, [
      h('label', null, ['Не заповнюйте це поле', h('input', { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off' })])
    ]);
  };

  Booking.prototype.turnstileBox = function () {
    var key = this.cat && this.cat.settings.turnstile_site_key;
    if (!key) return null;
    var self = this;
    var box = h('div', { class: 'bpb-turnstile' });
    loadTurnstile().then(function (ts) {
      ts.render(box, { sitekey: key, language: 'uk', callback: function (t) { self.turnstileToken = t; } });
    }, function () { /* без Turnstile сервер поверне помилку captcha */ });
    return box;
  };

  Booking.prototype.showErrors = function (form, fields) {
    var first = null;
    Array.prototype.forEach.call(form.querySelectorAll('.bpb-field'), function (f) {
      var name = f.getAttribute('data-field');
      var err = f.querySelector('.bpb-err');
      var input = f.querySelector('input, textarea');
      var msg = fields && fields[name];
      if (err) err.textContent = msg || '';
      if (input) input.setAttribute('aria-invalid', msg ? 'true' : 'false');
      if (msg && !first) first = input;
    });
    if (first) first.focus();
    return !first;
  };

  Booking.prototype.stepContacts = function () {
    var self = this;
    var s = this.state;
    var isChild = s.specialty && s.specialty.is_child;
    this.startedAt = Date.now();
    var name = h('input', { class: 'bpb-input', type: 'text', autocomplete: 'name', required: true, maxlength: '100' });
    var phone = h('input', { class: 'bpb-input', type: 'tel', autocomplete: 'tel', inputmode: 'tel', required: true, placeholder: '+380 __ ___ __ __' });
    attachPhoneMask(phone);
    var comment = h('textarea', { class: 'bpb-input', rows: '2', maxlength: '500' });
    var age = isChild ? h('input', { class: 'bpb-input', type: 'number', min: '0', max: '17', inputmode: 'numeric', required: true }) : null;
    if (s.contacts) {
      name.value = s.contacts.name || '';
      phone.value = s.contacts.phone || '';
      comment.value = s.contacts.comment || '';
      if (age) age.value = s.contacts.child_age || '';
    }
    var timer = h('p', { class: 'bpb-hold', 'aria-live': 'off' });
    var submit = h('button', { type: 'submit', class: 'bpb-btn' }, ['Записатися']);
    var form = h('form', { class: 'bpb-form', novalidate: true }, [
      isChild ? h('p', { class: 'bpb-note' }, ['Запис дитини здійснює її законний представник (батьки, опікун).']) : null,
      this.field('name', isChild ? "Ваше ім'я (законного представника)" : "Ім'я", name),
      this.field('phone', 'Телефон', phone),
      age ? this.field('child_age', 'Вік дитини (повних років)', age) : null,
      this.field('comment', 'Коментар (необов\'язково)', comment, 'Не вказуйте діагнози та скарги: лікар уточнить усе на прийомі.'),
      this.consentField(),
      this.honeypot(),
      this.turnstileBox(),
      submit
    ]);
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var digits = phoneDigits(phone.value);
      var consent = form.querySelector('input[name=consent]').checked;
      var errs = {};
      if (name.value.trim().length < 2) errs.name = "Вкажіть ім'я";
      if (!phoneValid(digits)) errs.phone = 'Вкажіть номер у форматі +380 XX XXX XX XX';
      if (age && (age.value === '' || +age.value < 0 || +age.value > 17)) errs.child_age = 'Вкажіть вік дитини (0–17)';
      if (!consent) errs.consent = 'Потрібна згода на обробку персональних даних';
      if (!self.showErrors(form, errs)) return;
      s.contacts = { name: name.value, phone: phone.value, comment: comment.value, child_age: age ? age.value : '' };
      submit.disabled = true;
      submit.textContent = 'Надсилаємо…';
      api('POST', '/bookings', {
        doctor: s.doctor.id,
        service: s.service.id,
        specialty: s.specialty ? s.specialty.id : null,
        start: s.slot.start,
        hold_token: s.hold ? s.hold.token : null,
        name: name.value.trim(),
        phone: '+380' + digits,
        comment: comment.value.trim(),
        child_age: age ? age.value : undefined,
        consent: true,
        website: form.querySelector('input[name=website]').value,
        form_started_at: self.startedAt,
        turnstile_token: self.turnstileToken,
        entry: s.entry,
        attribution: attribution()
      }).then(function (r) {
        submit.disabled = false;
        submit.textContent = 'Записатися';
        if (r.status === 201) {
          s.result = r.data;
          s.history = [];
          self.track('booking_submit', {
            specialty: s.specialty ? s.specialty.slug : undefined,
            doctor: s.doctor.slug,
            location: s.slot.location_id,
            service: s.service.id,
            lead_id: r.data.lead_id
          });
          self.go('done', true);
        } else if (r.status === 409) {
          self.showAlternatives(r.data, form);
        } else if (r.status === 422 && r.data.fields) {
          self.showErrors(form, r.data.fields);
        } else {
          self.alert(form, r.data.message || 'Не вдалося надіслати заявку. Спробуйте ще раз або зателефонуйте нам.');
          if (window.turnstile && self.cat.settings.turnstile_site_key) window.turnstile.reset();
        }
      });
    });
    this.frame('Ваші контакти', [this.summary(), timer, form]);
    this.startHoldTimer(timer);
  };

  // ---- готово

  Booking.prototype.stepDone = function () {
    var self = this;
    var s = this.state;
    var r = s.result;
    var l = r.location;
    this.frame('Заявку прийнято', [
      h('div', { class: 'bpb-msg bpb-msg-ok', role: 'status' }, ['Дякуємо! Адміністратор зателефонує вам для підтвердження запису.']),
      h('dl', { class: 'bpb-summary' }, [
        h('dt', null, ['Номер заявки']), h('dd', null, [r.lead_id]),
        h('dt', null, ['Лікар']), h('dd', null, [r.doctor.full_name]),
        h('dt', null, ['Послуга']), h('dd', null, [r.service.name]),
        h('dt', null, ['Дата і час']), h('dd', null, [humanDateLong(r.date) + ', ' + r.time]),
        l ? h('dt', null, ['Адреса']) : null, l ? h('dd', null, [l.address]) : null,
        l && l.phone_display ? h('dt', null, ['Телефон']) : null, l && l.phone_display ? h('dd', null, [h('a', { href: 'tel:' + l.phone_display.replace(/\D/g, '') }, [l.phone_display])]) : null
      ]),
      h('div', { class: 'bpb-actions' }, [
        r.ics_url ? h('a', { class: 'bpb-btn bpb-btn-2', href: r.ics_url, download: 'bpmedical.ics' }, ['Додати в календар (.ics)']) : null,
        r.prep_note_url ? h('a', { class: 'bpb-btn bpb-btn-2', href: r.prep_note_url, target: '_blank', rel: 'noopener' }, ['Як підготуватися до прийому']) : null
      ]),
      s.lockedDoctor ? null : h('button', { type: 'button', class: 'bpb-link', onclick: function () { self.reset(); } }, ['Записатися ще до одного лікаря'])
    ], { noCallback: true });
  };

  Booking.prototype.reset = function () {
    var entry = this.state.entry;
    this.state = { history: [], entry: entry };
    this.applyPreselect();
  };

  // ---- зворотний дзвінок

  Booking.prototype.stepCallback = function () {
    var self = this;
    var s = this.state;
    this.startedAt = Date.now();
    var name = h('input', { class: 'bpb-input', type: 'text', autocomplete: 'name', required: true, maxlength: '100' });
    var phone = h('input', { class: 'bpb-input', type: 'tel', autocomplete: 'tel', inputmode: 'tel', required: true, placeholder: '+380 __ ___ __ __' });
    attachPhoneMask(phone);
    var comment = h('textarea', { class: 'bpb-input', rows: '2', maxlength: '500' });
    var submit = h('button', { type: 'submit', class: 'bpb-btn' }, ['Передзвоніть мені']);
    var form = h('form', { class: 'bpb-form', novalidate: true }, [
      this.field('name', "Ім'я", name),
      this.field('phone', 'Телефон', phone),
      this.field('comment', 'Зручний час для дзвінка або побажання', comment, 'Не вказуйте діагнози та скарги.'),
      this.consentField(),
      this.honeypot(),
      this.turnstileBox(),
      submit
    ]);
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var digits = phoneDigits(phone.value);
      var errs = {};
      if (name.value.trim().length < 2) errs.name = "Вкажіть ім'я";
      if (!phoneValid(digits)) errs.phone = 'Вкажіть номер у форматі +380 XX XXX XX XX';
      if (!form.querySelector('input[name=consent]').checked) errs.consent = 'Потрібна згода на обробку персональних даних';
      if (!self.showErrors(form, errs)) return;
      submit.disabled = true;
      api('POST', '/callback', {
        name: name.value.trim(), phone: '+380' + digits, comment: comment.value.trim(), consent: true,
        doctor: s.doctor ? s.doctor.id : null, specialty: s.specialty ? s.specialty.id : null,
        website: form.querySelector('input[name=website]').value, form_started_at: self.startedAt,
        turnstile_token: self.turnstileToken, entry: s.entry, attribution: attribution()
      }).then(function (r) {
        submit.disabled = false;
        if (r.status === 201) {
          self.track('booking_callback_submit', self.an());
          self.frame('Дякуємо!', [h('div', { class: 'bpb-msg bpb-msg-ok', role: 'status' }, ['Адміністратор зателефонує вам найближчим часом у робочі години клініки.'])], { noCallback: true });
        } else if (r.status === 422 && r.data.fields) {
          self.showErrors(form, r.data.fields);
        } else {
          self.alert(form, r.data.message || 'Не вдалося надіслати. Спробуйте ще раз.');
        }
      });
    });
    var phoneNum = (this.cat && this.cat.settings.callback_phone) || CFG.phone;
    this.frame('Замовити дзвінок', [
      h('p', { class: 'bpb-text' }, [s.callbackNote || 'Залиште номер, і адміністратор підбере зручний час.']),
      form,
      phoneNum ? h('p', { class: 'bpb-hint' }, ['Або зателефонуйте: ', h('a', { href: 'tel:' + phoneNum }, [phoneNum])]) : null
    ], { noCallback: true });
    s.callbackNote = null;
  };

  // ---------------------------------------------------------------- модалка

  var modal = null;

  function openModal(opts, opener) {
    if (!modal) {
      var dialog = h('div', { class: 'bpb-dialog', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Онлайн-запис до лікаря' });
      var closeBtn = h('button', { type: 'button', class: 'bpb-close', 'aria-label': 'Закрити' }, ['×']);
      var content = h('div', { class: 'bpb-dialog-content' });
      dialog.appendChild(closeBtn);
      dialog.appendChild(content);
      var overlay = h('div', { class: 'bpb-overlay', hidden: true }, [dialog]);
      document.body.appendChild(overlay);
      modal = { overlay: overlay, dialog: dialog, content: content, opener: null };
      closeBtn.addEventListener('click', closeModal);
      overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) closeModal(); });
      overlay.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { e.preventDefault(); closeModal(); return; }
        if (e.key !== 'Tab') return;
        var f = Array.prototype.filter.call(dialog.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([tabindex="-1"]), textarea, select, [tabindex="0"]'), function (el) { return el.offsetParent !== null; });
        if (!f.length) return;
        var first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      });
    }
    modal.opener = opener || document.activeElement;
    modal.content.innerHTML = '';
    var host = h('div');
    modal.content.appendChild(host);
    modal.overlay.hidden = false;
    document.documentElement.classList.add('bpb-lock');
    opts.modal = true;
    var w = new Booking(host, opts);
    modal.widget = w;
    dl('booking_open', { entry: opts.entry || (opts.doctor ? 'doctor_page' : opts.specialty ? 'specialty_page' : 'promo') });
    setTimeout(function () { modal.dialog.querySelector('.bpb-close').focus(); }, 30);
    return w;
  }

  function closeModal() {
    if (!modal) return;
    if (modal.widget) modal.widget.stopHoldTimer();
    modal.overlay.hidden = true;
    document.documentElement.classList.remove('bpb-lock');
    if (modal.opener && modal.opener.focus) modal.opener.focus();
  }

  function optsFrom(el) {
    var d = el.dataset || {};
    var entry = d.entry;
    if (!entry && el.closest) {
      if (el.closest('header, nav, .site-header, #masthead')) entry = 'header';
    }
    return { doctor: d.doctor || '', specialty: d.specialty || '', entry: entry || '' };
  }

  function mountAll() {
    Array.prototype.forEach.call(document.querySelectorAll('.bp-booking:not([data-bpb-mounted])'), function (el) {
      el.setAttribute('data-bpb-mounted', '1');
      new Booking(el, optsFrom(el));
    });
  }

  document.addEventListener('click', function (e) {
    var t = e.target && e.target.closest ? e.target : null;
    if (!t) return;
    var popupId = CFG.popupId;
    var trigger = t.closest('.bp-booking-open')
      || (popupId ? t.closest('.popmake-' + popupId + ', a[href="#popmake-' + popupId + '"]') : null);
    if (!trigger) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    openModal(optsFrom(trigger), trigger);
  }, true);

  captureAttribution();
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mountAll);
  else mountAll();

  window.BPMBooking = {
    open: function (opts) { return openModal(opts || {}); },
    close: closeModal,
    mount: function (el, opts) { return new Booking(el, opts || optsFrom(el)); },
    mountAll: mountAll
  };
})();
