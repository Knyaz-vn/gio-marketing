// @ts-check
// E2E: реальний WordPress (SQLite) + плагін + фікстура попапу Popup Maker з формою Elementor.
// Підготовка: npm run wp:setup  (див. README, розділ "Тести").
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');

const WP_DIR = process.env.WP_DIR || path.join(__dirname, '../../.wp');
const lastLead = () => JSON.parse(execFileSync('php', [path.join(__dirname, 'fixture/last-lead.php'), WP_DIR], { encoding: 'utf8' }));
const consentGranted = (page) => page.waitForFunction(() => window.bpAttr && window.bpAttr.consent(), null, { timeout: 10000 });

test('gclid -> повернення з Google без параметрів -> сабміт попапу: ft=cpc, lt=organic, paid_in_path=1', async ({ page }) => {
	const before = lastLead().count;

	// 1. Перший захід з реклами Google Ads.
	await page.goto('/?gclid=test');
	await consentGranted(page);
	const cookies = await page.context().cookies();
	expect(cookies.find((c) => c.name === 'bp_attr')).toBeTruthy();

	// 2. Пізніше - повернення з органічної видачі Google, без параметрів.
	await page.goto('/departments/stomatologiya/', { referer: 'https://www.google.com/' });
	expect(await page.evaluate(() => document.referrer)).toBe('https://www.google.com/');

	// 3. Динамічно відкритий попап #popmake-13597 отримує приховані поля (MutationObserver).
	await page.click('#open-popup');
	const form = page.locator('#popmake-13597 form');
	await expect(form.locator('input[name="bp_attr[ft_medium]"]')).toHaveValue('cpc');
	await expect(form.locator('input[name="bp_attr[lt_medium]"]')).toHaveValue('organic');

	await form.locator('input[name="form_fields[name]"]').fill('Тест Тестович');
	await form.locator('input[name="form_fields[phone]"]').fill('+380501112233');
	await form.locator('select[name="form_fields[location]"]').selectOption('lviv');
	await form.locator('button[type="submit"]').click();
	await expect(page.locator('.bp-e2e-done')).toBeVisible();

	// 4. У БД.
	const { lead, count, mail } = lastLead();
	expect(count).toBe(before + 1);
	expect(lead.form_type).toBe('elementor');
	expect(lead.form_id).toBe('popmake-13597:a1b2c3d');
	expect(lead.ft_source).toBe('google');
	expect(lead.ft_medium).toBe('cpc');
	expect(lead.ft_click_id).toBe('test');
	expect(lead.lt_source).toBe('google');
	expect(lead.lt_medium).toBe('organic');
	expect(String(lead.paid_in_path)).toBe('1');
	expect(String(lead.touch_count)).toBe('2');
	expect(lead.touch_path).toBe('google/cpc > google/organic');
	expect(lead.gclid).toBe('test');
	expect(lead.specialty).toBe('stomatologiya');
	expect(lead.location).toBe('lviv');
	expect(lead.ft_landing).toBe('/');
	expect(lead.lt_landing).toBe('/departments/stomatologiya/');
	expect(JSON.parse(lead.fields).phone).toBe('+380501112233');

	// 5. Рядок атрибуції в листі адміністратору.
	expect(mail.message).toContain('Перше джерело: google/cpc | Останнє: google/organic | Дотиків: 2');

	// 6. dataLayer: lead_submit без персональних даних.
	const events = await page.evaluate(() => window.dataLayer.filter((e) => e && e.event === 'lead_submit'));
	expect(events).toHaveLength(1);
	expect(events[0]).toMatchObject({
		form_id: 'popmake-13597:a1b2c3d', specialty: 'stomatologiya', location: 'lviv',
		ft_source: 'google', ft_medium: 'cpc', lt_source: 'google', lt_medium: 'organic',
		touch_count: 2, paid_in_path: 1, days_to_convert: 0,
	});
	const json = JSON.stringify(events[0]);
	expect(json).not.toContain('Тест');
	expect(json).not.toContain('380501112233');
});

