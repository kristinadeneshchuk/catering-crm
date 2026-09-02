# Деплой з локальної машини

Хмарна сесія Claude Code не може залити проєкт на хостинг: у неї закриті
порти 22 і 21, а вихідний трафік дозволений лише до списку доменів розробки.
Це навмисна ізоляція середовища, і обійти її не можна.

Локальна сесія таких обмежень не має — вона працює на вашому комп'ютері з
вашою мережею. Далі все, що потрібно, щоб довести справу до кінця.

## 1. Поставити Claude Code

```bash
npm install -g @anthropic-ai/claude-code
```

Актуальні способи встановлення (є ще нативний інсталятор і збірки під
Windows) — у документації: <https://code.claude.com/docs>.

## 2. Забрати проєкт

```bash
git clone https://github.com/kristinadeneshchuk/catering-crm.git
cd catering-crm
git checkout claude/service-deployment-fvcr4g
cd bur-rental
```

## 3. Перевірити, що все живе локально

```bash
composer install
npm install && npm run build
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate:fresh --seed
php artisan test          # має бути зелено
php artisan serve         # http://127.0.0.1:8000, адмінка на /admin
```

## 4. Підготувати сервер

У панелі хостингу (FastPanel):

1. **Додати свій SSH-ключ** користувачу сайту. Перевірити:
   `ssh -p 22 користувач@хост` — має пустити без пароля.
2. **Створити базу MySQL** і записати доступи.
3. **Корінь сайту** вказати на `bur_app/public`.
   Якщо панель не дозволяє винести корінь за межі `public_html` —
   візьміть `deploy/shared-hosting/index-alt.php`, перейменуйте в `index.php`
   і покладіть у `public_html` разом із вмістом `bur_app/public/`.
4. **PHP 8.4**, розширення: `pdo_mysql`, `mbstring`, `gd`, `zip`, `intl`.

## 5. Покласти `.env` на сервер

Один раз, руками — скрипт деплою його **не чіпає** навмисно: перезаписати
бойові паролі файлом з ноутбука означає покласти сайт.

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://ваш-домен
APP_KEY=            # php artisan key:generate --show

DB_CONNECTION=mysql
DB_DATABASE=…
DB_USERNAME=…
DB_PASSWORD=…

# Поки не запускаєте в індексацію — лишайте true.
SITE_NOINDEX=true

ADMIN_EMAIL=…
ADMIN_PASSWORD=…    # не словниковий, check:launch це перевіряє

# З'являться пізніше:
# SMS_DRIVER=…
# TELEGRAM_BOT_TOKEN=
# TELEGRAM_MANAGER_CHAT_ID=
```

## 6. Задеплоїти

```bash
BUR_SSH=користувач@хост \
BUR_PATH=/var/www/користувач/data/bur_app \
BUR_PHP=php8.4 \
./deploy/deploy.sh
```

Скрипт ганяє тести, збирає фронтенд, ставить залежності без dev-пакетів,
синхронізує файли (не чіпаючи `.env` і `storage/`), а на сервері виконує
міграції, кеші й `check:launch`.

Перший запуск бази — окремо, бо `migrate --force` порожню базу лише
створить структуру:

```bash
ssh користувач@хост "cd /var/www/…/bur_app && php8.4 artisan db:seed --force"
```

Сиди наливають каталог, категорії, тексти й статті. **Демо-відгуки в них
позначені `demo = true` і на сайт не потрапляють**, рейтинги обнулені.

## 7. Два крон-рядки

Без них не працюють Telegram-сповіщення і нагадування клієнтам:

```
* * * * * cd /var/www/…/bur_app && php8.4 artisan queue:work --stop-when-empty --max-time=50
* * * * * cd /var/www/…/bur_app && php8.4 artisan schedule:run >> /dev/null 2>&1
```

## 8. Перед відкриттям для Google

```bash
php artisan check:launch
```

Червоне означає «не вмикати індексацію». Найчастіші блокери на цьому етапі:
демо-телефони й адреси філій у базі (замінити в адмінці), `SITE_NOINDEX=true`,
словниковий пароль адмінки.

Коли все зелене — зняти `SITE_NOINDEX`, перевірити `/robots.txt` і
`/sitemap.xml`, і віддати домен у Search Console.

## Що сказати локальній сесії Claude Code

Скопіюйте цей текст першим повідомленням:

> Проєкт `bur-rental` у цьому репозиторії, гілка `claude/service-deployment-fvcr4g`.
> Треба задеплоїти на хостинг за інструкцією `deploy/LOCAL-DEPLOY.md`.
> Мій SSH: `користувач@хост`, папка застосунку `/var/www/…/bur_app`, PHP `php8.4`,
> домен `https://…`. Спершу переконайся, що `ssh` проходить і тести зелені,
> потім `./deploy/deploy.sh`, у кінці `check:launch` і скажи, що лишилось
> червоним. `.env` на сервері я вже поклав — не перезаписуй його.
