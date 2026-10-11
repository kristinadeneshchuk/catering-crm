# API для ШІ-агентів (CRM)

Принцип: **агент пропонує → людина погоджує (CRM «ШІ → Погодження» або кнопки в Telegram) → CRM виконує сама.** У бойові дані агент напряму не пише.

База: `https://crm.horenko-production.com/api/agents/v1`, заголовок `Authorization: Bearer <токен агента>`.
Токен видає супер адмін: «ШІ → ШІ-агенти» → 🔑 (або `php artisan agents:token <slug>`). Токен не зберігати у файлах/памʼяті — лише keychain/env.

| Метод | Шлях | Що |
|---|---|---|
| GET | `me` | хто я, правила власника (`instructions`), дозволені типи |
| GET | `tasks?status=new,in_progress` | мої задачі |
| PATCH | `tasks/{id}` | `{status: in_progress|done|failed, result?}` |
| POST | `proposals` | `{type, title?, summary?, task_id?, payload}` → 201 `{id}` або 422 `{errors: [...]}` |
| GET | `proposals?status=…&since=…` | що сталося з моїми пропозиціями (`review_comment` — правки) |
| POST | `reports` | `{title, body (markdown), level: info|warn|error, task_id?}` |
| POST | `messages` | написати людям: `{text, files:[{name,mime,base64}]}`; іншому агенту: `{to: "<slug>", text}` (у Telegram не йде) |
| GET | `messages?after_id=…&only_new_for_me=1` | відповіді людей (з CRM і реплаї в Telegram), події «погоджено/відхилено», листи інших агентів |
| GET | `dishes?q=&ingredient=&kind=individual\|cycle\|all&order_id=` | готові страви: КБЖВ і собівартість на 100 г, `kind`, `conflicts` з виключеннями клієнта, `served_to_client_last_28d` — **спершу збирати меню з цього** |
| GET | `files/{message_id}/{index}` | файл з чату (свій, адресований мені, або з пропозиції в моїй задачі) |
| GET | `memory?prefix=…` | моя памʼять |
| PUT | `memory/{key}` | `{value: <будь-який JSON>}` |
| DELETE | `memory/{key}` | |

Статуси пропозиції: `pending` → `applied` (погоджено й виконано) / `changes_requested` (див. `review_comment`, виправити й подати нову) / `rejected` / `failed` (виконати не вдалося, `apply_result.error`).

## Тип `individual_week_menu`

```json
{
  "order_id": 1925,
  "replace_days": true,
  "new_dishes": [
    {"ref": "hohol_chicken", "name": "Інд · Курка з булгуром (Гоголь)", "group": "Обіди", "base_weight_g": 350,
     "description": "Технологія:\n1. …\nПакування: …",
     "ingredients": [{"ingredient_id": 139, "net_weight_g": 150}, {"child_dish_id": 838, "net_weight_g": 60}]}
  ],
  "days": [
    {"date": "2026-10-12", "meals": [
      {"meal_type_id": 1, "dish_id": 840},
      {"meal_type_id": 3, "dish_ref": "hohol_chicken", "weight_grams": null, "cooking_note": "без солі"}
    ]}
  ]
}
```

- Нова страва з тією самою назвою, що вже є в довіднику, не дублюється — береться наявна.
- `weight_grams: null` — CRM рахує вагу під калораж клієнта.
- `replace_days: true` — інше персональне меню цих дат прибирається.
- Після погодження CRM створює страви (`dishes` + `dish_ingredients`) і персональне меню (`order_day_dishes`) — звідти план виробництва, стікери, закупка.

## Памʼять: рекомендовані ключі
- `client:<client_id>:week:<YYYY-MM-DD>` → `{status, proposal_id, missing: [...], notes}` — що вже зроблено по клієнту на тиждень.
- `rules:<тема>` — рішення власника, які треба памʼятати.

## Тип `client_message` (контракт з Inbox, 11.10.2026)

```json
{"conversation_id": 72575, "crm_client_id": 665, "account": "Afood", "text": "…",
 "attachments": [{"kind": "photo", "name": "меню_1.png", "mime": "image/png", "base64": "…"}],
 "based_on_message_id": 123456, "preview": "Ім'я · канал · останні вхідні"}
```

- text ≤ 4000, без незаповнених `[заготовок]`; ≤ 10 файлів по ≤ 10 МБ. Вкладення CRM зберігає файлами, не в базі.
- Після погодження CRM шле в Inbox `POST INBOX_CRM_SEND_URL` з `X-Inbox-Timestamp`, `X-Inbox-Signature: sha256=HMAC(CRM_SEND_SECRET, ts + "." + body)`, `Idempotency-Key: proposal-<id>`; тіло = payload + proposal_id, approved_by, approved_at.
- 201/200 → `applied`; 409 stale → `changes_requested` (у CRM кнопка «Надіслати все одно» → повтор з `force: true`); 409 locked / 422 → `failed`; 502 → до 3 спроб, далі `failed`.

## Перевірки меню (при подачі, у `warnings` відповіді й у картці)
- **Повтори:** страва не має повторюватись 28 днів (усі замовлення клієнта) і двічі в одному меню. Якщо клієнт сам просить або це додаток (білкова добавка) — перелічіть у `payload.repeat_ok` (id страв або ref нових).
- **Собівартість:** продукти дня ≤ FOOD_COST_MAX_PCT (30%) від ціни дня замовлення (1100 → ≤ 330 грн). Вага без `weight_grams` — під калораж. Ціни з карток — орієнтовно.
- Пропозицію з `warnings` не блокуємо, але краще виправити й подати знову до погодження.

## Конвеєр індивідуальних меню
1. Агент меню (з власником) збирає тиждень **спершу з готових страв** (`GET dishes`), нову — лише коли нічого не підходить; дивиться навантаження кухні.
2. Віддає чернетку рецензенту (`POST messages {to: "<рецензент>"}`), враховує зауваження, подає `individual_week_menu` (PDF — у `files`).
3. Власник/менеджер погоджує в CRM → CRM записує меню й ставить задачу агенту розсилки (`AI_MENU_HANDOFF_AGENT`, за замовч. `inbox`) з файлами.
4. Агент розсилки звіряється з рецензентом і подає `client_message` → погодження → відправка клієнту.
- Попередження при подачі: повтори (28 днів), собівартість (≤ 30%), **навантаження кухні** — окремих страв поза циклом на день по всіх індивідуальних (`KITCHEN_OFFCYCLE_MAX`, 6).
