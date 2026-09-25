# BP Medical: онлайн-запис до лікарів (WordPress-плагін)

Модуль онлайн-запису для bpmedical.com.ua. Адміністратори й далі ведуть записи вручну в Google Calendar.
Плагін читає календарі, визначає зайнятість кожного лікаря **за прізвищем у назві події** і показує пацієнту лише вільні слоти.
Онлайн-заявка одразу з'являється в календарі у форматі `Прізвище | ОНЛАЙН | послуга`, а адміністратори отримують сповіщення в Telegram та на email.

- Віджет: vanilla JS без залежностей, **15.7 KB gzip** (JS + CSS, бюджет 40 KB; перевірка: `php bin/widget-size.php`).
- REST API: `/wp-json/bp-booking/v1/*`. Публічна частина віддає тільки вільні слоти й довідники, **ніколи** не віддає назви чи описи подій.
- Адмінка **«BP Запис»**: заявки, дзвінки, лікарі й графіки, діагностика календаря, налаштування. Ролі: адміністратор і реєстратор.
- Без ключів працює на mock-календарі з демо-подіями, тож усе можна перевірити одразу після активації.

Зміст: [Встановлення](#1-встановлення) · [Service account](#2-google-service-account-і-доступ-до-календарів) · [Секрети](#3-секрети-env) · [Cron](#4-cron-і-кеш) · [Графіки](#5-графіки-лікарів-послуги-свята) · [Віджет на сторінках](#6-підключення-віджета) · [GTM](#7-аналітика-gtm-kvr78mj) · [Офлайн-конверсії](#8-офлайн-конверсії-google-ads) · [Безпека й ПД](#9-безпека-і-персональні-дані) · [Тести](#10-тести-і-локальний-запуск) · [TODO](#11-що-потрібно-заповнити-todo)

Інструкція для адміністраторів (формат назв подій): [docs/ADMIN-GUIDE.md](docs/ADMIN-GUIDE.md) · Архітектура: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)

---

## 1. Встановлення

Вимоги: WordPress 5.8+, PHP 7.4+ (`openssl`, `mbstring`, `json`), MySQL 5.7+ / MariaDB 10.3+, HTTPS.

1. Скопіюйте каталог `bp-medical-booking/` у `wp-content/plugins/` (або заархівуйте в zip і завантажте через «Плагіни → Додати новий»). Каталоги `vendor/` і `tests/` на продакшн не потрібні.
2. Активуйте плагін. Під час активації:
   - створюються таблиці `wp_bpmb_bookings`, `wp_bpmb_callbacks`, `wp_bpmb_holds`, `wp_bpmb_events`;
   - з `data/*.json` заповнюються лікарі, спеціальності, послуги, локації й налаштування;
   - з'являються роль «Реєстратор BP Medical» і cron-задачі.
3. Відкрийте **BP Запис → Діагностика календаря**. У демо-режимі ви побачите mock-події, а на сторінках із шорткодом запрацює віджет.
4. Налаштуйте Google Calendar (розділи 2 і 3) і вставте віджет на сторінки (розділ 6).

**Реєстратори:** «Користувачі → Додати» з роллю «Реєстратор BP Medical». Реєстратор бачить заявки, дзвінки й діагностику, може змінювати статуси й перечитувати календар. Графіки, лікарів і налаштування редагує лише адміністратор.

## 2. Google service account і доступ до календарів

