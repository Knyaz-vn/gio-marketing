'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const P = require('../../assets/src/phone-core.js');
const C = require('../../assets/src/classify.js');
const cfg = require('../../phones.json');

test('нормалізація всіх форматів номерів у E.164', () => {
	const cases = {
		'050-211-99-22': '+380502119922',
		'(050) 211 9922': '+380502119922',
		'050- 211-99-22': '+380502119922',
		'050 211 99 22': '+380502119922',
		'0502119922': '+380502119922',
		'+38 (050) 211-99-22': '+380502119922',
		'+380502119922': '+380502119922',
		'380502119922': '+380502119922',
		'80502119922': '+380502119922',
		'tel:+380662119922': '+380662119922',
		'tel:066-211-99-22': '+380662119922',
		'tel:%2B380662119922': '+380662119922',
		'(066) 211-99-22': '+380662119922',
		'0 (800) 337 617': '+380800337617',
		'0 800 337 617': '+380800337617',
		'0-800-337-617': '+380800337617',
		'tel:+48123456789': '+48123456789',
		'12345': '',
		'': '',
	};
	for (const [raw, want] of Object.entries(cases)) assert.equal(P.normalize(raw), want, raw);
});

test('пошук номерів у тексті', () => {
	const text = 'Стрілецька: 050- 211-99-22, Коріатовичів (066) 211 9922; гаряча лінія 0 (800) 337 617. Два поспіль: 050 211 99 22 066 211 99 22';
	assert.deepEqual(P.findPhones(text).map((x) => x.e164), ['+380502119922', '+380662119922', '+380800337617', '+380502119922', '+380662119922']);
	const f = P.findPhones('тел.: +38 (050) 211-99-22')[0];
	assert.equal(f.raw, '+38 (050) 211-99-22');
	assert.equal('тел.: +38 (050) 211-99-22'.slice(f.index, f.index + f.raw.length), f.raw);
	// не номери: довгі числа, ціни, коди
	assert.deepEqual(P.findPhones('ID 1234567890123, ціна 1 200 грн, ЄДРПОУ 44367929'), []);
});

test('lookup: конфіг і невідомий номер', () => {
	assert.deepEqual(P.lookup('+380662119922', cfg), { phone_label: '066 Коріатовичів', location: 'koriat', known: true });
	assert.equal(P.lookup('+380800337617', cfg).location, 'hotline');
	assert.deepEqual(P.lookup('+380931111111', cfg), { phone_label: '+380931111111', location: 'unknown', known: false });
});

test('визначення page_type, specialty, doctor_slug', () => {
	const c = { ...cfg, doctor_specialty: { 'ivanenko-olena': 'mamolog' } };
	assert.deepEqual(P.pageContext('/', c), { page_type: 'home', specialty: '', doctor_slug: '' });
	assert.deepEqual(P.pageContext('/departments/mamolog/', c), { page_type: 'department', specialty: 'mamolog', doctor_slug: '' });
	assert.deepEqual(P.pageContext('/doctors/ivanenko-olena/', c), { page_type: 'doctor', specialty: 'mamolog', doctor_slug: 'ivanenko-olena' });
	assert.equal(P.pageContext('/contacts/', c).page_type, 'contacts');
	assert.equal(P.pageContext('/akcii/chek-up/', c).page_type, 'promo');
	assert.equal(P.pageContext('/blog/yak-pidhotuvatys/', c).page_type, 'article');
	assert.equal(P.pageContext('/some-post/', c, {}, 'single single-post').page_type, 'article');
	assert.equal(P.pageContext('/ru/departments/lor/', c).specialty, 'lor');
	assert.equal(P.pageContext('/pro-nas/', c).page_type, 'other');
	assert.equal(P.pageContext('/contactsxyz/', c).page_type, 'other');
	// підказка сервера (CPT лікаря з довільним URL) має пріоритет
	assert.deepEqual(P.pageContext('/team/petro/', c, { page_type: 'doctor', doctor_slug: 'petro', specialty: 'lor' }), { page_type: 'doctor', specialty: 'lor', doctor_slug: 'petro' });
});

test('визначення element за найближчим контейнером', () => {
	const a = (o) => ({ tag: 'div', id: '', cls: '', role: '', type: '', pos: 'static', ...o });
	const html = [a({ tag: 'body' }), a({ tag: 'html' })];
	assert.equal(P.element([a({ tag: 'a' }), a({ tag: 'header', cls: 'elementor elementor-location-header', type: 'header', pos: 'sticky' }), ...html]), 'header');
	assert.equal(P.element([a({ tag: 'a' }), a({ cls: 'elementor elementor-location-footer', type: 'footer' }), ...html]), 'footer');
	assert.equal(P.element([a({ tag: 'a' }), a({ id: 'popmake-13597', cls: 'pum-container popmake' }), a({ id: 'pum-13597', cls: 'pum pum-overlay', pos: 'fixed' }), ...html]), 'popup');
	assert.equal(P.element([a({ tag: 'a' }), a({ cls: 'elementor-popup-modal', role: 'dialog' }), ...html]), 'popup');
	assert.equal(P.element([a({ tag: 'a' }), a({ cls: 'call-btn', pos: 'fixed' }), ...html]), 'sticky');
	assert.equal(P.element([a({ tag: 'a' }), a({ cls: 'elementor-widget elementor-widget-call-to-action' }), ...html]), 'banner');
	assert.equal(P.element([a({ tag: 'a' }), a({ cls: 'home-banner' }), ...html]), 'banner');
	assert.equal(P.element([a({ tag: 'a' }), a({ cls: 'elementor-widget-text-editor' }), ...html]), 'content');
});

