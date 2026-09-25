=== BP Meta Generator ===
Requires at least: 6.0
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later

Автоматична генерація meta description через Claude API (Anthropic) для записів, сторінок і CPT. Підтримка Elementor, Yoast SEO, Rank Math.

== Встановлення ==

1. Адмінка → Плагіни → Додати новий → Завантажити плагін → оберіть bp-meta-generator.zip → Встановити → Активувати.
2. (Рекомендовано) додайте ключ у wp-config.php над рядком "That's all, stop editing!":
   define( 'BPMG_API_KEY', 'sk-ant-...' );
   Або вставте ключ у Налаштування → BP Meta Generator (константа має пріоритет).
3. Налаштування → BP Meta Generator: натисніть "Перевірити з'єднання", оберіть типи записів, збережіть.
4. Для наявних сторінок натисніть "Згенерувати для всіх без опису" і дочекайтеся 100% (сторінку можна закрити).

== Де зберігаються описи ==

* Yoast SEO активний → _yoast_wpseo_metadesc (виводить Yoast).
* Rank Math активний → rank_math_description (виводить Rank Math).
* Без SEO-плагіна → _bpmg_meta_description; плагін сам виводить <meta name="description"> і og:description.
  Якщо тема або інший плагін уже виводить такий тег, тег плагіна автоматично прибирається, тож дублів не буде.

== Використання ==

* Автоматично: після публікації запису з порожнім описом генерація запускається у фоні приблизно за 15 секунд (WP-Cron).
* Вручну: метабокс "Meta description (BP)" у редакторі. Кнопки "Згенерувати" і "Перегенерувати", лічильник символів.
* Список записів: колонка "Meta description" і групова дія "Згенерувати опис".
* Лог помилок (останні 50) — внизу сторінки налаштувань.

== Примітки ==

* WP-Cron запускається відвідуванням сайту. Якщо в wp-config.php стоїть DISABLE_WP_CRON, масова генерація все одно
  йде, поки відкрита сторінка налаштувань, але автогенерації після публікації потрібен системний cron.
* При видаленні плагіна видаляються його налаштування і лог. Згенеровані описи лишаються.
* Фільтри для розробників: bpmg_source_text, bpmg_source_max_chars, bpmg_elementor_widget_text, bpmg_generated_description,
  bpmg_bulk_batch_size, bpmg_request_pause, bpmg_max_retries, bpmg_output_enabled. Дія: bpmg_description_saved.
