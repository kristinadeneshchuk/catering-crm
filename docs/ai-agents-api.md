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
