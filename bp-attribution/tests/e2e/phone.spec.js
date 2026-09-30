// @ts-check
// E2E модуля "Кліки по телефону": реальний WordPress (SQLite) + плагін + фікстура хедера/кнопок/текстових номерів.
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect, devices } = require('@playwright/test');

const WP_DIR = process.env.WP_DIR || path.join(__dirname, '../../.wp');
const SECRET = 'e2e-secret-0123456789abcdef';
const db = (arg) => {
	const out = execFileSync('php', [path.join(__dirname, 'fixture/phone-db.php'), WP_DIR].concat(arg ? [arg] : []), { encoding: 'utf8' });
	return out ? JSON.parse(out) : null;
};
const phoneEvents = (page) => page.evaluate(() => (window.dataLayer || []).filter((e) => e && e.event === 'phone_click'));
const waitClick = async (pred) => {
	for (let i = 0; i < 50; i++) {
		const row = db().clicks.find(pred);
		if (row) return row;
		await new Promise((r) => setTimeout(r, 100));
	}
	throw new Error('клік не записано в wp_bp_phone_clicks');
};

// Headless Chromium після першого переходу за tel: (системний обробник протоколу) ігнорує наступні кліки.
// У тестах з кількома кліками гасимо сам перехід у bubble-фазі на window - ПІСЛЯ слухача плагіна.
const noTelNav = (page) => page.addInitScript(() => {
	window.addEventListener('click', (e) => { if (e.target.closest && e.target.closest('a[href^="tel:"]')) e.preventDefault(); });
});

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => db('reset'));

test('?gclid=test на /departments/mamolog/ -> клік по 066 у хедері -> dataLayer і wp_bp_phone_clicks', async ({ page }) => {
	await page.goto('/departments/mamolog/?gclid=test');
	const beacon = page.waitForRequest((r) => r.url().includes('/wp-json/bp/v1/phone-click') && r.method() === 'POST');
	await page.click('#hdr-066');
	const req = await beacon;

	const [ev] = await phoneEvents(page);
	expect(ev).toMatchObject({
		action: 'click', phone_e164: '+380662119922', phone_label: '066 Коріатовичів', location: 'koriat',
		device: 'desktop', page_path: '/departments/mamolog/', page_type: 'department', specialty: 'mamolog',
		element: 'header', ft_source: 'google', ft_medium: 'cpc', lt_source: 'google', lt_medium: 'cpc',
		touch_count: 1, paid_in_path: 1, gclid: 'test',
	});
	expect(ev.event_id).toMatch(/^[0-9a-f-]{36}$/);
	expect(ev.session_id).toMatch(/^[0-9a-f-]{36}$/);
	expect(req.url()).toContain('/wp-json/bp/v1/phone-click');
	// у події - лише поля схеми, жодних даних відвідувача
	expect(Object.keys(ev).sort()).toEqual(['event', 'event_id', 'ts', 'action', 'phone_e164', 'phone_label', 'location', 'device',
		'page_path', 'page_type', 'specialty', 'doctor_slug', 'element', 'ft_source', 'ft_medium', 'ft_campaign', 'lt_source',
		'lt_medium', 'lt_campaign', 'lt_term', 'touch_count', 'paid_in_path', 'gclid', 'gbraid', 'wbraid', 'session_id'].sort());

	const row = await waitClick((r) => r.event_id === ev.event_id);
	expect(row).toMatchObject({ location: 'koriat', specialty: 'mamolog', lt_medium: 'cpc', element: 'header', page_type: 'department', gclid: 'test', action: 'click' });
	expect(String(row.paid_in_path)).toBe('1');
	expect(row.ip_hash).toMatch(/^[0-9a-f]{32}$/);
	expect(JSON.stringify(row)).not.toContain('127.0.0.1');
});

test('мобільний: tap по tel: не блокується і не затримується', async ({ browser }) => {
	const ctx = await browser.newContext({ ...devices['Pixel 7'] });
	const page = await ctx.newPage();
	await page.addInitScript(() => {
		window.__t = [];
		// window capture - до нашого слухача (document capture), window bubble - після всіх
		window.addEventListener('click', () => window.__t.push(['start', performance.now()]), true);
		window.addEventListener('click', (e) => window.__t.push(['end', performance.now(), e.defaultPrevented]), false);
	});
	await page.goto('/departments/mamolog/');
	const nav = page.waitForRequest((r) => r.url().includes('/phone-click'));
	await page.locator('#hdr-066').tap();
	await nav;
	const t = await page.evaluate(() => window.__t);
	const start = t.find((x) => x[0] === 'start'), end = t.find((x) => x[0] === 'end');
	expect(end[2]).toBe(false); // preventDefault не викликано - перехід за tel: відбувається
	expect(end[1] - start[1]).toBeLessThan(50); // синхронна обробка, без затримки переходу
	const [ev] = await phoneEvents(page);
	expect(ev).toMatchObject({ action: 'tap', device: 'mobile', element: 'header', location: 'koriat' });
	await ctx.close();
});

