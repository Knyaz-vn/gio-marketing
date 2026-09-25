/* BP Medical: адмінка онлайн-запису (vanilla JS поверх REST /bp-booking/v1/admin). */
(function () {
  'use strict';
  var C = window.BPMB_ADMIN;
  var root = document.getElementById('bpmb-admin');
  if (!C || !root) return;

  var WD = ['', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Нд'];
  var STATUS_ACTIONS = [
    ['confirmed', 'Підтвердити'], ['visited', 'Відвідав'], ['no_show', 'Не прийшов'], ['cancelled', 'Скасувати'], ['pending', 'Повернути в очікування']
  ];

  function h(tag, attrs, children) {
    var el = document.createElement(tag);
    if (attrs) for (var k in attrs) {
      var v = attrs[k];
      if (v == null || v === false) continue;
      if (k === 'class') el.className = v;
      else if (k === 'text') el.textContent = v;
      else if (k === 'value') el.value = v;
      else if (k === 'checked') el.checked = !!v;
      else if (k.slice(0, 2) === 'on') el.addEventListener(k.slice(2), v);
      else el.setAttribute(k, v === true ? '' : v);
    }
    (children || []).forEach(function (c) {
      if (c == null || c === false) return;
      el.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c);
    });
    return el;
  }

  function api(method, path, body) {
    return fetch(C.api + path, {
      method: method,
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': C.nonce, 'Content-Type': 'application/json' },
      body: body ? JSON.stringify(body) : undefined
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (d) {
        if (!r.ok) {
          var msg = (d && d.message) || ('HTTP ' + r.status);
          if (d && d.errors) msg += ': ' + d.errors.join('; ');
          throw new Error(msg);
        }
        return d;
      });
    });
  }

  function notice(text, kind) {
    var n = h('div', { class: 'notice notice-' + (kind || 'success') + ' is-dismissible bpmb-notice', role: 'alert' }, [h('p', { text: text })]);
    root.insertBefore(n, root.firstChild.nextSibling);
    setTimeout(function () { if (n.parentNode) n.parentNode.removeChild(n); }, kind === 'error' ? 10000 : 4000);
  }

  function qsFrom(obj) {
    var p = [];
    for (var k in obj) if (obj[k] !== '' && obj[k] != null) p.push(encodeURIComponent(k) + '=' + encodeURIComponent(obj[k]));
    return p.length ? '?' + p.join('&') : '';
  }

  // ---------------------------------------------------------------- вкладки

  var TABS = [
    ['bookings', 'Заявки', renderBookings],
    ['callbacks', 'Зворотні дзвінки', renderCallbacks],
    ['doctors', 'Лікарі та графіки', renderDoctors],
    ['diagnostics', 'Діагностика календаря', renderDiagnostics]
  ];
  if (C.canSettings) TABS.push(['settings', 'Налаштування', renderSettings]);

  var nav = h('nav', { class: 'nav-tab-wrapper bpmb-tabs' });
  var panel = h('div', { class: 'bpmb-panel' });
  root.innerHTML = '';
  root.appendChild(nav);
  root.appendChild(panel);

  function route() {
    var id = (location.hash || '#bookings').slice(1).split('/')[0];
    var tab = TABS.filter(function (t) { return t[0] === id; })[0] || TABS[0];
    nav.innerHTML = '';
    TABS.forEach(function (t) {
      nav.appendChild(h('a', { href: '#' + t[0], class: 'nav-tab' + (t === tab ? ' nav-tab-active' : ''), 'aria-current': t === tab ? 'page' : null }, [t[1]]));
    });
    panel.innerHTML = '';
    panel.appendChild(h('p', { text: 'Завантаження…' }));
    tab[2](panel);
  }
  window.addEventListener('hashchange', route);

  var catalogCache = null;
  function catalog() {
    if (!catalogCache) catalogCache = api('GET', '/doctors');
    return catalogCache;
  }

  // ---------------------------------------------------------------- заявки

  var bFilter = { status: '', doctor: '', phone: '', from: '', to: '', page: 1 };

  function renderBookings(p) {
    catalog().then(function (cat) {
      p.innerHTML = '';
      var status = h('select', { onchange: function (e) { bFilter.status = e.target.value; bFilter.page = 1; load(); } },
        [h('option', { value: '' }, ['Усі статуси'])].concat(Object.keys(C.statuses).map(function (s) {
          return h('option', { value: s, selected: bFilter.status === s ? true : null }, [C.statuses[s]]);
        })));
      var doctor = h('select', { onchange: function (e) { bFilter.doctor = e.target.value; bFilter.page = 1; load(); } },
        [h('option', { value: '' }, ['Усі лікарі'])].concat(cat.doctors.filter(function (d) { return d.bookable; }).map(function (d) {
          return h('option', { value: d.id, selected: bFilter.doctor === d.id ? true : null }, [d.full_name]);
        })));
      var phone = h('input', { type: 'search', placeholder: 'Пошук за телефоном', value: bFilter.phone });
      var timer;
      phone.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { bFilter.phone = phone.value; bFilter.page = 1; load(); }, 350); });
      var from = h('input', { type: 'date', value: bFilter.from, 'aria-label': 'Прийом з', onchange: function (e) { bFilter.from = e.target.value; load(); } });
      var to = h('input', { type: 'date', value: bFilter.to, 'aria-label': 'Прийом по', onchange: function (e) { bFilter.to = e.target.value; load(); } });
      var exp = function (format) {
        var q = { format: format, status: format === 'google_ads' ? (bFilter.status || 'visited') : bFilter.status, from: bFilter.from, to: bFilter.to };
        location.href = C.exportUrl + '&' + qsFrom(q).slice(1);
      };
      var bar = h('div', { class: 'bpmb-filters' }, [status, doctor, phone, h('label', null, ['з ', from]), h('label', null, ['по ', to]),
        h('button', { class: 'button', onclick: function () { exp('generic'); } }, ['CSV']),
        h('button', { class: 'button', title: 'Офлайн-конверсії: статус "Відвідав", GCLID/GBRAID/WBRAID + хеш телефону', onclick: function () { exp('google_ads'); } }, ['CSV для Google Ads'])
      ]);
      var table = h('div');
      p.appendChild(bar);
      p.appendChild(table);

      function load() {
        table.innerHTML = '<p>Завантаження…</p>';
        api('GET', '/bookings' + qsFrom(bFilter)).then(function (d) {
          table.innerHTML = '';
          if (!d.items.length) { table.appendChild(h('p', { text: 'Заявок не знайдено.' })); return; }
          var tb = h('tbody');
          d.items.forEach(function (b) { tb.appendChild(bookingRow(b, load)); });
          table.appendChild(h('table', { class: 'widefat striped bpmb-table' }, [
            h('thead', null, [h('tr', null, ['Прийом', 'Лікар / послуга', 'Пацієнт', 'Джерело', 'Статус', 'Дії'].map(function (t) { return h('th', { text: t }); }))]),
            tb
          ]));
          table.appendChild(pager(d, bFilter, load));
        }, function (e) { table.innerHTML = ''; table.appendChild(h('p', { class: 'bpmb-error', text: e.message })); });
      }
      load();
    }, fail(p));
  }

  function bookingRow(b, reload) {
    var actions = h('div', { class: 'bpmb-actions' });
    STATUS_ACTIONS.forEach(function (a) {
      if (a[0] === b.status) return;
      if (a[0] === 'pending' && b.status === 'pending') return;
      actions.appendChild(h('button', {
        class: 'button button-small' + (a[0] === 'confirmed' ? ' button-primary' : ''),
        onclick: function (e) {
          if (a[0] === 'cancelled' && !confirm('Скасувати заявку ' + b.lead_id + '? Подію в календарі буде позначено як скасовану.')) return;
          e.target.disabled = true;
          api('POST', '/bookings/' + b.id + '/status', { status: a[0] }).then(function (r) {
            notice(b.lead_id + ': ' + C.statuses[r.status] + (r.sync_error ? ' (увага: ' + r.sync_error + ')' : ''), r.sync_error ? 'warning' : 'success');
            reload();
          }, function (err) { e.target.disabled = false; notice(err.message, 'error'); });
        }
      }, [a[1]]));
    });
    return h('tr', null, [
      h('td', null, [h('strong', { text: b.date + ' ' + b.time }), h('br'), h('small', { text: b.location }), h('br'), h('small', { class: 'bpmb-muted', text: b.lead_id + ' · створено ' + b.created_at })]),
      h('td', null, [b.doctor, h('br'), h('small', { text: b.service })]),
      h('td', null, [
        b.patient_name, h('br'),
        b.phone ? h('a', { href: 'tel:' + b.phone.replace(/\s/g, ''), text: b.phone }) : '—',
        b.child_age ? h('div', { class: 'bpmb-muted', text: 'Вік дитини: ' + b.child_age }) : null,
        b.comment ? h('div', { class: 'bpmb-comment', text: b.comment }) : null
      ]),
      h('td', null, [b.source || '—', b.click_ids.length ? h('div', { class: 'bpmb-muted', text: b.click_ids.join(', ') }) : null, b.entry ? h('div', { class: 'bpmb-muted', text: 'вхід: ' + b.entry }) : null]),
      h('td', null, [h('span', { class: 'bpmb-status bpmb-status-' + b.status, text: C.statuses[b.status] || b.status }),
        b.sync_error ? h('div', { class: 'bpmb-warn', text: '⚠ ' + b.sync_error }) : null]),
      h('td', null, [b.anonymized ? h('em', { text: 'анонімізовано' }) : actions])
    ]);
  }

  function pager(d, filter, load) {
    var pages = Math.max(1, Math.ceil(d.total / d.per_page));
    return h('div', { class: 'bpmb-pager' }, [
      h('span', { text: 'Всього: ' + d.total + ' ' }),
      h('button', { class: 'button', disabled: d.page <= 1 ? true : null, onclick: function () { filter.page = d.page - 1; load(); } }, ['‹']),
      h('span', { text: ' ' + d.page + ' / ' + pages + ' ' }),
      h('button', { class: 'button', disabled: d.page >= pages ? true : null, onclick: function () { filter.page = d.page + 1; load(); } }, ['›'])
    ]);
  }

  function fail(p) {
    return function (e) { p.innerHTML = ''; p.appendChild(h('p', { class: 'bpmb-error', text: 'Помилка: ' + e.message })); };
  }

  // ---------------------------------------------------------------- дзвінки

  var cFilter = { status: 'new', phone: '', page: 1 };
  var CB_STATUS = { new: 'Нова', done: 'Опрацьовано', spam: 'Спам' };

  function renderCallbacks(p) {
    p.innerHTML = '';
    var sel = h('select', { onchange: function (e) { cFilter.status = e.target.value; cFilter.page = 1; load(); } },
      [h('option', { value: '' }, ['Усі'])].concat(Object.keys(CB_STATUS).map(function (s) { return h('option', { value: s, selected: cFilter.status === s ? true : null }, [CB_STATUS[s]]); })));
    var phone = h('input', { type: 'search', placeholder: 'Пошук за телефоном', value: cFilter.phone, onchange: function (e) { cFilter.phone = e.target.value; load(); } });
    var table = h('div');
    p.appendChild(h('div', { class: 'bpmb-filters' }, [sel, phone]));
    p.appendChild(table);
    function load() {
      api('GET', '/callbacks' + qsFrom(cFilter)).then(function (d) {
        table.innerHTML = '';
        if (!d.items.length) { table.appendChild(h('p', { text: 'Немає заявок на дзвінок.' })); return; }
        var tb = h('tbody');
        d.items.forEach(function (x) {
          var act = h('div', { class: 'bpmb-actions' });
          Object.keys(CB_STATUS).forEach(function (s) {
            if (s === x.status) return;
            act.appendChild(h('button', { class: 'button button-small', onclick: function () {
              api('POST', '/callbacks/' + x.id + '/status', { status: s }).then(load, function (e) { notice(e.message, 'error'); });
            } }, [CB_STATUS[s]]));
          });
          tb.appendChild(h('tr', null, [
            h('td', { text: x.created_at }),
            h('td', null, [x.name, h('br'), x.phone ? h('a', { href: 'tel:' + x.phone.replace(/\s/g, ''), text: x.phone }) : '—']),
            h('td', null, [x.doctor || x.specialty || '—', x.comment ? h('div', { class: 'bpmb-comment', text: x.comment }) : null]),
            h('td', { text: x.source || '—' }),
            h('td', null, [h('span', { class: 'bpmb-status', text: CB_STATUS[x.status] || x.status })]),
            h('td', null, [act])
          ]));
        });
        table.appendChild(h('table', { class: 'widefat striped bpmb-table' }, [
          h('thead', null, [h('tr', null, ['Створено', 'Контакт', 'Лікар / спеціальність', 'Джерело', 'Статус', 'Дії'].map(function (t) { return h('th', { text: t }); }))]), tb
        ]));
        table.appendChild(pager(d, cFilter, load));
      }, fail(table));
    }
    load();
  }

  // ---------------------------------------------------------------- лікарі

  function renderDoctors(p) {
    catalogCache = null;
    catalog().then(function (cat) {
      p.innerHTML = '';
      var id = (location.hash.split('/')[1] || '');
      if (id) return doctorForm(p, cat, cat.doctors.filter(function (d) { return d.id === id; })[0] || null);
      if (cat.can_edit) p.appendChild(h('p', null, [h('a', { class: 'button button-primary', href: '#doctors/new' }, ['Додати лікаря'])]));
      var tb = h('tbody');
      cat.doctors.forEach(function (d) {
        var sched = (d.schedule || []).map(function (r) { return WD[r.weekday] + ' ' + r.start + '–' + r.end; }).join(', ');
        tb.appendChild(h('tr', null, [
          h('td', null, [h('a', { href: '#doctors/' + d.id, text: d.full_name })]),
          h('td', { text: d.position }),
          h('td', { text: [d.surname].concat(d.aliases || []).filter(function (v, i, a) { return a.indexOf(v) === i; }).join(', ') }),
          h('td', null, [d.bookable ? h('span', { class: 'bpmb-status bpmb-status-confirmed', text: 'так' }) : h('span', { class: 'bpmb-status', text: 'ні (лише блокує час)' })]),
          h('td', { text: sched || '—' }),
          h('td', { text: (d.schedule_overrides || []).length ? d.schedule_overrides.length + ' винятк.' : '' })
        ]));
      });
      p.appendChild(h('table', { class: 'widefat striped bpmb-table' }, [
        h('thead', null, [h('tr', null, ['Лікар', 'Посада', 'Прізвище та aliases (тригер у календарі)', 'Онлайн-запис', 'Графік', 'Винятки'].map(function (t) { return h('th', { text: t }); }))]), tb
      ]));
    }, fail(p));
  }

  function timeInput(v) { return h('input', { type: 'time', step: '300', value: v || '' }); }

  function locSelect(locs, v, allowOff) {
    var opts = allowOff ? [h('option', { value: '' }, ['Вихідний'])] : [];
    return h('select', null, opts.concat(locs.map(function (l) { return h('option', { value: l.id, selected: v === l.id ? true : null }, [l.name + ' (' + l.address + ')']); })));
  }

  function doctorForm(p, cat, d) {
    var isNew = !d;
    d = d || { id: '', slug: '', full_name: '', surname: '', position: '', aliases: [], specialties: [], profile_url: '', photo_url: '', bookable: true, schedule: [], schedule_overrides: [], default_service_ids: ['consult_primary'], service_durations: {} };
    var ro = !cat.can_edit;
    var f = {};
    var field = function (label, input, hint) {
      return h('tr', null, [h('th', { scope: 'row' }, [h('label', { text: label })]), h('td', null, [input, hint ? h('p', { class: 'description', text: hint }) : null])]);
    };
    f.id = h('input', { type: 'text', class: 'regular-text', value: d.id, disabled: isNew ? null : true, pattern: '[a-z0-9-]+' });
    f.full_name = h('input', { type: 'text', class: 'regular-text', value: d.full_name });
    f.surname = h('input', { type: 'text', class: 'regular-text', value: d.surname });
    f.aliases = h('textarea', { rows: '2', class: 'large-text', value: (d.aliases || []).join(', ') });
    f.position = h('input', { type: 'text', class: 'regular-text', value: d.position });
    f.slug = h('input', { type: 'text', class: 'regular-text', value: d.slug });
    f.profile_url = h('input', { type: 'url', class: 'regular-text', value: d.profile_url });
    f.photo_url = h('input', { type: 'url', class: 'regular-text', value: d.photo_url });
    f.bookable = h('input', { type: 'checkbox', checked: d.bookable });

    var specBox = h('div', { class: 'bpmb-checks' });
    cat.specialties.forEach(function (s) {
      specBox.appendChild(h('label', null, [h('input', { type: 'checkbox', value: s.id, checked: (d.specialties || []).indexOf(s.id) >= 0 }), ' ' + s.name]));
    });

    // Графік
    var schedBody = h('tbody');
    var addSched = function (r) {
      r = r || { weekday: 1, start: '09:00', end: '18:00', location_id: cat.locations[0] && cat.locations[0].id };
      var wd = h('select', null, [1, 2, 3, 4, 5, 6, 7].map(function (i) { return h('option', { value: i, selected: +r.weekday === i ? true : null }, [WD[i]]); }));
      var tr = h('tr', null, [h('td', null, [wd]), h('td', null, [timeInput(r.start)]), h('td', null, [timeInput(r.end)]), h('td', null, [locSelect(cat.locations, r.location_id)]),
        h('td', null, [h('button', { type: 'button', class: 'button-link-delete', onclick: function () { tr.parentNode.removeChild(tr); } }, ['Видалити'])])]);
      schedBody.appendChild(tr);
    };
    (d.schedule || []).forEach(addSched);
    var schedTable = h('div', null, [
      h('table', { class: 'widefat bpmb-sub' }, [h('thead', null, [h('tr', null, ['День', 'Початок', 'Кінець', 'Адреса', ''].map(function (t) { return h('th', { text: t }); }))]), schedBody]),
      h('p', null, [
        h('button', { type: 'button', class: 'button', onclick: function () { addSched(); } }, ['+ Інтервал']), ' ',
        h('button', { type: 'button', class: 'button', onclick: function () { [1, 2, 3, 4, 5].forEach(function (w) { addSched({ weekday: w, start: '09:00', end: '18:00', location_id: cat.locations[0].id }); }); } }, ['+ Пн–Пт 09:00–18:00'])
      ])
    ]);

    // Винятки
    var ovBody = h('tbody');
    var addOv = function (o) {
      o = o || { date: '', start: '', end: '', location_id: null };
      var tr = h('tr', null, [h('td', null, [h('input', { type: 'date', value: o.date })]), h('td', null, [timeInput(o.start)]), h('td', null, [timeInput(o.end)]),
        h('td', null, [locSelect(cat.locations, o.location_id, true)]),
        h('td', null, [h('button', { type: 'button', class: 'button-link-delete', onclick: function () { tr.parentNode.removeChild(tr); } }, ['Видалити'])])]);
      ovBody.appendChild(tr);
    };
    (d.schedule_overrides || []).forEach(addOv);
    var ovTable = h('div', null, [
      h('table', { class: 'widefat bpmb-sub' }, [h('thead', null, [h('tr', null, ['Дата', 'Початок', 'Кінець', 'Адреса / вихідний', ''].map(function (t) { return h('th', { text: t }); }))]), ovBody]),
      h('p', null, [h('button', { type: 'button', class: 'button', onclick: function () { addOv(); } }, ['+ Виняток'])])
    ]);

    // Послуги
    var svcBody = h('tbody');
    cat.services.forEach(function (s) {
      var o = (d.service_durations || {})[s.id] || {};
      svcBody.appendChild(h('tr', { 'data-id': s.id }, [
        h('td', null, [h('label', null, [h('input', { type: 'checkbox', class: 'bpmb-def', checked: (d.default_service_ids || []).indexOf(s.id) >= 0 }), ' ' + s.name])]),
        h('td', { text: s.duration_min + ' хв' + (s.buffer_after_min ? ' + ' + s.buffer_after_min : '') }),
        h('td', null, [h('input', { type: 'number', class: 'small-text bpmb-dur', min: '5', max: '480', placeholder: String(s.duration_min), value: o.duration_min || '' })]),
        h('td', null, [h('input', { type: 'number', class: 'small-text bpmb-buf', min: '0', max: '120', placeholder: String(s.buffer_after_min || 0), value: o.buffer_after_min != null && o.duration_min ? o.buffer_after_min : '' })])
      ]));
    });
    var svcTable = h('table', { class: 'widefat bpmb-sub' }, [h('thead', null, [h('tr', null, ['Послуга (✓ = показувати першою)', 'За замовчуванням', 'Тривалість, хв', 'Буфер після, хв'].map(function (t) { return h('th', { text: t }); }))]), svcBody]);

    var save = h('button', { type: 'submit', class: 'button button-primary' }, ['Зберегти']);
    var form = h('form', { class: 'bpmb-form' }, [
      h('p', null, [h('a', { href: '#doctors' }, ['← До списку лікарів'])]),
      h('h2', { text: isNew ? 'Новий лікар' : d.full_name }),
      h('table', { class: 'form-table' }, [h('tbody', null, [
        field('ID', f.id, 'Латиниця, без пробілів. Не змінюється після створення.'),
        field('ПІБ', f.full_name),
        field('Прізвище (тригер)', f.surname, 'Саме за цим словом у назві події календаря визначається зайнятість лікаря.'),
        field('Aliases', f.aliases, 'Через кому: відмінки й варіанти написання (Машевської, Машевській; Дерев\'янко, Деревянко). Матчинг лише цілим словом.'),
        field('Посада', f.position),
        field('Slug', f.slug, 'Для діплінків ?doctor=slug та data-doctor="slug".'),
        field('Сторінка лікаря', f.profile_url),
        field('Фото (URL)', f.photo_url),
        field('Онлайн-запис', h('label', null, [f.bookable, ' доступний (якщо вимкнено, події з прізвищем все одно блокують час, але запису немає)'])),
        field('Спеціальності', specBox),
        field('Графік', schedTable, 'Кілька інтервалів на день і різні адреси в різні дні. Години клініки обрізають графік автоматично.'),
        field('Винятки', ovTable, 'На дату замінюють графік. "Вихідний" означає, що запису немає. Відпустку також можна позначити в календарі all-day подією з прізвищем.'),
        field('Послуги і тривалості', svcTable, 'Порожнє поле = тривалість послуги за замовчуванням.')
      ])]),
      ro ? h('p', { class: 'description', text: 'Редагування доступне лише адміністратору.' }) : h('p', null, [save, ' ',
        !isNew ? h('button', { type: 'button', class: 'button button-link-delete', onclick: function () {
          if (!confirm('Видалити лікаря ' + d.full_name + '? Його події перестануть розпізнаватися.')) return;
          api('DELETE', '/doctors/' + d.id).then(function () { notice('Видалено'); location.hash = '#doctors'; }, function (e) { notice(e.message, 'error'); });
        } }, ['Видалити']) : null])
    ]);
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var rows = function (body, map) { return Array.prototype.map.call(body.children, map); };
      var payload = {
        id: isNew ? f.id.value.trim() : d.id,
        full_name: f.full_name.value.trim(),
        surname: f.surname.value.trim(),
        aliases: f.aliases.value.split(',').map(function (s) { return s.trim(); }).filter(Boolean),
        position: f.position.value.trim(),
        slug: f.slug.value.trim(),
        profile_url: f.profile_url.value.trim(),
        photo_url: f.photo_url.value.trim(),
        bookable: f.bookable.checked,
        specialties: Array.prototype.filter.call(specBox.querySelectorAll('input'), function (i) { return i.checked; }).map(function (i) { return i.value; }),
        schedule: rows(schedBody, function (tr) {
          var s = tr.querySelectorAll('select, input');
          return { weekday: +s[0].value, start: s[1].value, end: s[2].value, location_id: s[3].value };
        }),
        schedule_overrides: rows(ovBody, function (tr) {
          var s = tr.querySelectorAll('select, input');
          return { date: s[0].value, start: s[1].value || null, end: s[2].value || null, location_id: s[3].value || null };
        }),
        default_service_ids: Array.prototype.filter.call(svcBody.querySelectorAll('.bpmb-def'), function (i) { return i.checked; }).map(function (i) { return i.closest('tr').getAttribute('data-id'); }),
        service_durations: {}
      };
      Array.prototype.forEach.call(svcBody.children, function (tr) {
        var dur = tr.querySelector('.bpmb-dur').value, buf = tr.querySelector('.bpmb-buf').value;
        if (dur) payload.service_durations[tr.getAttribute('data-id')] = { duration_min: +dur, buffer_after_min: +(buf || 0) };
      });
      save.disabled = true;
      api('POST', '/doctors', payload).then(function () {
        save.disabled = false;
        catalogCache = null;
        notice('Збережено. Кеш календаря перераховано.');
        if (isNew) location.hash = '#doctors/' + payload.id;
      }, function (err) { save.disabled = false; notice(err.message, 'error'); });
    });
    p.appendChild(form);
  }

  // ---------------------------------------------------------------- діагностика

  function renderDiagnostics(p) {
    api('GET', '/diagnostics').then(function (d) {
      p.innerHTML = '';
      var sync = d.sync || {};
      var btn = h('button', { class: 'button button-primary', onclick: function () {
        btn.disabled = true;
        btn.textContent = 'Читаємо календар…';
        api('POST', '/resync').then(function (r) {
          notice(r.ok ? 'Календар перечитано' : 'Є помилки синхронізації', r.ok ? 'success' : 'warning');
          renderDiagnostics(p);
        }, function (e) { btn.disabled = false; notice(e.message, 'error'); });
      } }, ['Перечитати календар']);
      var modeText = { google: 'Google Calendar (service account)', mock: 'Демо: mock-календар', 'google-missing-key': 'Google Calendar: КЛЮЧ НЕ ЗНАЙДЕНО' }[d.mode] || d.mode;
      var info = h('table', { class: 'widefat bpmb-kv' }, [h('tbody', null, [
        kv('Джерело', modeText),
        kv('Режим матчингу', d.match_mode === 'prefix' ? 'prefix (прізвище першим словом)' : 'anywhere (будь-де в назві)'),
        kv('Остання синхронізація', sync.at ? new Date(sync.at * 1000).toLocaleString('uk-UA') + (sync.ok === false ? ' ⚠ з помилками' : '') : 'ще не було'),
        kv('Подій у кеші', d.stats.total),
        kv('Push-сповіщення', Object.keys(d.watch || {}).length ? Object.keys(d.watch).map(function (k) { var w = d.watch[k]; return w.active ? 'активний до ' + w.expires : 'лише polling' + (w.error ? ' (' + w.error + ')' : ''); }).join('; ') : 'лише polling (кожні 2 хв)'),
        kv('Webhook URL', d.webhook_url)
      ])]);
      p.appendChild(h('p', null, [btn]));
      p.appendChild(info);
      (sync.calendars || []).forEach(function (c) {
        if (c.error) p.appendChild(h('div', { class: 'notice notice-error inline' }, [h('p', { text: c.name + ': ' + c.error })]));
      });
      p.appendChild(eventsTable('Нерозпізнані події', 'Події без прізвища жодного лікаря: нікого не блокують. Перевірте написання прізвища або додайте alias.', d.unrecognized, false));
      p.appendChild(eventsTable('Можливі хибні збіги прізвищ', 'Прізвище лікаря знайдено НЕ на початку назви (можливо, це пацієнт). У режимі anywhere такі події блокують лікаря; у режимі prefix ні.', d.suspicious, true));
      p.appendChild(eventsTable('Події з кількома лікарями', 'Блокуються всі знайдені лікарі (наприклад, хірург + анестезіолог).', d.multi, true));
    }, fail(p));
  }

  function kv(k, v) { return h('tr', null, [h('th', { text: k }), h('td', { text: String(v) })]); }

  function eventsTable(title, hint, rows, withDoctors) {
    var wrap = h('div', { class: 'bpmb-section' }, [h('h2', { text: title + ' (' + rows.length + ')' }), h('p', { class: 'description', text: hint })]);
    if (!rows.length) { wrap.appendChild(h('p', { text: 'Немає.' })); return wrap; }
    var tb = h('tbody');
    rows.forEach(function (e) {
      tb.appendChild(h('tr', null, [
        h('td', { text: e.date + ' ' + e.time }), h('td', { text: e.calendar }), h('td', null, [h('code', { text: e.summary })]),
        withDoctors ? h('td', { text: e.doctors.join(', ') + (e.suspicious.length ? ' | під питанням: ' + e.suspicious.join(', ') : '') }) : null
      ]));
    });
    wrap.appendChild(h('table', { class: 'widefat striped bpmb-table' }, [
      h('thead', null, [h('tr', null, ['Коли', 'Календар', 'Назва події'].concat(withDoctors ? ['Лікарі'] : []).map(function (t) { return h('th', { text: t }); }))]), tb
    ]));
    return wrap;
  }

  // ---------------------------------------------------------------- налаштування

  function renderSettings(p) {
    api('GET', '/settings').then(function (d) {
      p.innerHTML = '';
      var s = d.settings;
      var f = {};
      var row = function (label, input, hint) { return h('tr', null, [h('th', { scope: 'row' }, [label]), h('td', null, [input, hint ? h('p', { class: 'description', text: hint }) : null])]); };
      var num = function (k) { f[k] = h('input', { type: 'number', class: 'small-text', value: s[k] }); return f[k]; };
      var txt = function (k, cls) { f[k] = h('input', { type: 'text', class: cls || 'regular-text', value: Array.isArray(s[k]) ? s[k].join(', ') : (s[k] == null ? '' : s[k]) }); return f[k]; };
      var chk = function (k) { f[k] = h('input', { type: 'checkbox', checked: !!s[k] }); return f[k]; };
      var sel = function (k, opts) { f[k] = h('select', null, opts.map(function (o) { return h('option', { value: o[0], selected: s[k] === o[0] ? true : null }, [o[1]]); })); return f[k]; };

      // Календарі
      var calBody = h('tbody');
      var addCal = function (c) {
        c = c || { id: '', name: '', location_id: null };
        var locSel = h('select', null, [h('option', { value: '' }, ['— без локації —'])].concat(d.locations.map(function (l) { return h('option', { value: l.id, selected: c.location_id === l.id ? true : null }, [l.name]); })));
        var tr = h('tr', null, [h('td', null, [h('input', { type: 'text', class: 'regular-text', value: c.id, placeholder: 'xxxx@group.calendar.google.com' })]), h('td', null, [h('input', { type: 'text', value: c.name })]), h('td', null, [locSel]),
          h('td', null, [h('button', { type: 'button', class: 'button-link-delete', onclick: function () { tr.parentNode.removeChild(tr); } }, ['Видалити'])])]);
        calBody.appendChild(tr);
      };
      (s.calendars || []).forEach(addCal);
      var cal = h('div', null, [h('table', { class: 'widefat bpmb-sub' }, [h('thead', null, [h('tr', null, ['ID календаря', 'Назва', 'Локація (куди писати онлайн-записи)', ''].map(function (t) { return h('th', { text: t }); }))]), calBody]),
        h('p', null, [h('button', { type: 'button', class: 'button', onclick: function () { addCal(); } }, ['+ Календар'])])]);

      var hours = h('table', { class: 'bpmb-sub' }, [h('tbody', null, [1, 2, 3, 4, 5, 6, 7].map(function (w) {
        var v = (s.clinic_hours || {})[w];
        return h('tr', { 'data-wd': w }, [h('th', { text: WD[w] }), h('td', null, [timeInput(v ? v[0] : '')]), h('td', null, [timeInput(v ? v[1] : '')]), h('td', { class: 'description', text: 'порожньо = вихідний' })]);
      }))]);

      var json = function (k, v) { f[k] = h('textarea', { rows: '8', class: 'large-text code', value: JSON.stringify(v, null, 2) }); return f[k]; };
      var sec = d.secrets;
      var secrets = h('ul', null, [
        h('li', { text: 'Google service account: ' + (sec.google_service_account ? '✓ ' + sec.google_client_email : '✗ не знайдено (BPMB_GOOGLE_SA_FILE / BPMB_GOOGLE_SA_JSON)') }),
        h('li', { text: 'Telegram bot token: ' + (sec.telegram_bot_token ? '✓' : '✗ (BPMB_TELEGRAM_BOT_TOKEN)') }),
        h('li', { text: 'Turnstile secret: ' + (sec.turnstile_secret ? '✓' : '✗ (BPMB_TURNSTILE_SECRET, необов\'язково)') })
      ]);

      var save = h('button', { type: 'submit', class: 'button button-primary' }, ['Зберегти налаштування']);
      var form = h('form', null, [
        h('h2', { text: 'Секрети (лише з .env / wp-config.php)' }), secrets,
        h('h2', { text: 'Google Calendar' }),
        h('table', { class: 'form-table' }, [h('tbody', null, [
          row('Календарі', cal, 'Розшарте кожен календар на email service account з правом "Вносити зміни в події".'),
          row('Джерело', sel('calendar_mode', [['auto', 'Авто (Google, якщо є ключ, інакше mock)'], ['google', 'Лише Google'], ['mock', 'Mock (демо)']]), 'Зараз фактично: ' + d.calendar_mode_effective),
          row('match_mode', sel('match_mode', [['anywhere', 'anywhere: прізвище будь-де в назві'], ['prefix', 'prefix: прізвище першим словом (рекомендовано)']])),
          row('Колір онлайн-записів (colorId)', txt('online_color_id', 'small-text'), '1–11 з палітри Google Calendar'),
          row('При скасуванні', sel('cancel_mode', [['mark', 'Позначити "СКАСОВАНО" і зробити прозорою'], ['delete', 'Видалити подію']]))
        ])]),
        h('h2', { text: 'Слоти' }),
        h('table', { class: 'form-table' }, [h('tbody', null, [
          row('Крок слотів, хв', num('slot_step_min')),
          row('Мінімум до запису, хв', num('min_lead_minutes')),
          row('Горизонт запису, днів', num('booking_horizon_days')),
          row('Утримання слота (hold), хв', num('hold_minutes')),
          row('Години клініки', hours),
          row('Святкові / неробочі дні', txt('holidays', 'large-text'), 'Дати через кому: 2026-12-25, 2027-01-01')
        ])]),
        h('h2', { text: 'Сповіщення і безпека' }),
        h('table', { class: 'form-table' }, [h('tbody', null, [
          row('Telegram chat_id', txt('telegram_chat_id'), 'Кілька через кому. Бот має бути доданий у чат.'),
          row('Email адміністраторів', txt('notify_emails', 'large-text'), 'Через кому'),
          row('Заявок з IP на годину', num('rate_limit_per_hour')),
          row('Turnstile site key', txt('turnstile_site_key')),
          row('CORS (дозволені домени)', txt('cors_origins', 'large-text')),
          row('Зберігати заявки, днів', num('retention_days'), 'Після цього ім\'я, телефон і коментар анонімізуються автоматично'),
          row('Політика конфіденційності', txt('privacy_url', 'large-text')),
          row('Телефон для дзвінка', txt('callback_phone'))
        ])]),
        h('h2', { text: 'Віджет і аналітика' }),
        h('table', { class: 'form-table' }, [h('tbody', null, [
          row('Підключати на всіх сторінках', h('label', null, [chk('load_globally'), ' модалка для .bp-booking-open і збереження UTM/click id на 90 днів'])),
          row('Перехоплювати Popup Maker ID', txt('intercept_popup_id', 'small-text'), 'Кнопки #popmake-N відкриватимуть онлайн-запис. Порожньо = не перехоплювати.'),
          row('Зберігати SHA-256 телефону', h('label', null, [chk('store_phone_hash'), ' для Enhanced Conversions for Leads'])),
          row('Назва конверсії Google Ads', txt('ads_conversion_name'))
        ])]),
        h('h2', { text: 'Довідники (JSON)' }),
        h('p', { class: 'description', text: 'Спеціальності: id, name, slug, parent_id, is_child. Послуги: id, name, specialty_id (null = для всіх), duration_min, buffer_after_min, price_from, prep_note_url. Локації: id, name, address, phone, phone_display.' }),
        h('h3', { text: 'Спеціальності' }), json('specialties', d.specialties),
        h('h3', { text: 'Послуги' }), json('services', d.services),
        h('h3', { text: 'Локації' }), json('locations', d.locations),
        h('p', null, [save])
      ]);
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var out = { settings: {} };
        ['slot_step_min', 'min_lead_minutes', 'booking_horizon_days', 'hold_minutes', 'rate_limit_per_hour', 'retention_days'].forEach(function (k) { out.settings[k] = +f[k].value; });
        ['calendar_mode', 'match_mode', 'online_color_id', 'cancel_mode', 'telegram_chat_id', 'turnstile_site_key', 'privacy_url', 'callback_phone', 'intercept_popup_id', 'ads_conversion_name'].forEach(function (k) { out.settings[k] = f[k].value; });
        ['notify_emails', 'holidays', 'cors_origins'].forEach(function (k) { out.settings[k] = f[k].value; });
        ['load_globally', 'store_phone_hash'].forEach(function (k) { out.settings[k] = f[k].checked; });
        out.settings.calendars = Array.prototype.map.call(calBody.children, function (tr) {
          var i = tr.querySelectorAll('input, select');
          return { id: i[0].value.trim(), name: i[1].value.trim(), location_id: i[2].value || null };
        });
        out.settings.clinic_hours = {};
        Array.prototype.forEach.call(hours.querySelectorAll('tr'), function (tr) {
          var i = tr.querySelectorAll('input');
          out.settings.clinic_hours[tr.getAttribute('data-wd')] = i[0].value && i[1].value ? [i[0].value, i[1].value] : null;
        });
        try {
          ['specialties', 'services', 'locations'].forEach(function (k) { out[k] = JSON.parse(f[k].value); });
        } catch (err) { notice('Некоректний JSON у довідниках: ' + err.message, 'error'); return; }
        save.disabled = true;
        api('POST', '/settings', out).then(function () { save.disabled = false; catalogCache = null; notice('Налаштування збережено'); }, function (err) { save.disabled = false; notice(err.message, 'error'); });
      });
      p.appendChild(form);
    }, fail(p));
  }

  route();
})();
