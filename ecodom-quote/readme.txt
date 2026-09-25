=== EcoDom Quote ===
Contributors: ecodom
Tags: calculator, roof, fence, lead form, telegram
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later

Калькулятор покрівлі (металочерепиця, профнастил) і паркану з формою ліда, PDF-кошторисом, Telegram і вебхуком. Не залежить від WooCommerce.

== Description ==

* CPT «Моделі» (ecodom_model): тип (roof / profnastyl / fence), загальна й корисна ширина, крок хвилі, мін./макс. довжина листа, ціни за м² для товщин 0.40 / 0.45 / 0.50 і покриттів глянець / мат.
* Шорткод `[ecodom_calc type="" model=""]`.
  * `type` — `roof`, `fence` або порожньо (тоді показується перемикач «Покрівля / Паркан»).
  * `model` — ID або slug моделі. Порожньо — показується select з моделями, що підходять до режиму.
* Покрівля: кілька скатів (прямокутник або трапеція/трикутник). Кількість листів рахується по колонках корисної ширини; довжина листа округлюється до кроку хвилі (металочерепиця) або до 10 мм (профнастил); якщо скат довший за макс. довжину — розбивається на ряди з нахлестом. Доборні: коньок/ребра, торцеві й карнизні планки за периметром; саморізи — 8 шт/м² (налаштовується).
* Паркан: довжина, висота, тип стовпів (список у налаштуваннях), лаги 2 або 3 ряди.
* Результат — таблиця позицій і вилка ціни ±10 % (налаштовується).
* Форма ліда: ім'я, телефон з маскою +380, канал зв'язку. Приховані поля: utm_source, utm_medium, utm_campaign, utm_term, utm_content, gclid, fbclid (з URL, зберігаються в cookie `edq_attr` на 90 днів — на будь-якій сторінці сайту), URL сторінки.
* Лід зберігається в CPT «Ліди» (ecodom_lead), генерується PDF (dompdf), надсилається в Telegram (повідомлення + PDF) і POST-ом JSON на вебхук.
* `window.dataLayer.push` події `calc_start`, `calc_complete`, `calc_lead` з параметрами `model`, `sqm`, `value`, `currency: "UAH"` (+ `calc_type`, `lead_id`).
* Захист: nonce, sanitize/escape, ціни рахуються тільки на сервері, rate limit 5 заявок/год з IP (і 120 розрахунків/год), honeypot + тайм-пастка.
* CSS/JS підключаються лише на сторінках із шорткодом. Vanilla JS, без jQuery. Стилі з префіксом `.edq-` у контейнері `.edq-root` + container queries.
* Українська локалізація (languages/ecodom-quote-uk.*). Базові рядки — англійською через `__()`, тож плагін можна перекласти й іншими мовами.

== Installation ==

1. Зберіть ZIP з dompdf: у папці плагіна виконайте `bin/build-zip.sh` (потрібні composer і zip). Або на сервері: скопіюйте папку `ecodom-quote` у `wp-content/plugins/` і виконайте в ній `composer install --no-dev`.
   Без dompdf плагін працює, але ліди надходять без PDF (на сторінці налаштувань буде попередження).
2. «Плагіни → Додати новий → Завантажити плагін» → ecodom-quote.zip → Активувати.
3. «EcoDom Quote → Налаштування»: токен бота, chat_id, URL вебхука, текст гарантії, ціни доборних, саморізів, лаг і типи стовпів.
   * Токен: @BotFather → /newbot. chat_id: додайте бота в групу, напишіть у групу повідомлення і відкрийте `https://api.telegram.org/bot<TOKEN>/getUpdates` — поле `chat.id` (для груп від'ємне).
4. Додайте моделі («EcoDom Quote → Моделі») або імпортуйте CSV (див. нижче).
5. Вставте шорткод на сторінку: `[ecodom_calc]`, `[ecodom_calc type="roof"]`, `[ecodom_calc type="fence" model="ps-8"]`.
6. Перевірка сповіщень: `wp edq test-notify` (надсилає тестовий лід у Telegram і на вебхук).
7. Мова сайту має бути «Українська» (Налаштування → Загальні), тоді інтерфейс калькулятора буде українською.

== Перенесення наявних цін ==

1. «EcoDom Quote → Імпорт / експорт» → «Завантажити CSV» — отримаєте шаблон (або візьміть `sample-models.csv`).
2. Заповніть у Excel / Google Таблицях: одна модель — один рядок.
   Колонки: `slug;title;type;width_total;width_useful;wave_step;length_min;length_max;p040_gloss;p040_matt;p045_gloss;p045_matt;p050_gloss;p050_matt`
   * type: `roof` (металочерепиця), `profnastyl` (покрівля і паркан), `fence` (тільки паркан).
   * розміри — у мм; ціни — грн/м², десяткова кома дозволена; 0 — варіант недоступний; порожня клітинка — залишити поточну ціну.
3. Збережіть як CSV (UTF-8 або Windows-1251, роздільник «;» чи «,») і завантажте на тій же сторінці. Моделі з тим самим slug (або назвою) оновлюються, нові — створюються. Повторний імпорт безпечний.
4. Через WP-CLI: `wp edq import prices.csv`, експорт: `wp edq export > prices.csv`.
5. Оновлення прайсу надалі: експорт → правка цін → імпорт.

== Frequently Asked Questions ==

= Кешування сторінок =
Форма використовує nonce (дійсний 12–24 год). Якщо сторінка з калькулятором кешується довше (WP Rocket, LiteSpeed, Cloudflare APO), виключіть її з кешу або задайте TTL кешу менше 10 годин.

= Сайт за Cloudflare / проксі =
Для rate limit потрібна реальна IP відвідувача: `add_filter( 'edq_client_ip', fn( $ip ) => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $ip );`

= Вебхук на внутрішню адресу =
Запити йдуть через `wp_safe_remote_post`, тому локальні IP і нестандартні порти блокуються (захист від SSRF). Використовуйте публічний HTTPS-URL.

= Формат вебхука =
POST JSON: `event` ("lead.created"), `lead_id`, `created_at`, `site`, `contact` {name, phone, channel}, `calc` {mode, model_id, model, thickness, coating, sqm, total, price_min, price_max, currency, positions[], input}, `attribution` {utm_*, gclid, fbclid}, `page_url`. Якщо задано секрет — заголовок `X-EDQ-Signature: sha256=<HMAC-SHA256 тіла>`.

= Як змінити вигляд? =
Перевизначте CSS-змінні: `.edq-root { --edq-accent: #d35400; --edq-radius: 4px; }`. Шаблони можна скопіювати в тему: `your-theme/ecodom-quote/calculator.php` і `your-theme/ecodom-quote/pdf.php`.

= Хуки =
* `edq_lead_created` (action) — `$lead_id, $data, $pdf_path`.
* `edq_webhook_payload` (filter) — змінити JSON вебхука.
* `edq_template` (filter) — шлях до шаблону.
* `edq_client_ip` (filter) — IP для rate limit.

== Changelog ==

= 1.0.0 =
* Перший реліз.
