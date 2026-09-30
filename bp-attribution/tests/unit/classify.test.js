'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const C = require('../../assets/src/classify.js');

const SITE = 'bpmedical.com.ua';
const T0 = Date.UTC(2026, 8, 1, 10, 0, 0);
const MIN = 60 * 1000;
const DAY = 864e5;

const visit = (url, referrer, now) =>
	C.classify({ url: 'https://bpmedical.com.ua' + url, referrer: referrer || '', siteDomain: SITE, now });

const run = (steps) => steps.reduce((s, [url, ref, now]) => C.addTouch(s, visit(url, ref, now), now), null);

test('gclid + organic referrer google -> google/cpc', () => {
	const t = visit('/?gclid=abc123', 'https://www.google.com/', T0);
	assert.equal(t.source, 'google');
	assert.equal(t.medium, 'cpc');
	assert.equal(t.click_id_type, 'gclid');
	assert.equal(t.click_id, 'abc123');
});

test('gbraid / wbraid -> google/cpc, campaign з utm', () => {
	const t = visit('/departments/cardio/?wbraid=x1&utm_campaign=Brand&utm_term=kardiolog', '', T0);
	assert.deepEqual([t.source, t.medium, t.campaign, t.term, t.click_id_type], ['google', 'cpc', 'Brand', 'kardiolog', 'wbraid']);
	assert.equal(visit('/?gbraid=y', '', T0).medium, 'cpc');
});

test('fbclid без utm -> facebook/social (не платний)', () => {
	const t = visit('/?fbclid=IwAR1', 'https://l.facebook.com/', T0);
	assert.equal(t.source, 'facebook');
	assert.equal(t.medium, 'social');
	assert.equal(C.isPaid(t.medium), false);
	assert.equal(visit('/?fbclid=IwAR1', 'https://l.instagram.com/', T0).source, 'instagram');
});

test('utm_medium=paid_social та нормалізація medium', () => {
	const t = visit('/?utm_source=Facebook%20&utm_medium=Paid_Social&utm_campaign=implant&fbclid=IwAR', 'https://l.facebook.com/', T0);
	assert.deepEqual([t.source, t.medium, t.campaign], ['facebook', 'paid_social', 'implant']);
	assert.equal(C.isPaid(t.medium), true);
	for (const [m, want] of [['PPC', 'cpc'], ['paid', 'cpc'], ['paidsearch', 'cpc'], ['cpm', 'paid_social'], ['paidsocial', 'paid_social'], ['email', 'email']]) {
		assert.equal(visit('/?utm_source=x&utm_medium=' + m, '', T0).medium, want, m);
	}
});

test('referrer google maps -> google/maps', () => {
	assert.deepEqual(pick(visit('/', 'https://www.google.com/maps/place/BP', T0)), ['google', 'maps']);
	assert.deepEqual(pick(visit('/', 'https://maps.app.goo.gl/abc', T0)), ['google', 'maps']);
	assert.deepEqual(pick(visit('/', 'https://maps.google.com.ua/', T0)), ['google', 'maps']);
	assert.deepEqual(pick(visit('/?utm_source=google&utm_medium=gbp&utm_campaign=lviv', 'https://www.google.com/', T0)), ['google', 'gbp']);
});

test('пошукові системи, соцмережі, реферали', () => {
	assert.deepEqual(pick(visit('/', 'https://www.google.com.ua/', T0)), ['google', 'organic']);
	assert.deepEqual(pick(visit('/', 'https://www.bing.com/search?q=x', T0)), ['bing', 'organic']);
	assert.deepEqual(pick(visit('/', 'https://duckduckgo.com/', T0)), ['duckduckgo', 'organic']);
	assert.deepEqual(pick(visit('/', 'https://search.yahoo.com/', T0)), ['yahoo', 'organic']);
	assert.deepEqual(pick(visit('/', 'https://t.co/xyz', T0)), ['twitter', 'social']);
	assert.deepEqual(pick(visit('/', 'https://www.linkedin.com/', T0)), ['linkedin', 'social']);
	assert.deepEqual(pick(visit('/', 'https://www.tiktok.com/', T0)), ['tiktok', 'social']);
	assert.deepEqual(pick(visit('/', 'https://mail.google.com/', T0)), ['mail.google.com', 'referral']);
	assert.deepEqual(pick(visit('/', 'https://www.likarni.com/lviv', T0)), ['likarni.com', 'referral']);
	assert.deepEqual(pick(visit('/?msclkid=1', 'https://www.bing.com/', T0)), ['bing', 'cpc']);
});

test('внутрішній referrer (домен і піддомени) ігнорується', () => {
	assert.equal(visit('/contacts/', 'https://bpmedical.com.ua/', T0), null);
	assert.equal(visit('/contacts/', 'https://www.bpmedical.com.ua/departments/', T0), null);
	assert.equal(visit('/contacts/', 'https://booking.bpmedical.com.ua/', T0), null);
	const s = run([['/?gclid=a', '', T0], ['/contacts/', 'https://bpmedical.com.ua/', T0 + 5 * MIN]]);
	assert.equal(s.h.length, 1);
	// схожий, але чужий домен - зовнішній
	assert.equal(visit('/', 'https://notbpmedical.com.ua/', T0).medium, 'referral');
});

test('виключені referrer-и (платіжні шлюзи) не є дотиком', () => {
	const t = C.classify({ url: 'https://bpmedical.com.ua/thanks/', referrer: 'https://www.liqpay.ua/checkout', siteDomain: SITE, excludeReferrers: ['liqpay.ua'], now: T0 });
	assert.equal(t, null);
});

