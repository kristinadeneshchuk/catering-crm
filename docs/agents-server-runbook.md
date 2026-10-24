# Агенти на сервері CRM — запуск кроку 1

ТЗ: `docs/tz-ai-agents-in-crm.md`. Гілка `feat/ai-agents-server`. Кожен пункт нижче — лише з ОК власника.

## 1. Перед деплоєм
- Бекап: `mysqldump --single-transaction --quick ufitcrm | gzip > /var/backups/horenko/db/ufitcrm_pre-agents-server_$(date +%F_%H%M).sql.gz`.
- Виправити 10 «битих» продуктів довідника (MenuGuard V4 інакше не прийме меню з ними): #105 Цукор, #452 Фета, #279 Кетчуп, #449 Ковбаса, #450 Буженина домашня, #277 Хлібці, #455 Соус Ворчестер, #252 Перець Капі, #426 Мікрогрін соняшник — немає БЖВ; #250 «НЕ ВИКОРИСТОВУВАТИ — дубль шампіньйонів» — прибрати зі страв. Тимчасово можна `AI_BROKEN_INGREDIENT_LEVEL=warn`.

## 2. Міграції (5 шт., накочуються деплоєм)
`2026_10_24_100000…100400`: поля задач (клієнт, замовлення, період, шаблон, витрати), `ai_task_events` (тред), `checks/cross_check/version` у пропозиціях, `ingredient_groups(+items)`, `client_diet_profiles`. Лише додають колонки й таблиці, наявних даних не змінюють.

## 3. .env на сервері
```
AGENTS_DAILY_CAP_USD=20
AGENT_MENU_MODEL=claude-opus-5-5
AGENT_MENU_EFFORT=high
AGENT_MENU_MAX_STEPS=40
```
`ANTHROPIC_API_KEY` уже є (операційний ШІ). Після правки: `php8.5 artisan config:cache`.

## 4. Окремий воркер черги `agents`
`/etc/supervisor/conf.d/horenko-agents.conf`:
```
[program:horenko-agents]
command=php8.5 /var/www/horenko/artisan queue:work --queue=agents --timeout=1800 --tries=1 --sleep=3
directory=/var/www/horenko
user=www-data
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=1810
stdout_logfile=/var/www/horenko/storage/logs/agents-worker.log
```
`supervisorctl reread && supervisorctl update`. Основний `horenko-worker` черги `agents` не бере. У `crm-deploy` додати `supervisorctl restart horenko-agents:*`.

## 5. Знання з теки на Маку
1. Групи-синоніми: `php8.5 artisan agents:ingredient-groups` (показати) → `--write`.
2. Профілі: на Маку `php artisan agents:import-profiles ~/Documents/individual-rations/profiles --export=profiles.json --modes=hohol-denys:hybrid,dovhan-yurii:vip,polina:own_menu_only,kulzhanova-ainur:own_menu_only,alekseienko-yelyzaveta:own_menu_only,lazutina-olena:own_menu_only`, файл — на сервер, `agents:import-profiles profiles.json` (показати) → `--write`. Цілі БЖВ замовлень команда не міняє, лише показує розбіжності.

## 6. Тіньовий тиждень
3–5 клієнтів: менеджер на картці → «Доручити агенту» → «Меню (сервер)». Пропозиції не погоджувати, порівнювати з меню сесії «Індивідуальні раціони». Витрати — у «Задачі ШІ» (колонка $) і `ai_runs` (purpose `agent_menu`).

## Що змінилось для агентів на Маку (API agents/v1)
- `individual_week_menu` тепер проходить MenuGuard: жорсткі помилки (виключення на всю глибину ПФ і групами, дні поза замовленням, биті продукти, КБЖВ дня > 20 % від цілі при повному дні) → 422 з кодами `[V1]…[V5]`; попередження — у `warnings`.
- Нова версія меню на ті самі дати від того самого агента замінює попередню (стара → `rejected`, «Замінено новою версією»). Меню іншого агента на ті самі дати, що чекає погодження, — 422.
