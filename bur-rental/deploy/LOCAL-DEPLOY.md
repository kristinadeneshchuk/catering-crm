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

## 4. Домен — tekhpark.com.ua

1. **Власник домену** — клієнт або його ФОП, а не підрядник: домен — це
   актив бізнесу. Перевірте це в кабінеті реєстратора.
2. **Автопродовження** увімкнути одразу. Прострочений домен за кілька днів
   перехоплюють, і разом з ним — усю накопичену видачу.
3. **Захисний домен** `tehpark.com.ua` (як набирають «на слух») — купити
   й налаштувати 301-переадресацію на `https://tekhpark.com.ua`.
4. **DNS:** у FastPanel додати сайт `tekhpark.com.ua`, потім у реєстратора або
   прописати NS хостингу, або A-запис `@` і `www` на IP сервера.
   Перевірка: `dig +short tekhpark.com.ua` показує IP сервера (оновлюється до кількох годин).
5. **SSL:** у FastPanel випустити Let's Encrypt для домену і `www`,
   увімкнути редирект HTTP → HTTPS і `www` → без `www` (або навпаки —
   головне, одна адреса). Без HTTPS `check:launch` не пропустить.

## 5. Підготувати сервер

У панелі хостингу (FastPanel):

1. **Додати свій SSH-ключ** користувачу сайту. Перевірити:
   `ssh -p 22 користувач@хост` — має пустити без пароля.
2. **Створити базу MySQL** і записати доступи.
3. **Корінь сайту** вказати на `tekhpark_app/public`.
   Якщо панель не дозволяє винести корінь за межі `public_html` —
   візьміть `deploy/shared-hosting/index-alt.php`, перейменуйте в `index.php`
   і покладіть у `public_html` разом із вмістом `tekhpark_app/public/`.
4. **PHP 8.4**, розширення: `pdo_mysql`, `mbstring`, `gd`, `zip`, `intl`.

## 6. Покласти `.env` на сервер

Один раз, руками — скрипт деплою його **не чіпає** навмисно: перезаписати
бойові паролі файлом з ноутбука означає покласти сайт.

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_NAME=Техпарк
APP_URL=https://tekhpark.com.ua
APP_KEY=            # php artisan key:generate --show

DB_CONNECTION=mysql
DB_DATABASE=…
DB_USERNAME=…
DB_PASSWORD=…

# Поки не запускаєте в індексацію — лишайте true.
SITE_NOINDEX=true

ADMIN_EMAIL=…
ADMIN_PASSWORD=…    # не словниковий, check:launch це перевіряє

# Аналітика: контейнер створити на tagmanager.google.com, у ньому — тег GA4.
GTM_ID=GTM-…

# Продавець — показується в футері й на контактах, без нього check:launch червоний.
COMPANY_LEGAL_NAME="ФОП … або ТОВ «…»"
COMPANY_EDRPOU=…
COMPANY_LEGAL_ADDRESS="…"

# З'являться пізніше:
# SMS_DRIVER=…
# TELEGRAM_BOT_TOKEN=
# TELEGRAM_MANAGER_CHAT_ID=
```

## 7. Задеплоїти

```bash
DEPLOY_SSH=користувач@хост \
DEPLOY_PATH=/var/www/користувач/data/tekhpark_app \
DEPLOY_PHP=php8.4 \
./deploy/deploy.sh
```

Скрипт ганяє тести, збирає фронтенд, ставить залежності без dev-пакетів,
синхронізує файли (не чіпаючи `.env` і `storage/`), а на сервері виконує
міграції, кеші й `check:launch`.

Перший запуск бази — окремо, бо `migrate --force` порожню базу лише
створить структуру:

```bash
ssh користувач@хост "cd /var/www/…/tekhpark_app && php8.4 artisan db:seed --force"
```

Сиди наливають каталог, категорії, тексти й статті. **Демо-відгуки в них
позначені `demo = true` і на сайт не потрапляють**, рейтинги обнулені.

## 8. Два крон-рядки

Без них не працюють Telegram-сповіщення і нагадування клієнтам:

```
* * * * * cd /var/www/…/tekhpark_app && php8.4 artisan queue:work --stop-when-empty --max-time=50
* * * * * cd /var/www/…/tekhpark_app && php8.4 artisan schedule:run >> /dev/null 2>&1
```

## 9. Перед відкриттям для Google

```bash
php artisan check:launch
```

Червоне означає «не вмикати індексацію». Найчастіші блокери на цьому етапі:
демо-телефони й адреси філій у базі (замінити в адмінці), `SITE_NOINDEX=true`,
словниковий пароль адмінки.

Коли все зелене — зняти `SITE_NOINDEX`, скинути кеш конфігу
(`php artisan config:cache`) і перевірити, що `/robots.txt` більше не
закриває сайт, а `/sitemap.xml` віддає адреси з бойовим доменом.

## 10. Після відкриття індексації

Перший тиждень, по порядку:

1. **Google Search Console** — ресурс типу «Домен», підтвердження TXT-записом
   у DNS (у коді нічого міняти не треба). Надіслати `https://домен/sitemap.xml`.
   Через «Перевірку URL» попросити проіндексувати головну і 3–5 ключових
   категорій — решта підтягнеться через sitemap.
2. **Google Business Profile** — окремий профіль на кожну філію з реальною
   адресою, телефоном і годинами роботи, ті самі, що на сайті. Категорія
   «Прокат інструментів». Підтвердження поштою або відео — від кількох днів.
3. **Bing Webmaster Tools** — імпорт із Search Console за одну кнопку.
4. **Аналітика** — у GTM перевірити в режимі попереднього перегляду, що GA4
   бачить перегляди; позначити подією відправку бронювання і заявки на дзвінок.
5. **Перші відгуки** — попросити клієнтів з перших оренд лишити відгук у
   Google Maps. Не купувати й не писати самим: за це профіль блокують.

Чого чекати: перші сторінки у видачі — за 1–2 тижні, стабільний трафік
з категорій — за 2–3 місяці. Раніше Search Console покаже лише покази.

## Що сказати локальній сесії Claude Code

Скопіюйте цей текст першим повідомленням:

> Проєкт `bur-rental` у цьому репозиторії, гілка `claude/service-deployment-fvcr4g`.
> Треба задеплоїти на хостинг за інструкцією `deploy/LOCAL-DEPLOY.md`.
> Мій SSH: `користувач@хост`, папка застосунку `/var/www/…/tekhpark_app`, PHP `php8.4`,
> домен `https://tekhpark.com.ua`. Спершу переконайся, що `ssh` проходить і тести зелені,
> потім `./deploy/deploy.sh`, у кінці `check:launch` і скажи, що лишилось
> червоним. `.env` на сервері я вже поклав — не перезаписуй його.
