# crm_host — Claude working notes

## Стиль ответов и работы

- Перед правками **не пиши «Факти перед редагуванням»** и подобные длинные fact-листы (4 пункта про imports / public API / data schemas / instruction). ECC-hook `pre:edit-write:gateguard-fact-force` отключён в `~/.zshrc`.
- Достаточно одной строки о намерении («Правлю `EditOrder.php`: убираю запись `status` из формы, перевожу на `recomputeStatus()`») и сразу действие.
- Если задача рискованная (миграции, удаления, массовые правки в проде) — короткий план в 3–5 строк, потом ОК пользователя, потом действие. Полную «инвентаризацию импортёров» делай только если пользователь явно попросил.
- Деструктивный bash (`rm -rf`, `git reset --hard`, `git push --force`, `drop table`) — продолжай требовать обоснование. Это правильный gate, его не отключали.

## Контекст проекта

- Filament + Livewire + Eloquent (Laravel).
- Бизнес-домен: заказы (`Order`) с дочерними заказами и днями (`OrderDay`); статусы — `new / active / paused / finished / completed`.
- **`paused` — sticky:** `recompute` не должен переводить `paused → active`, даже если есть будущие дни. Это ловушка трёх копий логики (ListOrders, EditOrder, OrderCalendar) — следи за этим при любом касании статуса.