test('дедуплікація 60 с, закріплена кнопка, невідомий номер', async ({ page }) => {
	await noTelNav(page);
	await page.goto('/contacts-test/?utm_source=facebook&utm_medium=paid_social');
	await page.goto('/departments/mamolog/');
	await page.click('#hdr-050');
	await page.click('#sticky-050'); // той самий номер < 60 с - не рахується
	await page.click('#hdr-066');
	await page.click('#unknown-tel');
	const evs = await phoneEvents(page);
	expect(evs.map((e) => [e.phone_e164, e.location])).toEqual([
		['+380502119922', 'strilets'],
		['+380662119922', 'koriat'],
		['+380931112233', 'unknown'],
	]);
	expect(evs[0].element).toBe('header');
	expect(evs[0]).toMatchObject({ lt_source: 'facebook', lt_medium: 'paid_social', paid_in_path: 1 });
	await waitClick((r) => r.phone_e164 === '+380931112233');
	expect(db().unknown['+380931112233'].count).toBe(1);

	// нова сесія (інший контекст) - закріплена кнопка рахується
	const ctx = await page.context().browser().newContext();
	const p2 = await ctx.newPage();
	await p2.goto('/departments/mamolog/');
	await p2.click('#sticky-050');
	expect((await phoneEvents(p2))[0]).toMatchObject({ element: 'sticky', lt_medium: '(none)', lt_source: '(direct)' });
	await ctx.close();
});

test('текстові номери обгорнуто в tel:, атрибути й існуючі посилання не зламано; копіювання', async ({ page }) => {
	await noTelNav(page);
	await page.goto('/departments/mamolog/');
	const content = page.locator('.wp-block-post-content, .entry-content').first();
	await expect(content.locator('a.bp-phone-link[href="tel:+380800337617"]')).toHaveText('0 (800) 337 617');
	await expect(content.locator('a.bp-phone-link[href="tel:+380662119922"]')).toHaveText('(066) 211 9922');
	await expect(content.locator('a.existing-link')).toHaveAttribute('href', 'tel:0502119922');
	expect(await content.locator('a.existing-link a').count()).toBe(0); // без вкладених посилань
	await expect(content.locator('img')).toHaveAttribute('alt', '050-211-99-22');
	expect(await content.locator('a[href="tel:+380931112233"]').count()).toBe(0); // невідомий номер у тексті не обгортаємо

	// копіювання номера (виділення тексту -> copy)
	await page.evaluate(() => {
		const a = document.querySelector('a.bp-phone-link[href="tel:+380800337617"]');
		const r = document.createRange();
		r.selectNodeContents(a.parentElement);
		getSelection().removeAllRanges();
		getSelection().addRange(r);
		document.dispatchEvent(new Event('copy', { bubbles: true }));
	});
	const [ev] = await phoneEvents(page);
	expect(ev).toMatchObject({ action: 'copy', phone_e164: '+380800337617', location: 'hotline', element: 'content' });
	// клік по обгорнутому номеру трекається
	await page.click('a.bp-phone-link[href="tel:+380662119922"]');
	expect((await phoneEvents(page))[1]).toMatchObject({ action: 'click', location: 'koriat', element: 'content' });
});