test('пристрій', () => {
	assert.equal(P.device('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148', 5), 'mobile');
	assert.equal(P.device('Mozilla/5.0 (Linux; Android 14; Pixel 8) Mobile Safari/537.36', 5), 'mobile');
	assert.equal(P.device('Mozilla/5.0 (Linux; Android 13; SM-X700) Safari/537.36', 5), 'tablet');
	assert.equal(P.device('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605', 5), 'tablet');
	assert.equal(P.device('Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/140', 0), 'desktop');
});

test('дедуплікація 60 с у межах сесії', () => {
	const W = 60000, T = 1e12;
	let r = P.dedupe({}, 's1', '+380662119922', T, W);
	assert.equal(r.dup, false);
	r = P.dedupe(r.store, 's1', '+380662119922', T + 30000, W);
	assert.equal(r.dup, true);
	assert.equal(P.dedupe(r.store, 's1', '+380502119922', T + 30000, W).dup, false, 'інший номер');
	assert.equal(P.dedupe(r.store, 's2', '+380662119922', T + 30000, W).dup, false, 'інша сесія');
	assert.equal(P.dedupe(r.store, 's1', '+380662119922', T + 61000, W).dup, false, 'після 60 с');
});

test('сесія живе 30 хв від останньої активності', () => {
	let n = 0;
	const id = () => 'id' + ++n;
	let s = P.session(null, 0, 30, id);
	assert.equal(s.id, 'id1');
	s = P.session(s, 29 * 60000, 30, id);
	assert.equal(s.id, 'id1');
	s = P.session(s, 58 * 60000, 30, id);
	assert.equal(s.id, 'id1');
	s = P.session(s, 89 * 60000, 30, id);
	assert.equal(s.id, 'id2');
	assert.match(P.uuid(), /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
});

test('джерело без cookie bp_attr: класифікація поточного заходу', () => {
	const v = (url, referrer) => P.sourceFields(C, null, { url: 'https://bpmedical.com.ua' + url, referrer, siteDomain: 'bpmedical.com.ua', now: 1e12 });
	let f = v('/departments/mamolog/?gclid=test', 'https://www.google.com/');
	assert.deepEqual([f.lt_source, f.lt_medium, f.gclid, f.paid_in_path, f.touch_count], ['google', 'cpc', 'test', 1, 1]);
	f = v('/?gbraid=g1', '');
	assert.deepEqual([f.lt_medium, f.gbraid], ['cpc', 'g1']);
	f = v('/?utm_source=Newsletter&utm_medium=Email&utm_campaign=sept&utm_term=x', '');
	assert.deepEqual([f.lt_source, f.lt_medium, f.lt_campaign, f.lt_term], ['newsletter', 'email', 'sept', 'x']);
	assert.deepEqual([v('/?fbclid=1', '').lt_source, v('/?fbclid=1', '').lt_medium], ['facebook', 'social']);
	assert.equal(v('/', 'https://www.bing.com/').lt_medium, 'organic');
	assert.equal(v('/', 'https://www.instagram.com/').lt_medium, 'social');
	assert.equal(v('/', 'https://maps.app.goo.gl/x').lt_medium, 'maps');
	assert.deepEqual([v('/', 'https://likarni.com/').lt_source, v('/', 'https://likarni.com/').lt_medium], ['likarni.com', 'referral']);
	assert.deepEqual([v('/', '').lt_source, v('/', '').lt_medium], ['(direct)', '(none)']);
	// внутрішній referrer без cookie (напр. до згоди в іншій вкладці) - direct, а не помилка
	assert.equal(v('/contacts/', 'https://bpmedical.com.ua/').lt_medium, '(none)');
});

test('джерело з cookie bp_attr має пріоритет', () => {
	const T = 1e12;
	let s = C.addTouch(null, C.classify({ url: 'https://bpmedical.com.ua/?gclid=a', referrer: '', siteDomain: 'bpmedical.com.ua', now: T }), T);
	s = C.addTouch(s, C.classify({ url: 'https://bpmedical.com.ua/', referrer: 'https://www.google.com/', siteDomain: 'bpmedical.com.ua', now: T + 864e5 }), T + 864e5);
	const f = P.sourceFields(C, C.decode(C.encode(s)), { url: 'https://bpmedical.com.ua/contacts/', referrer: 'https://bpmedical.com.ua/', siteDomain: 'bpmedical.com.ua', now: T + 864e5 });
	assert.deepEqual([f.ft_medium, f.lt_medium, f.touch_count, f.paid_in_path, f.gclid], ['cpc', 'organic', 2, 1, 'a']);
});
