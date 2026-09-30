# BP Attribution: чесна мультиканальна атрибуція заявок

WordPress-плагін для bpmedical.com.ua. Для кожної заявки зберігає:

- **перше джерело** (first touch): звідки людина прийшла вперше;
- **останнє непряме джерело** (last non-direct touch): останній захід із зовнішнього джерела;
- **шлях дотиків**: до 10 останніх заходів за 90 днів (перший завжди зберігається).

Мета: побачити реальний внесок платної реклами, яку last-click записує як organic або direct
(людина клікнула рекламу, а через тиждень знайшла клініку в Google і залишила заявку).

**Принцип: жодного штучного перепризначення.** Джерело визначається лише за фактичними параметрами URL
(gclid, utm_*, fbclid…) і `document.referrer`. Якщо реклама не була в шляху, звіт це й покаже.

---

## Зміст

1. [Аналіз сайту і покриття форм](#1-аналіз-сайту-і-покриття-форм)
2. [Встановлення](#2-встановлення)
3. [Як збираються дотики](#3-як-збираються-дотики)
4. [Поля заявки](#4-поля-заявки)
5. [Форми: Elementor, Popup Maker, "Нам прикро", акції, онлайн-запис](#5-форми)
6. [Поле "Звідки ви дізналися про нас?"](#6-поле-звідки-ви-дізналися-про-нас)
7. [dataLayer, GTM і GA4](#7-datalayer-gtm-і-ga4)
8. [Правила UTM для Meta і Google Business Profile](#8-правила-utm-для-meta-і-google-business-profile)
9. [Телефон, месенджери, Binotel](#9-телефон-месенджери-binotel)
10. [Звіт "Атрибуція заявок"](#10-звіт-атрибуція-заявок)
11. [Тести і збірка](#11-тести-і-збірка)
12. [Обмеження](#12-обмеження)

---

## 1. Аналіз сайту і покриття форм

Під час розробки сайт bpmedical.com.ua був недоступний із середовища розробки (мережеві обмеження),
тому тему, плагіни і форми не вдалося перевірити на живому сайті. Замість цього:

- плагін **не залежить від конкретних форм**: JS додає приховані поля в **кожну** `<form>` на сторінці,
  включно з попапами, що з'являються пізніше (MutationObserver). Виняток: пошук, коментарі, вхід, кошик;
- після встановлення відкрийте **Атрибуція заявок → Форми на сайті**. Сторінка сама знаходить у БД
  усі форми Elementor (на сторінках, у шаблонах і Elementor Popups), попапи Popup Maker і їхній вміст,
  форми Contact Form 7 / WPForms, показує тему і список активних плагінів. Там само видно,
  з яких `form_id` уже надходили заявки.

Очікуване покриття форм з ТЗ:

| Форма | Як обробляється | Що перевірити після встановлення |
|---|---|---|
| Elementor Forms (усі сторінки) | PHP-хук `elementor_pro/forms/process` → `wp_bp_leads` + рядок у листі | Нічого. Опційно: Hidden-поля з ID `ft_source` тощо, якщо дані потрібні у вебхуку/CRM |
| Popup Maker `#popmake-13597` | приховані поля додаються при відкритті; `form_id` = `popmake-13597:<id форми>` | Яка форма всередині попапу (Elementor / CF7 / HTML) - видно на сторінці "Форми на сайті" |
| Форма "Нам прикро" | як і будь-яка інша форма (Elementor або fallback) | Якщо це відгук, а не заявка: додати її `form_id` у **Налаштування → Форми, що не є заявками** |
| Форми акцій | Elementor / CF7 / WPForms / fallback | - |
| Модуль онлайн-запису | PHP-хелпер `bp_attr_record_booking()` (див. розділ 5) | Викликати хелпер в обробнику запису |
| Інші HTML-форми теми | fallback на `shutdown`: будь-який POST з `bp_attr[...]`, де є телефон або email | - |

---

## 2. Встановлення

1. Скопіюйте папку `bp-attribution` у `wp-content/plugins/` (або заархівуйте в zip і завантажте через
   **Плагіни → Додати новий → Завантажити**). Правки теми не потрібні.
2. Активуйте плагін. Створиться таблиця `wp_bp_leads`.
3. **Атрибуція заявок → Налаштування**:
   - *Згода на cookies*: `Авто` (за замовчуванням). Якщо на сайті є банер cookies або Google Consent Mode,
     cookie `bp_attr` пишеться лише після `analytics_storage=granted`. До згоди дотики лежать у `sessionStorage`
     і переносяться в cookie після згоди. Підтримуються Consent Mode (`gtag('consent', ...)`), CookieYes,
     Complianz, Cookiebot, Cookie Notice. Якщо банера немає, cookie пишеться через ~2 с після завантаження сторінки.
   - *Домен cookie*: `.bpmedical.com.ua`, якщо онлайн-запис працює на піддомені.
   - *Форми, що не є заявками*: `form_id` форми "Нам прикро", підписок тощо.
4. Якщо використовується кешування (WP Rocket, LiteSpeed тощо): нічого робити не треба, вся логіка на клієнті.
   Мініфікацію/об'єднання JS можна залишити.
5. Перевірка: відкрийте сайт з `?gclid=test`, потім зайдіть ще раз, залиште тестову заявку і подивіться звіт
   (або `window.bpAttr.fields()` у консолі браузера).

Вимоги: WordPress 6.0+, PHP 7.4+. JS: 5.2 KB gzip, без залежностей.

---

## 3. Як збираються дотики

**Дотик** фіксується, коли людина приходить на сайт ззовні:

- в URL є `gclid`, `gbraid`, `wbraid`, `fbclid`, `msclkid` або будь-який `utm_*`;
- або `document.referrer` з іншого домену (не bpmedical.com.ua і не його піддомени).

Внутрішні переходи не є дотиком. Повторний захід із тим самим джерелом (source + medium + campaign)
протягом 30 хв не створює новий дотик.

### Класифікація (`assets/src/classify.js`), порядок пріоритету

| # | Умова | source / medium |
|---|---|---|
| 1 | `gclid` / `gbraid` / `wbraid` | `google / cpc` (campaign, term, content - з utm, якщо є) |
| 1a | `msclkid` без `utm_source` | `bing / cpc` (Microsoft Ads додає msclkid лише до платних кліків) |
| 2 | є `utm_source` | `utm_source / utm_medium` як є (lower-case, trim). Нормалізація medium: `cpc, ppc, paid, paidsearch → cpc`; `paid_social, paidsocial, cpm → paid_social` |
| 3 | `fbclid` **без** utm | `facebook / social` (або `instagram / social`, якщо referrer - instagram.com). **Не платний**: Meta додає fbclid і до органічних переходів |
| 4 | referrer google.\*, bing.\*, duckduckgo, yahoo | `<пошуковик> / organic` |
| 5 | referrer facebook, instagram, t.co / x.com, linkedin, tiktok | `<мережа> / social` |
| 6 | referrer google.com/maps, maps.google.\*, maps.app.goo.gl | `google / maps` (перевіряється раніше за п.4, бо google.com/maps теж google.\*) |
| 7 | інший зовнішній referrer | `<домен> / referral` |
| 8 | ні параметрів, ні referrer | `(direct) / (none)`: у шляху показується як `direct` |

**Direct не перезаписує last touch.** Прямий захід потрапляє в шлях (`google/cpc > direct`), але
`lt_*` лишається останнім непрямим джерелом. Прямий захід посеред активної сесії (< 30 хв,
наприклад відкриття в новій вкладці) дотиком не вважається.

Домени платіжних шлюзів (liqpay, wayforpay, privatbank, monobank) виключені: повернення після оплати не є дотиком.
Список редагується в налаштуваннях.

### Зберігання

- Cookie `bp_attr`: JSON → base64url, `SameSite=Lax; Secure; path=/`, строк дії 90 днів від **останнього** дотику.
- Дублювання в `localStorage` (резерв, якщо cookie видалили).
- Історія: максимум 10 дотиків. Перший дотик не витісняється ніколи; з середини видаляються найстаріші.
  Загальна кількість дотиків (`touch_count`) і факт платного дотику (`paid_in_path`) зберігаються окремо,
  тож не губляться при витісненні.
- Структура дотику: `{ source, medium, campaign, term, content, click_id_type, click_id, landing_path, ts }`
  (у cookie - з короткими ключами, щоб вкластися в 4 KB).

---

## 4. Поля заявки

JS додає в кожну форму приховані поля `bp_attr[<назва>]` (простір імен, щоб не конфліктувати з полями форм):

| Поле | Опис |
|---|---|
| `ft_source`, `ft_medium`, `ft_campaign`, `ft_term`, `ft_content`, `ft_landing`, `ft_ts`, `ft_click_id` | перший дотик |
| `lt_source`, `lt_medium`, `lt_campaign`, `lt_term`, `lt_content`, `lt_landing`, `lt_ts`, `lt_click_id` | останній непрямий дотик |
| `touch_path` | `google/cpc > google/organic > direct` (`…` - якщо частину середини витіснено) |
| `touch_count` | кількість дотиків |
| `days_to_convert` | днів від першого дотику до заявки |
| `paid_in_path` | `1`, якщо хоча б один дотик мав medium `cpc`, `paid_social`, `display` або `video` |
| `gclid`, `gbraid`, `wbraid`, `fbclid` | останні збережені значення |
| `page_url` | сторінка заявки |
| `specialty` | з URL `/departments/{slug}/` |
| `location` | значення поля форми, ID/ім'я якого містить `location`, `filial`, `branch`, `clinic`, `локац`, `філі` |
| `form_id` | ID форми; для попапів - `popmake-13597:<id>` |
| `self_reported` | відповідь "Звідки ви дізналися про нас?" |

Якщо у формі Elementor є Hidden-поле з таким самим ID (`ft_source`, `touch_path`…), воно теж заповнюється,
тож дані підуть у вебхуки / CRM, налаштовані в Elementor.

Сервер бере поля з форми; якщо JS не спрацював, читає cookie `bp_attr` (та сама логіка в PHP).

**Лист адміністратору** отримує один рядок:

```
Перше джерело: google/cpc (кампанія) | Останнє: google/organic | Дотиків: 3
```

Листи-підтвердження пацієнту (на email, введений у формі) цей рядок не отримують.

---

## 5. Форми

- **Elementor Pro Forms** (зокрема всередині Elementor Popup і Popup Maker): хук `elementor_pro/forms/process`.
  Поля форми зберігаються в `wp_bp_leads.fields` (JSON), атрибуція - в окремих колонках.
- **Contact Form 7**: `wpcf7_before_send_mail`. **WPForms**: `wpforms_process`.
- **Будь-які інші форми** (HTML-форми теми, admin-ajax, admin-post): fallback на `shutdown`, якщо в POST є `bp_attr[...]`
  і контакт (телефон або email).
- **Popup Maker**: попапи з'являються в DOM динамічно; MutationObserver додає поля щойно форма з'являється.

### Модуль онлайн-запису

Модуль читає ту саму cookie `bp_attr` і пише ті самі поля. В обробнику запису (PHP):

```php
// після збереження запису у власну таблицю модуля
$lead_id = bp_attr_record_booking(
	array( 'patient' => $name, 'phone' => $phone, 'doctor' => $doctor, 'date' => $date ), // -> wp_bp_leads.fields
	array(
		'form_id'       => 'booking',
		'location'      => $location_slug,      // обрана локація
		'specialty'     => $specialty_slug,     // якщо запис не зі сторінки /departments/{slug}/
		'self_reported' => $_POST['self_reported'] ?? '',
		'table'         => $wpdb->prefix . 'bp_bookings', // опційно: таблиця модуля
		'row_id'        => $booking_id,                    // опційно: ID рядка
	)
);
$admin_mail_body .= "\n\n" . bp_attr_email_line( bp_attr_booking_fields() );
```

- Заявка завжди потрапляє в `wp_bp_leads` (для звіту). Якщо передано `table` + `row_id`, ті самі колонки
  (`ft_*`, `lt_*`, `touch_path`, …) додаються в таблицю модуля (`ALTER TABLE ... ADD COLUMN`, лише відсутні)
  і заповнюються через спільну функцію.
- `bp_attr_self_reported_select()` повертає HTML select для форми запису.
- На фронтенді після успішного запису: `window.bpAttr.lead('booking', 'booking_submit')` (dataLayer без ПД).
  Якщо запис надсилається через `fetch`, додайте поля з `window.bpAttr.fields()` у запит.
- Хук `do_action( 'bp_attr_booking_submitted', $lead_id, $attr, $booking, $args )`.

---

## 6. Поле "Звідки ви дізналися про нас?"

Необов'язковий select з варіантами: Google пошук, Google Карти, Instagram, Facebook, порада знайомих,
порада лікаря, вже був(ла) пацієнтом, інше. Зберігається як `self_reported` (код: `google_search`, `google_maps`,
`instagram`, `facebook`, `friends`, `doctor`, `returning`, `other`).

Як додати у форми запису (будь-який спосіб):

1. **Налаштування → Поле "Звідки ви дізналися про нас?"**: вписати `form_id` форм (зі сторінки "Форми на сайті").
   Select з'явиться перед кнопкою надсилання.
2. Або в Elementor додати поле Select з **ID `self_reported`** і тими самими підписами варіантів.
3. Модуль запису: `bp_attr_self_reported_select()`.

Відповідь **не впливає** на автоматичну класифікацію; у звіті показується поруч, у матриці порівняння.

---

## 7. dataLayer, GTM і GA4

### Події

**`lead_submit`**: після успішного сабміту (Elementor `submit_success`, CF7 `wpcf7mailsent`, WPForms,
звичайні форми - на submit):

```js
{ event: 'lead_submit', form_id, specialty, location,
  ft_source, ft_medium, ft_campaign, lt_source, lt_medium,
  touch_count, paid_in_path, days_to_convert }
```

**`contact_click`**: клік по `tel:`, `viber:`, `t.me`, `wa.me`:

```js
{ event: 'contact_click', contact_type: 'phone'|'viber'|'telegram'|'whatsapp', specialty,
  ft_source, ft_medium, ft_campaign, ft_term, ft_content, ft_landing, ft_ts, ft_click_id,
  lt_source, lt_medium, lt_campaign, lt_term, lt_content, lt_landing, lt_ts, lt_click_id,
  touch_count, paid_in_path, days_to_convert }
```

**`booking_submit`**: з модуля запису через `window.bpAttr.lead('booking', 'booking_submit')`.

Жодних імен, телефонів, email чи коментарів у dataLayer немає.

> Якщо GTM-KVR78MJ уже формує власну подію `lead_submit` (наприклад, слухає Elementor), щоб не було дублів,
> вимкніть автоматичну подію плагіна:
> `add_filter( 'bp_attr_js_config', fn( $c ) => $c + array( 'pushLead' => false ) );`

### Змінні GTM (тип "Data Layer Variable", версія 2)

| Назва змінної в GTM | Data Layer Variable Name |
|---|---|
| `DLV - form_id` | `form_id` |
| `DLV - specialty` | `specialty` |
| `DLV - location` | `location` |
| `DLV - ft_source` | `ft_source` |
| `DLV - ft_medium` | `ft_medium` |
| `DLV - ft_campaign` | `ft_campaign` |
| `DLV - lt_source` | `lt_source` |
| `DLV - lt_medium` | `lt_medium` |
| `DLV - lt_campaign` | `lt_campaign` (лише contact_click) |
| `DLV - touch_count` | `touch_count` |
| `DLV - paid_in_path` | `paid_in_path` |
| `DLV - days_to_convert` | `days_to_convert` |
| `DLV - contact_type` | `contact_type` |

Тригери: Custom Event `lead_submit`, `contact_click`, `booking_submit`.
Теги: GA4 Event з тією ж назвою події і параметрами = змінні вище.

### Custom dimensions у GA4 (Admin → Custom definitions → Create custom dimension, Scope: **Event**)

| Dimension name | Event parameter |
|---|---|
| Form ID | `form_id` |
| Specialty | `specialty` |
| Location | `location` |
| First touch source | `ft_source` |
| First touch medium | `ft_medium` |
| First touch campaign | `ft_campaign` |
| Last touch source | `lt_source` |
| Last touch medium | `lt_medium` |
| Touch count | `touch_count` |
| Paid in path | `paid_in_path` |
| Days to convert | `days_to_convert` |
| Contact type | `contact_type` |

`touch_count` і `days_to_convert` за бажанням можна додатково створити як custom metrics (для середніх значень).

### Google Ads

**Не створюйте нових конверсій у Google Ads на основі цих полів** (наприклад, "заявка з paid_in_path=1").
Конверсії Ads лишаються на реальних подіях: `lead_submit`, `booking_submit`, дзвінки. Поля атрибуції
потрібні для аналізу і звіту власнику, а не для оптимізації ставок: інакше Ads отримає подвійний або
штучно завищений сигнал.

---

## 8. Правила UTM для Meta і Google Business Profile

### Meta (Facebook / Instagram Ads)

**Усі оголошення Meta мають містити `utm_medium=paid_social`, інакше вони не рахуються як платні.**
fbclid без utm класифікується як органіка (`facebook/social`), бо Meta додає fbclid і до звичайних
(неоплачених) переходів з постів і профілю.

Рекомендований шаблон (Ads Manager → Оголошення → Параметри URL):

```
utm_source=facebook&utm_medium=paid_social&utm_campaign={{campaign.name}}&utm_content={{ad.name}}&utm_term={{adset.name}}
```

Для Instagram-розміщень можна `utm_source={{site_source_name}}` (значення `fb`, `ig`, `msg`, `an`).

### Google Business Profile (обидві локації)

Посилання на сайт у профілі кожної локації:

```
https://bpmedical.com.ua/?utm_source=google&utm_medium=gbp&utm_campaign={location}
```

де `{location}` - короткий код локації (наприклад `lviv`, `kyiv`). Без цього перехід з картки Google
часто виглядає як звичайний `google/organic` (браузер передає лише `https://www.google.com/`).
`gbp` у звіті потрапляє в категорію "Google Карти".

### Google Ads

Автоматичне тегування (gclid) має бути ввімкнене. Додаткові utm не обов'язкові, але `utm_campaign` у
шаблоні відстеження дасть назву кампанії у звіті: `{lpurl}?utm_source=google&utm_medium=cpc&utm_campaign={_campaign}`
(`{_campaign}` - custom parameter на рівні кампанії).

---

## 9. Телефон, месенджери, Binotel

Кліки по `tel:`, `viber:`, `t.me`, `wa.me` відправляють `contact_click` з ft_/lt_ полями (див. розділ 7).

### Binotel GetCall

Публічної документації JS API GetCall про custom fields ми не знайшли, тому плагін не робить припущень
про його внутрішній API. Що доступно для інтеграції:

- `window.bpAttr.fields()` повертає всі поля (`ft_source`, `ft_medium`, `gclid`, …);
- подія `window` `bp_attr_ready` сигналізує, що дані готові.

Якщо в кабінеті Binotel / у техпідтримки підтвердять передачу custom fields або UTM з віджета, достатньо
передати в неї `bpAttr.fields().ft_source`, `.ft_medium`, `.gclid`. Уточнюючі питання до Binotel -
у чеклисті нижче.

### Чекліст динамічного коллтрекінгу Binotel (налаштовується в кабінеті Binotel)

- [ ] Пул динамічних номерів для `google / cpc` (Google Ads), розмір пулу ≥ пікової кількості одночасних відвідувачів з реклами.
- [ ] Окремий пул для `paid_social` (Meta Ads з `utm_medium=paid_social`).
- [ ] Статичний номер для `organic` (пошук) і окремий статичний номер на сайті в Google Business Profile кожної локації.
- [ ] Номер за замовчуванням (direct / referral) - основний номер клініки.
- [ ] Правила підміни за `utm_medium` / `gclid`, а не лише за referrer.
- [ ] Передача gclid у Binotel для імпорту офлайн-конверсій дзвінків у Google Ads (якщо використовується).
- [ ] Запитати в Binotel: чи підтримує GetCall custom fields / UTM-передачу з JS (для `ft_source`, `ft_medium`, `gclid`).
- [ ] Перевірити, що підмінені номери мають `tel:`-посилання (тоді `contact_click` фіксує клік).

---

## 10. Звіт "Атрибуція заявок"

**Адмінка → Атрибуція заявок → Звіт**. Фільтри: період, спеціальність, локація, "включно з формами, що не є заявками".

1. **Заявки за категоріями**: кожна заявка потрапляє рівно в одну категорію; кількість, % від усіх,
   розбивка по спеціальностях.

   | Категорія | Правило |
   |---|---|
   | Платна реклама (last touch) | `lt_medium` платний: `cpc`, `paid_social`, `display`, `video` |
   | Платна реклама (асистована) | `lt_medium` не платний, але `paid_in_path = 1` |
   | Органічний пошук | `lt_medium = organic`, платних дотиків немає |
   | Google Карти | `lt_medium = maps` або `gbp`, платних дотиків немає |
   | Соцмережі (органіка) | `lt_medium = social`, платних дотиків немає |
   | Реферали | `lt_medium = referral`, платних дотиків немає |
   | Прямі | жодного непрямого дотику, платних дотиків немає |
   | Інші мітки UTM | інший `utm_medium` (email, qr…), платних немає. Рядок з'являється, лише якщо такі заявки є |

   Опис правил виводиться під таблицею простою мовою, тож звіт можна показати власнику клініки.
2. **Заявки з платним дотиком у шляху**: кількість, середні `days_to_convert` і `touch_count` порівняно з заявками без платних дотиків.
3. **Топ-10 шляхів дотиків**.
4. **Матриця**: автоматична категорія × відповідь пацієнта "Звідки ви дізналися про нас?".
5. **Експорт CSV** (UTF-8 з BOM, для Excel). Містить усі поля атрибуції, **без персональних даних**
   (ім'я / телефон лишаються лише в БД). Значення, що починаються з `= + - @`, екрануються.

Доступ: адміністратори (`manage_options`).

---

## 11. Тести і збірка

```bash
cd bp-attribution
npm install
npm test          # unit-тести classify.js (node:test)
npm run build     # assets/src -> assets/dist, перевірка бюджету < 8 KB gzip
npm run wp:setup  # локальний WordPress 6.5 + SQLite у .wp/ (ядро - з npm-пакета @wp-playground/wordpress-builds)
npm run test:e2e  # Playwright
```

У середовищі без завантажених браузерів Playwright: `PW_CHROMIUM_PATH=/шлях/до/chrome npm run test:e2e`.

**Unit** (`tests/unit/classify.test.js`): gclid + organic referrer → cpc; fbclid без utm → social;
utm_medium=paid_social і нормалізація; referrer maps; direct після cpc не перезаписує last touch;
повторний захід < 30 хв не створює дотик; ліміт 10 дотиків зберігає first touch; внутрішній referrer
(домен і піддомени) ігнорується; виключені referrer-и; кодування cookie і ліміт розміру.

**E2E** (`tests/e2e/attribution.spec.js`, справжній WordPress + плагін + фікстура попапу `#popmake-13597`
з формою в стилі Elementor Pro):

1. захід з `?gclid=test` → повернення з referrer `https://www.google.com/` без параметрів → відкриття попапу →
   сабміт → у БД `ft_medium=cpc`, `lt_medium=organic`, `paid_in_path=1`, `touch_path=google/cpc > google/organic`;
   рядок у листі; `lead_submit` у dataLayer без ПД;
2. Consent Mode: до згоди лише sessionStorage, після `analytics_storage=granted` - cookie;
3. `contact_click` по `tel:`;
4. внутрішній перехід не створює дотик;
5. звіт в адмінці і CSV без ПД;
6. модуль запису: сервер читає cookie і пише ті самі поля у власну таблицю модуля (паритет PHP і JS).

Фікстура `tests/e2e/fixture/bp-e2e-fixture.php` - лише для тестів, **не встановлюйте її на робочий сайт**.

Структура:

```
bp-attribution.php          головний файл плагіна
includes/functions.php      схема БД, cookie, поля, збереження, лист, категорії
includes/integrations.php   Elementor, CF7, WPForms, fallback, wp_mail
includes/booking.php        хелпери модуля запису
includes/admin-*.php        звіт, форми, налаштування
assets/src/classify.js      класифікація (чисті функції, unit-тести)
assets/src/tracker.js       DOM, cookie/згода, форми, dataLayer
assets/dist/                зібраний JS (підключається на сайті)
```

---

## 12. Обмеження

- **Referrer.** Браузери передають лише домен (`https://www.google.com/`), тому перехід з Google Maps
  без UTM зазвичай виглядає як `google/organic`. Саме тому потрібна розмітка GBP (розділ 8).
- **Safari / iOS (ITP)** обмежує cookie, записані з JavaScript, і localStorage до 7 днів (а після переходу
  з click ID у деяких випадках до 24 год). Для частини користувачів iPhone історія буде коротшою за 90 днів,
  і частина асистованих заявок у звіті буде недорахована (не перерахована).
- **Кілька пристроїв**: телефон і ноутбук - це різні історії; атрибуція лише в межах браузера.
- **Відмова від cookies**: дотики живуть лише до кінця сесії (sessionStorage); заявка в тій самій сесії
  все одно отримує поля.
- Заявки, надіслані до встановлення плагіна, у звіті відсутні.
