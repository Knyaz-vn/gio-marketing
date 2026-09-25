# Архітектура

## Контекст

bpmedical.com.ua працює на WordPress, тому модуль реалізовано як плагін: REST API, шорткод і Gutenberg-блок, адмінка.
Бізнес-логіка винесена в шари без залежності від WordPress, тож її покрито тестами на PHPUnit + SQLite без встановленого WP.

```
Google Calendar ──events.list / watch──▶ CalendarSync ──▶ wp_bpmb_events (кеш + doctor_ids)
      ▲                                                        │
      │ events.insert / patch                                   ▼
BookingService ◀── REST /holds, /bookings ◀── віджет ──▶ REST /availability, /slots ──▶ AvailabilityService
      │                                                        ▲
      └──▶ wp_bpmb_bookings, wp_bpmb_holds ──(зайнятість)──────┘
      └──▶ Telegram / email
```

## Шари

| Шар | Каталог | Залежність від WP |
|---|---|---|
| Ядро: нормалізація, матчинг прізвищ, класифікація подій, графіки, слоти | `src/Core` | ні |
| Бронювання: hold / submit / статуси | `src/Booking` | ні |
| Календар: Google (JWT service account), Mock, синхронізація, watch | `src/Calendar` | лише `WpHttpTransport` |
| Зберігання: `Connection` (wpdb + GET_LOCK / PDO + flock), схема, репозиторії | `src/Storage` | лише `WpdbConnection` |
| WordPress: REST, адмінка, шорткод, cron, ролі, секрети | `src/Rest`, `src/Admin`, `src/Frontend`, `src/Cron`, `src/Security`, `Plugin.php` | так |

## Матчинг прізвищ (`SurnameMatcher`)

1. `Normalizer`: lower-case; апострофи `' ’ ʼ ` ‘ ′ ´` зводяться до `'`; у словах з кирилицею латинські двійники
   `a c e i o p x y k m t b h ï` замінюються на кириличні; прибираються зайві пробіли.
2. Назва розбивається на слова (апостроф усередині слова лишається). Alias може складатися з кількох слів, і порівнюється послідовність **цілих слів**.
3. `anywhere` означає збіг будь-де. `prefix` означає лише «лікарську» позицію: якщо в назві є роздільник `|` або ` - `, це всі прізвища лікарів у першому сегменті
   (сегмент має починатися з прізвища лікаря); без роздільника це лише перше слово.
4. Для діагностики завжди рахуються обидва набори. `suspicious` = знайдені будь-де мінус знайдені в prefix-позиції.

## Захист від подвійного запису

1. `POST /holds`: під локом лікаря (`GET_LOCK('bpmb_doc_<id>')`) слот перевіряється і створюється hold на 7 хв. Чужі holds віднімаються від вільного часу.
2. `POST /bookings`: під тим самим локом виконується свіжий `events.list` на добу слота для всіх календарів (оновлює кеш), повторна перевірка
   (події + чужі holds + активні заявки з буфером), INSERT заявки, `events.insert`, видалення hold.
3. Активні заявки в БД займають час незалежно від календаря, тому поки подія не з'явилась у кеші, слот усе одно зайнятий.

## Дані

- `wp_bpmb_events`: кеш подій вікна «сьогодні … +горизонт+2» з `doctor_ids` (`,id1,id2,`), `suspicious_ids`, `flags` (unrecognized, suspicious, multi, all_day).
  Прозорі й скасовані події не зберігаються.
- `wp_bpmb_holds`: тимчасові утримання.
- `wp_bpmb_bookings`: заявки (статуси pending → confirmed / cancelled / no_show / visited), атрибуція, хеш телефону.
- `wp_bpmb_callbacks`: заявки на зворотний дзвінок.
- `wp_options`: `bpmb_settings`, `bpmb_doctors`, `bpmb_specialties`, `bpmb_services`, `bpmb_locations`, `bpmb_watch_state`, `bpmb_sync_report`.

Час зберігається як unix timestamp (UTC), розрахунки ведуться в `Europe/Kyiv` (з fallback на `Europe/Kiev` для старих tzdata).

## Публічний API (`/wp-json/bp-booking/v1`)

| Метод | Шлях | Відповідь |
|---|---|---|
| GET | `/catalog` | спеціальності (з `doctor_ids`), лікарі з онлайн-записом і їхні послуги, локації, налаштування віджета |
| GET | `/nearest?doctors=a,b` | найближча дата з вільним часом для кожного лікаря |
| GET | `/availability?doctor&service&from&to` | дні з кількістю вільних слотів |
| GET | `/slots?doctor&service&date` | слоти на день (`start`, `time`, `end_time`, `location_id`) |
| POST | `/holds` | `hold_token`, `expires_at`, `slot`, або 409 + 3 альтернативи |
| POST | `/bookings` | 201 `lead_id`, `ics_url`, … / 409 `slot_unavailable` + альтернативи / 422 `fields` / 429 |
| POST | `/callback` | 201 |
| GET | `/ics/{lead}?t=` | `.ics` (токен = HMAC від lead_id) |
| POST | `/calendar/webhook` | push від Google (перевірка `X-Goog-Channel-ID` / `X-Goog-Channel-Token`) |

Адмінський API (`/admin/*`) використовує cookie-авторизацію WP з `X-WP-Nonce`. Capabilities: `bpmb_manage_bookings` (реєстратор, адміністратор)
і `bpmb_manage_settings` (адміністратор).