test('Consent Mode: до згоди - лише sessionStorage, після analytics_storage=granted - cookie', async ({ page }) => {
	await page.goto('/?consent=denied&utm_source=facebook&utm_medium=paid_social&utm_campaign=implant');
	await page.waitForTimeout(2600);
	expect((await page.context().cookies()).find((c) => c.name === 'bp_attr')).toBeFalsy();
	expect(await page.evaluate(() => localStorage.getItem('bp_attr'))).toBeNull();
	expect(await page.evaluate(() => !!sessionStorage.getItem('bp_attr_pending'))).toBe(true);

	await page.evaluate(() => window.gtag('consent', 'update', { analytics_storage: 'granted' }));
	await consentGranted(page);
	expect((await page.context().cookies()).find((c) => c.name === 'bp_attr')).toBeTruthy();
	const f = await page.evaluate(() => window.bpAttr.fields());
	expect(f.ft_medium).toBe('paid_social');
	expect(f.ft_campaign).toBe('implant');
	expect(await page.evaluate(() => sessionStorage.getItem('bp_attr_pending'))).toBeNull();
});

test('клік по телефону: contact_click з ft_/lt_ полями', async ({ page }) => {
	await page.goto('/?utm_source=google&utm_medium=gbp&utm_campaign=lviv');
	await page.evaluate(() => document.getElementById('call').addEventListener('click', (e) => e.preventDefault()));
	await page.click('#call');
	const ev = await page.evaluate(() => window.dataLayer.find((e) => e && e.event === 'contact_click'));
	expect(ev).toMatchObject({ contact_type: 'phone', ft_source: 'google', ft_medium: 'gbp', ft_campaign: 'lviv', lt_medium: 'gbp', paid_in_path: 0 });
});

test('внутрішній перехід не створює дотик', async ({ page }) => {
	await page.goto('/?gclid=abc');
	await page.goto('/departments/stomatologiya/', { referer: new URL('/', page.url()).href });
	const s = await page.evaluate(() => window.bpAttr.state());
	expect(s.h).toHaveLength(1);
	expect(s.l.medium).toBe('cpc');
});

test('звіт "Атрибуція заявок": категорія, правила, CSV', async ({ page }) => {
	await page.goto('/wp-login.php');
	await page.fill('#user_login', 'admin');
	await page.fill('#user_pass', 'admin');
	await Promise.all([page.waitForURL(/wp-admin/, { waitUntil: 'commit' }), page.click('#wp-submit')]);
	await page.goto('/wp-admin/admin.php?page=bp-attribution');
	const row = page.locator('tr', { hasText: 'Платна реклама (асистована)' }).first();
	await expect(row).toBeVisible();
	expect(Number(await row.locator('td').nth(1).innerText())).toBeGreaterThanOrEqual(1);
	await expect(page.locator('.rules')).toContainText('paid_in_path = 1');
	await expect(page.locator('code', { hasText: 'google/cpc > google/organic' })).toBeVisible();

	const [download] = await Promise.all([page.waitForEvent('download'), page.click('text=Експорт CSV')]);
	const csv = require('fs').readFileSync(await download.path(), 'utf8');
	expect(csv).toContain('ft_medium');
	expect(csv).toContain('Платна реклама (асистована)');
	expect(csv).not.toContain('380501112233'); // без ПД

	await page.goto('/wp-admin/admin.php?page=bp-attribution-forms');
	await expect(page.locator('h1')).toHaveText('Форми на сайті');
	await expect(page.getByText('popmake-13597:a1b2c3d', { exact: true })).toBeVisible();
});

test('модуль запису: сервер читає cookie bp_attr і пише ті самі поля у свою таблицю', async ({ page }) => {
	await page.goto('/?utm_source=facebook&utm_medium=paid_social&utm_campaign=implant');
	await consentGranted(page);
	await page.goto('/departments/stomatologiya/', { referer: 'https://www.google.com/' });
	await consentGranted(page);
	const js = await page.evaluate(() => window.bpAttr.fields());

	const res = await page.request.post('/wp-admin/admin-ajax.php', {
		form: { action: 'bp_e2e_booking' },
		headers: { Referer: page.url() },
	});
	const { data } = await res.json();
	const b = data.booking;
	expect(b.ft_medium).toBe('paid_social');
	expect(b.lt_medium).toBe('organic');
	expect(b.touch_path).toBe('facebook/paid_social > google/organic');
	expect(String(b.paid_in_path)).toBe('1');
	expect(b.location).toBe('kyiv');
	expect(b.specialty).toBe('stomatologiya');
	// паритет PHP (cookie) і JS (buildFields)
	for (const k of ['ft_source', 'ft_medium', 'ft_campaign', 'ft_landing', 'lt_source', 'lt_medium', 'lt_landing', 'touch_path', 'touch_count']) {
		expect(String(b[k]), k).toBe(js[k]);
	}
	expect(data.line).toBe('Перше джерело: facebook/paid_social (implant) | Останнє: google/organic | Дотиків: 2');
	expect(lastLead().lead.form_type).toBe('booking');
});