test('Binotel: секрет, зіставлення high/low, номер абонента не зберігається', async ({ page, browser, request }) => {
	db('reset');
	const bin = (body, secret = SECRET) => request.post('/wp-json/bp/v1/binotel-calls', { data: body, headers: secret ? { 'X-BP-Secret': secret } : {} });
	expect((await bin({ call_id: 'x', dialed_number: '0662119922', started_at: new Date().toISOString() }, 'wrong')).status()).toBe(403);
	expect((await bin({ call_id: 'x' }, '')).status()).toBe(403);

	// один клік на гарячу лінію -> дзвінок через кілька секунд -> high
	await page.goto('/departments/mamolog/?utm_source=google&utm_medium=gbp&utm_campaign=koriat');
	await page.click('a.bp-phone-link[href="tel:+380800337617"]');
	await waitClick((r) => r.phone_e164 === '+380800337617');

	// два кліки на 066 з різних сесій -> low, береться найближчий за часом
	const ids = [];
	for (let i = 0; i < 2; i++) {
		const ctx = await browser.newContext();
		const p = await ctx.newPage();
		await p.goto('/departments/mamolog/');
		await p.click('#hdr-066');
		ids.push((await phoneEvents(p))[0].event_id);
		await waitClick((r) => r.event_id === ids[i]);
		await ctx.close();
		await new Promise((r) => setTimeout(r, 1100));
	}
	const now = new Date(Date.now() + 2000).toISOString();
	const res = await bin({ calls: [
		{ call_id: 'c-hot', dialed_number: '0 (800) 337 617', started_at: now, duration_sec: 95, answered: true, external_number: '+380501234567' },
		{ call_id: 'c-066', dialed_number: '+380662119922', started_at: now, duration_sec: 0, answered: false, caller: '0671112233' },
		{ call_id: 'c-none', dialed_number: '0502119922', started_at: now, duration_sec: 40, answered: true },
		{ call_id: '', dialed_number: 'bad' },
	] });
	expect(res.status()).toBe(200);
	expect(await res.json()).toMatchObject({ received: 3, matched: 2 });

	const { clicks, calls, call_columns } = db();
	const hot = clicks.find((c) => c.phone_e164 === '+380800337617');
	expect(hot).toMatchObject({ call_id: 'c-hot', match_confidence: 'high', lt_medium: 'gbp' });
	expect(String(hot.call_answered)).toBe('1');
	expect(String(hot.call_duration)).toBe('95');
	const nearest = clicks.find((c) => c.event_id === ids[1]);
	expect(nearest).toMatchObject({ call_id: 'c-066', match_confidence: 'low' });
	expect(clicks.find((c) => c.event_id === ids[0]).call_id).toBeNull();
	expect(calls.find((c) => c.call_id === 'c-none').click_id).toBeNull();
	// номер абонента не зберігається
	expect(call_columns.sort()).toEqual(['answered', 'call_id', 'click_id', 'dialed_e164', 'duration_sec', 'id', 'location', 'match_confidence', 'received_at', 'started_at'].sort());
	expect(JSON.stringify(calls)).not.toMatch(/0501234567|0671112233/);
});

test('звіт "Кліки по телефону", CSV і сканер "Номери на сайті"', async ({ page }) => {
	await page.goto('/wp-login.php');
	await page.fill('#user_login', 'admin');
	await page.fill('#user_pass', 'admin');
	await Promise.all([page.waitForURL(/wp-admin/, { waitUntil: 'commit' }), page.click('#wp-submit')]);

	await page.goto('/wp-admin/admin.php?page=bp-phone-clicks');
	await expect(page.locator('h1')).toHaveText('Кліки по телефону');
	await expect(page.locator('.kpi', { hasText: 'Кліків по номерах' }).locator('b')).toHaveText('3');
	await expect(page.locator('.kpi', { hasText: 'Перейшли в дзвінок' }).locator('b')).toContainText('66');
	await expect(page.locator('table', { hasText: 'google/gbp' }).first()).toBeVisible();
	await expect(page.locator('svg.bp-days')).toBeVisible();
	expect(await page.locator('.bp-heat td.off').count()).toBe(7 * 24 - (5 * 10 + 7)); // Пн-Пт 8-18, Сб 8-15

	const [dl] = await Promise.all([page.waitForEvent('download'), page.click('text=Експорт CSV')]);
	const csv = require('fs').readFileSync(await dl.path(), 'utf8');
	expect(csv).toContain('phone_label');
	expect(csv).toContain('066 Коріатовичів');
	expect(csv).not.toContain('ip_hash');

	await page.goto('/wp-admin/admin.php?page=bp-phone-scan');
	await page.click('#bp-scan');
	await expect(page.locator('#bp-scan-status')).toContainText('Готово', { timeout: 60000 });
	const rows = page.locator('#bp-scan-t tbody tr');
	await expect(rows.filter({ hasText: '/departments/mamolog/' }).filter({ hasText: 'header' }).filter({ hasText: 'tel:-посилання' }).first()).toBeVisible();
	await expect(rows.filter({ hasText: '/departments/mamolog/' }).filter({ hasText: 'обгорнуто плагіном' }).filter({ hasText: '+380800337617' })).toHaveCount(1);
	await expect(rows.filter({ hasText: '/departments/mamolog/' }).filter({ hasText: '+380931112233' }).filter({ has: page.locator('td', { hasText: /^текст$/ }) })).toHaveCount(1);
});

test('REST: валідація схеми і rate limit 30/хв на IP', async ({ request }) => {
	const ok = {
		event_id: '00000000-0000-4000-8000-000000000000', ts: new Date().toISOString(), action: 'click',
		phone_e164: '+380662119922', device: 'desktop', page_path: '/', page_type: 'home', element: 'header',
		session_id: '11111111-1111-4111-8111-111111111111',
	};
	const post = (data) => request.post('/wp-json/bp/v1/phone-click', { data });
	expect((await post({ ...ok, action: 'hack' })).status()).toBe(400);
	expect((await post({ ...ok, page_path: 'https://evil' })).status()).toBe(400);
	expect((await post({ ...ok, element: undefined })).status()).toBe(400);
	let last;
	for (let i = 0; i < 32; i++) {
		last = await post({ ...ok, event_id: '00000000-0000-4000-8000-' + String(i).padStart(12, '0') });
		if (last.status() === 429) break;
	}
	expect(last.status()).toBe(429);
});