1. [Google Cloud Console](https://console.cloud.google.com/) → створіть проєкт (наприклад, `bp-medical-booking`).
2. **APIs & Services → Library → Google Calendar API → Enable**.
3. **IAM & Admin → Service Accounts → Create service account**, наприклад `bp-booking`. Ролі проєкту **не потрібні**.
4. Відкрийте акаунт → **Keys → Add key → Create new key → JSON**. Файл завантажиться один раз, тож збережіть його.
5. Покладіть ключ на сервер **поза webroot**, наприклад `/home/bpmedical/secrets/bp-booking-sa.json`, `chmod 600`. Не комітьте ключ у репозиторій і не завантажуйте в «Медіа».
6. У Google Calendar акаунта клініки для **кожного** календаря (наприклад, окремо «Коріатовичів 168А» і «Стрілецька 7Д»):
   **Налаштування календаря → Надати доступ окремим користувачам → Додати** email service account (`bp-booking@<project>.iam.gserviceaccount.com`) з правом
   **«Вносити зміни в події»**. Право «Керувати доступом» не надавайте: мінімально необхідний доступ.
7. У тих самих налаштуваннях розділ **«Інтеграція календаря» → Ідентифікатор календаря** (`xxxx@group.calendar.google.com`).
8. **BP Запис → Налаштування → Календарі**: додайте кожен календар, вкажіть назву і **локацію**. Онлайн-записи на слот цієї адреси створюються саме в цьому календарі.
   Зайнятість лікаря береться з **усіх** календарів.
9. **Діагностика календаря → «Перечитати календар»**. «Джерело» має показати «Google Calendar (service account)».

Плагін читає події через `events.list` (`singleEvents=true`, `orderBy=startTime`, вікно «сьогодні … +горизонт»), а не через `freeBusy`,
бо freeBusy не повертає назв і не дає розділити зайнятість лікарів у спільному календарі. Scope: `calendar.events`.

**Push-сповіщення.** Щогодини плагін створює або продовжує канали `events.watch` на `https://<сайт>/wp-json/bp-booking/v1/calendar/webhook` (TTL 7 днів, продовження за добу до кінця).
Коли Google надсилає сповіщення, позачергова синхронізація запускається за кілька секунд. Потрібен публічний HTTPS. Якщо канал створити не вдалося (помилку видно в діагностиці), модуль працює на polling кожні 2 хв.

## 3. Секрети (.env)

Секрети **не зберігаються в БД і в репозиторії**. Плагін шукає їх у такому порядку: константа в `wp-config.php`, змінна оточення, файл `.env`
на рівень вище каталогу WordPress (або шлях у `define('BPMB_ENV_FILE', '/path/.env')`). Приклад: [.env.example](.env.example).

| Ключ | Що це |
|---|---|
| `BPMB_GOOGLE_SA_FILE` | шлях до JSON-ключа service account (рекомендовано) |
| `BPMB_GOOGLE_SA_JSON` | альтернатива: вміст JSON або base64 в одному рядку |
| `BPMB_TELEGRAM_BOT_TOKEN` | токен бота від @BotFather (chat_id задається в адмінці) |
| `BPMB_TURNSTILE_SECRET` | секрет Cloudflare Turnstile (необов'язково) |

Telegram: створіть бота через @BotFather і додайте його в робочий чат адміністраторів. Chat_id можна дізнатися через `https://api.telegram.org/bot<TOKEN>/getUpdates`
після першого повідомлення в чаті (для груп він від'ємний). Вкажіть його в **Налаштування → Telegram chat_id** (кілька через кому).
Email-сповіщення надсилаються через `wp_mail`, тому на сайті має працювати SMTP (наприклад, плагін WP Mail SMTP).

## 4. Cron і кеш

WP-Cron спрацьовує лише під час відвідувань. Щоб синхронізація була стабільною кожні 2 хв, увімкніть системний cron:

```php
// wp-config.php
define('DISABLE_WP_CRON', true);
```
```cron
*/2 * * * * curl -fsS "https://bpmedical.com.ua/wp-cron.php?doing_wp_cron" >/dev/null 2>&1
```

Задачі: `bpmb_sync` (кожні 2 хв), `bpmb_hourly` (продовження push-каналів, очищення holds), `bpmb_daily` (анонімізація заявок старших за `retention_days`).

**Кешування сторінок.** Виключіть `/wp-json/bp-booking/` з кешу LiteSpeed / WP Rocket / Cloudflare (Cache Rules → Bypass).
Плагін сам віддає `Cache-Control: no-store` і кешує довідники у transients на 30–300 с. Цей кеш скидається після кожної синхронізації, запису й зміни налаштувань.
Сторінки з віджетом можна кешувати: віджет не використовує nonce.

## 5. Графіки лікарів, послуги, свята

**BP Запис → Лікарі та графіки → лікар:**

- **Прізвище (тригер) і aliases.** За цими словами розпізнаються події. Додайте відмінки (Машевської, Машевській…) і варіанти написання (Дерев'янко / Деревянко).
  Матчинг іде лише цілим словом, тому «Машевська» (онколог) і «Машевський» (анестезіолог) не плутаються.
- **Онлайн-запис.** Якщо вимкнено (анестезіологи), події з прізвищем лікаря все одно блокують його час, але у віджеті лікаря немає.
- **Графік:** правила «день тижня · початок · кінець · адреса». На один день можна додати кілька інтервалів, а в різні дні вказати різні адреси. Години клініки
  (Пн–Пт 09:00–18:00, Сб 09:00–15:00, Нд вихідний) обрізають графік автоматично.
- **Винятки** на конкретну дату замінюють графік. Варіант «Вихідний» означає, що запису немає.
- **Послуги й тривалості:** ✓ означає «показувати першою» (використовується для діплінку зі сторінки лікаря). Порожня тривалість бере значення послуги.

Seed-графік для швидкого старту: усі лікарі з онлайн-записом працюють Пн–Пт 09:00–18:00 на Коріатовичів 168А. **Замініть на реальні графіки.**

**Налаштування:** крок слотів (15 хв), мінімум до запису (120 хв), горизонт (30 днів), hold (7 хв), години клініки, **святкові дні**, `match_mode`, колір онлайн-записів,
режим скасування (позначити «СКАСОВАНО» + прозора подія, або видалити подію). Спеціальності, послуги й локації редагуються як JSON:

- спеціальність: `id, name, slug (як у /departments/…), parent_id, is_child` (для дитячих спеціальностей форма просить вік дитини);
- послуга: `id, name, specialty_id (null означає «для всіх»), duration_min, buffer_after_min, price_from, prep_note_url`;
- спеціальність без жодного лікаря з онлайн-записом показує у віджеті лише форму зворотного дзвінка.

**Алгоритм слотів:** робочі інтервали (графік / винятки − свята, ∩ години клініки) − події з прізвищем лікаря (all-day блокує цілий день) − holds − активні заявки →
нарізка кроком `slot_step_min` довжиною `duration + buffer`, причому слот має повністю вміститися (тож у суботу останній слот закінчується до 15:00) →
не раніше `now + min_lead_minutes`, не далі `booking_horizon_days`.

## 6. Підключення віджета

### Сторінка лікаря
Відкриває одразу календар дат цього лікаря (крок спеціальності й лікаря пропускається):
```
[bp_booking doctor="gribanova-anastasiya" entry="doctor_page"]
```
Або кнопка з модальним вікном: `[bp_booking doctor="gribanova-anastasiya" mode="button" label="Записатися онлайн"]`.
У Gutenberg доступний блок **«Онлайн-запис BP Medical»**, в Elementor віджет «Шорткод».
Якщо атрибут `doctor` не вказано, віджет сам визначає лікаря, коли URL сторінки збігається з `profile_url`.

### Сторінка спеціальності (`/departments/...`)
Пропускає крок вибору спеціальності:
```
[bp_booking specialty="mamolog" entry="specialty_page"]
```

### Будь-яка кнопка (шапка, банер, Elementor)
Додайте кнопці CSS-клас `bp-booking-open`, і вона відкриє модалку. Необов'язкові атрибути: `data-doctor`, `data-specialty`, `data-entry`
(`header` визначається автоматично всередині `<header>`, для банерів вказуйте `promo`). В Elementor: «Додатково → CSS-класи» та «Атрибути → `data-doctor|gribanova-anastasiya`».

### Заміна поточного попапу `#popmake-13597`
У налаштуваннях за замовчуванням `Перехоплювати Popup Maker ID = 13597`: усі наявні кнопки з класом `popmake-13597` або посиланням `#popmake-13597`
відкривають онлайн-запис замість старого попапу. Щоб повернути старий попап, очистіть поле.

### Діплінки
`https://bpmedical.com.ua/<сторінка з віджетом>/?doctor=gribanova-anastasiya` або `?specialty=mamolog`.
Slug лікаря редагується в адмінці. Seed-slug-и збігаються з URL сторінок лікарів на сайті.

### Кнопка «Не знайшли зручний час? Замовте дзвінок»
Є на кожному кроці й відкриває коротку форму зворотного дзвінка. Заявки на дзвінок з'являються на вкладці «Зворотні дзвінки» і надходять у Telegram / на email.

### Кольори
Перевизначаються CSS-змінними в темі:
```css
.bpb, .bpb-overlay { --bpb-accent: #0b6b83; --bpb-accent-hover: #08566a; --bpb-accent-soft: #e6f2f5; }
```
Контраст стандартної палітри відповідає WCAG AA. Віджет повністю працює з клавіатури, має aria-лейбли, `aria-live` для статусів і focus-trap у модалці.

### Не на WordPress (лендінг на іншому домені)
```html
<link rel="stylesheet" href="https://bpmedical.com.ua/wp-content/plugins/bp-medical-booking/assets/widget/booking.css">
<script>window.BPMB_CONFIG = { api: "https://bpmedical.com.ua/wp-json/bp-booking/v1", popupId: "" };</script>
<script src="https://bpmedical.com.ua/wp-content/plugins/bp-medical-booking/assets/widget/booking.js" defer></script>
<div class="bp-booking" data-specialty="mamolog" data-entry="promo"></div>
```
Домен лендінгу потрібно додати в **Налаштування → CORS**. За замовчуванням дозволені лише `bpmedical.com.ua`, `www.` і `staging.`.

## 7. Аналітика (GTM-KVR78MJ)

Віджет пише в `dataLayer` **без персональних даних** (ні імені, ні телефону):

| event | параметри |
|---|---|
| `booking_open` | `entry`: doctor_page / specialty_page / header / promo |
| `booking_specialty_select` | `specialty` |
| `booking_doctor_select` | `specialty`, `doctor` |
| `booking_slot_select` | `specialty`, `doctor`, `location`, `days_ahead` |
| `booking_submit` | `specialty`, `doctor`, `location`, `service`, `lead_id`: **основна конверсія** |
| `booking_no_slots` | `specialty`, `doctor` |
| `booking_callback_submit` | `specialty`, `doctor` (додатково: заявка на дзвінок) |

Для inline-віджета `booking_open` спрацьовує один раз, коли віджет з'являється в зоні видимості. Для модалки подія спрацьовує під час відкриття.

**Налаштування в GTM:**
1. **Змінні → Нова → Змінна рівня даних** для кожного параметра: `specialty`, `doctor`, `location`, `service`, `lead_id`, `days_ahead`, `entry` (назви DLV: `DLV - doctor` тощо).
2. **Тригери → Нова → Спеціальна подія**: `booking_submit`, а також окремі тригери або один regex `^booking_.*` для решти кроків воронки.
3. **GA4:** тег «Подія GA4» з назвою `{{Event}}` на тригер `^booking_.*` і параметрами з DLV. У GA4 позначте `booking_submit` як ключову подію.
4. **Google Ads:** тег «Відстеження конверсій Google Ads» на тригер `booking_submit`, **Transaction ID = `{{DLV - lead_id}}`** (дедуплікація).
   Має бути налаштований «Conversion Linker», тоді cookie `_gcl_aw` підхоплюється і плагіном.
5. **Meta Pixel:** `fbq('track', 'Lead', {}, {eventID: {{DLV - lead_id}}})` на `booking_submit`.
6. Перевірте в режимі Preview: пройдіть запис і переконайтеся, що в `booking_submit` немає імені й телефону.

**Атрибуція заявки.** Віджет зберігає в заявці `gclid, gbraid, wbraid, fbclid, utm_source/medium/campaign/term/content, landing_page, referrer`.
Значення з URL записуються у first-party cookie `bpmb_attr` на 90 днів (останній рекламний клік). Якщо в URL нічого немає, віджет читає вже наявні cookie сайту:
`_gcl_aw`, `_gcl_gb` (Google Ads Conversion Linker), `_fbc` (Meta), `sbjs_current` (Sourcebuster / WooCommerce Order Attribution).
Щоб UTM ловилися на будь-якій сторінці входу, залиште увімкненим «Підключати на всіх сторінках».

## 8. Офлайн-конверсії Google Ads

1. Google Ads → **Цілі → Конверсії → Нова → Імпорт → Кліки з інших джерел**, назва, наприклад, `Online booking visit` (має збігатися з «Назва конверсії Google Ads» у налаштуваннях плагіна).
2. Після візитів реєстратор ставить статус **«Відвідав»**.
3. **BP Запис → Заявки → «CSV для Google Ads»**: вивантажуються заявки зі статусом `visited` (або з обраним у фільтрі) і наявним GCLID / GBRAID / WBRAID
   чи хешем телефону. Колонки: `Google Click ID, GBRAID, WBRAID, Phone Number (SHA-256 від E.164), Conversion Name, Conversion Time, Conversion Value, Conversion Currency, Order ID`,
   перший рядок `Parameters:TimeZone=Europe/Kyiv`.
4. Google Ads → Конверсії → **Завантаження** → виберіть файл. Кліки старші за 90 днів Google не приймає, тому вивантажуйте щотижня.

Хеш телефону (`SHA-256` від `+380XXXXXXXXX`) зберігається для **Enhanced Conversions for Leads** (налаштування «Зберігати SHA-256 телефону»).
Шаблон CSV у Google Ads періодично змінюється: перед першим імпортом звірте заголовки з актуальним шаблоном у Google Ads.
Кнопка **CSV** вивантажує всі поля заявок за поточним фільтром.

## 9. Безпека і персональні дані

- Ключ service account, токен бота й секрет Turnstile зберігаються лише в `.env` або `wp-config.php`. Доступ до календаря обмежено scope `calendar.events` і правом «Вносити зміни в події».
- Публічний API віддає тільки вільні слоти. Назви й описи подій бачать лише адміністратор і реєстратор (вкладка «Діагностика»).
- В опис події в календарі потрапляють ім'я, телефон, «Не підтверджено: зателефонувати пацієнту» та ID заявки. **Коментар пацієнта (скарги) в календар не пишеться.** Форма просить не вказувати діагнози.
- Логи маскують телефони (`+38067***45`). IP для rate limit зберігається лише як хеш у transients.
- Антиспам: honeypot, мінімальний час заповнення (3 с), rate limit 5 заявок/год з IP (окремо для записів і дзвінків), 30 holds/год, опційно Cloudflare Turnstile.
  Якщо сайт за Cloudflare, додайте `define('BPMB_TRUST_CLOUDFLARE_IP', true);`, щоб ліміт рахувався за реальним IP.
- Заявки зберігаються `retention_days` (365) днів, далі ім'я, телефон, хеш, коментар і вік дитини автоматично анонімізуються (щоденний cron).
- CORS для `/wp-json/bp-booking/*` дозволено лише доменам зі списку (bpmedical.com.ua, www, staging).
- Захист від подвійного запису: hold на 7 хв, під час сабміту MySQL `GET_LOCK` на лікаря, свіжий `events.list` на добу слота і повторна перевірка.
  Якщо Google Calendar недоступний у момент сабміту, заявка приймається за кешем (не старшим за 2 хв) з позначкою ⚠ для адміністратора: статус і так лишається «Очікує підтвердження».
- Mock-календар зберігається в `uploads/bpmb-private/` з випадковою назвою файлу, доступ з вебу закрито через `.htaccess`.
- Видалення плагіна прибирає роль і cron, але **не** видаляє заявки, поки в `wp-config.php` немає `define('BPMB_DELETE_DATA_ON_UNINSTALL', true);`.

## 10. Тести і локальний запуск

```bash
cd bp-medical-booking
composer install
vendor/bin/phpunit            # 54 тести
php bin/widget-size.php       # бюджет розміру віджета
```

- `tests/Unit/SurnameMatcherTest.php`: Машевська vs Машевський, підрядок не матчиться, апострофи в Дерев'янко (’ ʼ ` без апострофа), латинська `i` у кирилиці,
  прізвище пацієнта = прізвище лікаря в режимі `prefix`, два прізвища в одній події, all-day, transparent / cancelled, діагностика.
- `tests/Unit/SlotGeneratorTest.php`: субота до 15:00 (з буфером і без), `min_lead`, перетин подій, buffer, override-вихідний, override з іншою адресою, кілька інтервалів, свята, горизонт.
- `tests/Integration/DoubleBookingTest.php`: **два окремі PHP-процеси** одночасно сабмітять один слот (спільні SQLite і mock-календар). Успішний лише один,
  другий отримує `slot_unavailable` і 3 альтернативи. У календарі рівно одна подія.
- `tests/Integration/BookingFlowTest.php`: hold, формат події в календарі без скарг, свіжа перевірка календаря, скасування / підтвердження, дитяча спеціальність.

Ядро (`src/Core`, `src/Booking`, `src/Calendar`, `src/Storage`) не залежить від WordPress. Тести проганяються на SQLite з mock-календарем (`MockCalendarClient`), ключі не потрібні.
На живому сайті без ключа плагін автоматично переходить у mock-режим і генерує демо-події на 2 тижні. Щоб перегенерувати демо, видаліть файл `uploads/bpmb-private/mock-calendar-*.json`.

## 11. Що потрібно заповнити (TODO)

- [ ] **Звірити список лікарів** з https://bpmedical.com.ua/doctors-2/ (у середовищі розробки сайт був недоступний, тож seed складено за ТЗ).
- [ ] Баженова Ірина Олексіївна: `profile_url` і slug (зараз `bazhenova-iryna`, URL порожній).
- [ ] Біктіміров: у ТЗ по батькові «Вікторович», а в URL профілю `volodymyrovych`. Уточніть.
- [ ] `photo_url` лікарів (без фото віджет показує ініціали).
- [ ] Реальні графіки, винятки й тривалості послуг.
- [ ] ID календарів Google для кожної адреси (зараз `mock-koriat`, `mock-strilets`).
- [ ] Звірити slug-и спеціальностей і `parent_id` з `/departments/` (спеціальності без лікарів показують форму дзвінка).
- [ ] Ціни `price_from` і посилання на пам'ятки підготовки `prep_note_url`.
- [ ] Telegram chat_id, email адміністраторів, святкові дні.
- [ ] Перевести адміністраторів на формат назв подій з [docs/ADMIN-GUIDE.md](docs/ADMIN-GUIDE.md) і після цього увімкнути `match_mode = prefix`.

## Структура

```
bp-medical-booking/
├── bp-medical-booking.php      # заголовок плагіна, автолоадер, хуки активації
├── uninstall.php
├── data/                       # seed: doctors, specialties, services, locations, settings
├── src/
│   ├── Core/                   # чиста логіка: Normalizer, SurnameMatcher, EventClassifier, ScheduleResolver,
│   │                           #   SlotGenerator, AvailabilityService, Phone, Ics, Attribution, Config, Catalog
│   ├── Booking/                # BookingService (hold → submit → календар), DbBusyProvider, Logger
│   ├── Calendar/               # GoogleCalendarClient (JWT service account), MockCalendarClient, CalendarSync, WatchManager
│   ├── Storage/                # Connection (wpdb / PDO), Schema, репозиторії
│   ├── Rest/                   # PublicController, AdminController
│   ├── Admin/                  # AdminPage, Roles, Export (CSV)
│   ├── Frontend/               # шорткод, блок, глобальне підключення
│   ├── Notify/                 # Telegram, Email
│   ├── Security/               # Secrets (.env), RateLimiter, Turnstile, Cors
│   ├── Cron/Jobs.php
│   ├── Plugin.php, Options.php, Installer.php
├── assets/widget/              # booking.js, booking.css, block.js
├── assets/admin/               # admin.js, admin.css
├── tests/                      # Unit + Integration (PHPUnit)
└── docs/                       # ADMIN-GUIDE.md, ARCHITECTURE.md
```
