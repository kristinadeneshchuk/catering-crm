# Розкатка сервісу

## Як влаштовано

Пуш у `main` → `.github/workflows/deploy.yml` заходить по SSH на кожен сервер,
робить `git pull origin main` і запускає `deploy/deploy.sh`.

`deploy.sh` сам код не тягне — інакше він не встиг би оновитися до власного запуску.
Порядок кроків усередині:

1. `artisan down` (503 + `?secret=deploying` для перегляду живими очима);
2. `composer install --no-dev --optimize-autoloader`;
3. `storage:link`;
4. `migrate --force`;
5. чистка та перебудова кешів (`config` / `event` / `view`);
6. `queue:restart`;
7. `artisan up` — через `trap` він виконається навіть якщо крок посередині впав.

Асети (`public/build`) лежать у git, тому `npm` на сервері не потрібен.

### Чому немає `route:cache`

У `routes/web.php` є closure-роути (`/`, `client.dashboard`, `/migrate-orders-to-days`).
Laravel не серіалізує замикання, тому `route:cache` і загальний `artisan optimize`
падають. Якщо колись перевести ці роути на контролери — можна додати `route:cache`
в скрипт і виграти ще трохи на кожному запиті.

### Чому `config:cache` тепер безпечний

`config:cache` вимикає `env()` поза файлами `config/` — там повертається `null`.
Єдиний такий виклик був у `AdminPanelProvider::panel()` (`brandName(env('APP_NAME'))`),
через нього після кешування бренд у панелі перетворився б на дефолт. Переведено
на `config('app.name')`. **Нові `env()` поза `config/` додавати не можна.**

## Ручний деплой

```bash
cd /home/afood/a-food.com.ua/okn
git pull origin main
bash deploy/deploy.sh
```

Прапорці на випадок пожежі:

```bash
SKIP_MIGRATE=1 bash deploy/deploy.sh    # без міграцій
SKIP_COMPOSER=1 bash deploy/deploy.sh   # без чіпання vendor/
PHP_BIN=/usr/bin/php8.3 bash deploy/deploy.sh
```

Якщо деплой обірвався і сайт лишився в maintenance:

```bash
php artisan up
```

## Фонові процеси

Без них частина функціоналу просто мовчить:

| Процес | Що зламається без нього |
| --- | --- |
| `queue:work` | розсилки в месенджери (`SendOutboundMessage`), перерахунок собівартості меню (`RecalculateDailyMenuCosts`) |
| `schedule:run` | Telegram-аналітика (ранкова/вечірня/тижнева), чистка activity-логу |

Юніти лежать у `deploy/systemd/`. Перед копіюванням заміни в них `APP_USER` та
`APP_DIR` на реальні шляхи сервера — systemd не підставляє змінні в `User=`
і `WorkingDirectory=`.

```bash
sudo cp deploy/systemd/crm-queue.service /etc/systemd/system/
sudo cp deploy/systemd/crm-scheduler.service /etc/systemd/system/
sudo cp deploy/systemd/crm-scheduler.timer  /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now crm-queue crm-scheduler.timer
```

Перевірка:

```bash
systemctl status crm-queue
systemctl list-timers crm-scheduler.timer
php artisan queue:monitor default
```

Якщо на сервері вже стоїть cron замість systemd — достатньо одного рядка:

```
* * * * * cd /home/APP_USER/APP_DIR && php artisan schedule:run >> /dev/null 2>&1
```

## Сервер horenko

Там workflow викликає `/usr/local/bin/crm-deploy` — скрипт живе на самому сервері,
не в репозиторії. Щоб не тримати дві різні логіки розкатки, його тіло має бути:

```bash
#!/usr/bin/env bash
set -e
cd /шлях/до/crm
git pull origin main
bash deploy/deploy.sh
```

## Що варто зробити окремо

- `routes/web.php:113` — `GET /migrate-orders-to-days` відкритий без авторизації
  й створює записи `OrderDay`. Це одноразова міграція з минулого; її треба або
  видалити, або закрити `->middleware('auth')`.
- Перевести решту closure-роутів на контролери, щоб увімкнути `route:cache`.