test('без параметрів і referrer -> direct', () => {
	assert.deepEqual(pick(visit('/', '', T0)), ['(direct)', '(none)']);
});

test('direct після cpc не перезаписує last touch', () => {
	const s = run([['/?gclid=a', '', T0], ['/', '', T0 + 2 * DAY]]);
	assert.equal(s.h.length, 2);
	const f = C.buildFields(s, { now: T0 + 2 * DAY });
	assert.equal(f.ft_medium, 'cpc');
	assert.equal(f.lt_medium, 'cpc');
	assert.equal(f.lt_source, 'google');
	assert.equal(f.touch_path, 'google/cpc > direct');
	assert.equal(f.touch_count, '2');
	assert.equal(f.days_to_convert, '2');
	assert.equal(f.paid_in_path, '1');
	assert.equal(f.gclid, 'a');
});

test('лише прямі заходи -> ft і lt = direct', () => {
	const f = C.buildFields(run([['/', '', T0]]), { now: T0 });
	assert.equal(f.ft_source, '(direct)');
	assert.equal(f.lt_medium, '(none)');
	assert.equal(f.paid_in_path, '0');
});

test('повторний захід з тим самим джерелом < 30 хв не створює дотик', () => {
	let s = run([['/', 'https://www.google.com/', T0], ['/prices/', 'https://www.google.com/', T0 + 10 * MIN]]);
	assert.equal(s.h.length, 1);
	assert.equal(s.n, 1);
	// прямий захід посеред сесії (нова вкладка) - теж не дотик
	s = C.addTouch(s, visit('/', '', T0 + 20 * MIN), T0 + 20 * MIN);
	assert.equal(s.h.length, 1);
	// через 30+ хв неактивності - новий дотик
	s = C.addTouch(s, visit('/', 'https://www.google.com/', T0 + 60 * MIN), T0 + 60 * MIN);
	assert.equal(s.h.length, 2);
	// інше джерело в межах 30 хв - новий дотик
	s = C.addTouch(s, visit('/?gclid=z', 'https://www.google.com/', T0 + 65 * MIN), T0 + 65 * MIN);
	assert.equal(s.h.length, 3);
	assert.equal(s.l.medium, 'cpc');
});

test('ліміт 10 дотиків зберігає first touch', () => {
	const steps = [['/?gclid=first', '', T0]];
	for (let i = 1; i <= 14; i++) steps.push(['/', 'https://ref' + i + '.example.com/', T0 + i * DAY]);
	const s = run(steps);
	assert.equal(s.h.length, 10);
	assert.equal(s.n, 15);
	assert.equal(s.h[0].click_id, 'first');
	assert.equal(s.h[9].source, 'ref14.example.com');
	const f = C.buildFields(s, { now: T0 + 15 * DAY });
	assert.equal(f.ft_medium, 'cpc');
	assert.equal(f.lt_source, 'ref14.example.com');
	assert.equal(f.touch_count, '15');
	assert.equal(f.paid_in_path, '1');
	assert.match(f.touch_path, /^google\/cpc > … > ref6\.example\.com\/referral/);
});

test('paid_in_path зберігається навіть після витіснення платного дотику з історії', () => {
	const steps = [['/', '', T0], ['/?utm_source=facebook&utm_medium=paid_social', '', T0 + DAY]];
	for (let i = 2; i <= 14; i++) steps.push(['/', 'https://r' + i + '.example.com/', T0 + i * DAY]);
	const s = run(steps);
	assert.ok(!s.h.some((t) => t.medium === 'paid_social'));
	assert.equal(C.buildFields(s, { now: T0 + 20 * DAY }).paid_in_path, '1');
});

test('сценарій E2E: gclid -> повернення з Google -> ft=cpc, lt=organic', () => {
	const s = run([['/?gclid=test', '', T0], ['/', 'https://www.google.com/', T0 + DAY]]);
	const f = C.buildFields(s, { now: T0 + DAY, pageUrl: 'https://bpmedical.com.ua/departments/stomatologiya/' });
	assert.equal(f.ft_medium, 'cpc');
	assert.equal(f.lt_medium, 'organic');
	assert.equal(f.paid_in_path, '1');
	assert.equal(f.touch_path, 'google/cpc > google/organic');
	assert.equal(f.specialty, 'stomatologiya');
});

test('encode/decode: round trip, кирилиця, base64url без + / =', () => {
	const s = run([['/?utm_source=google&utm_medium=cpc&utm_campaign=' + encodeURIComponent('Імплантація ?/+'), '', T0]]);
	const enc = C.encode(s);
	assert.doesNotMatch(enc, /[+/=]/);
	const d = C.decode(enc);
	assert.equal(d.h[0].campaign, 'Імплантація ?/+');
	assert.equal(d.l.medium, 'cpc');
	assert.deepEqual(C.buildFields(d, { now: T0 }), C.buildFields(s, { now: T0 }));
	assert.equal(C.decode('%%%'), null);
});

test('cookie не перевищує ліміт розміру', () => {
	const long = 'x'.repeat(100);
	const steps = [];
	for (let i = 0; i < 12; i++) steps.push(['/' + long + '/?gclid=' + 'g'.repeat(150) + '&utm_campaign=' + long + '&utm_term=' + long + '&utm_content=' + long, '', T0 + i * DAY]);
	const enc = C.encode(run(steps));
	assert.ok(enc.length <= 3800, 'len ' + enc.length);
	assert.equal(C.decode(enc).h[0].ts, T0);
});

function pick(t) { return [t.source, t.medium]; }
