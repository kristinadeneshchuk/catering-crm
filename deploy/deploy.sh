#!/usr/bin/env bash
#
# Розкатка CRM на сервері.
#
#   cd /path/to/crm && git pull origin main && bash deploy/deploy.sh
#
# Скрипт ідемпотентний: можна ганяти скільки завгодно разів.
# Код він НЕ тягне — git pull робить викликаюча сторона (workflow або людина),
# щоб цей файл встиг оновитися до свого ж запуску.
#
# Змінні оточення:
#   PHP_BIN       — шлях до php      (типово: php)
#   COMPOSER_BIN  — шлях до composer (типово: composer)
#   SKIP_MIGRATE=1  — не виконувати міграції
#   SKIP_COMPOSER=1 — не чіпати vendor/

set -euo pipefail

PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"

cd "$(dirname "$0")/.."
APP_DIR="$(pwd)"

step() { printf '\n\033[1;36m▸ %s\033[0m\n' "$1"; }

step "Деплой у $APP_DIR ($(git rev-parse --short HEAD))"

if [ ! -f .env ]; then
    echo "✗ .env не знайдено — деплой зупинено" >&2
    exit 1
fi

# Технічна пауза: сайт віддає 503 замість напівзібраної збірки.
# secret дає можливість зайти й подивитися на процес живими очима.
step "Maintenance mode"
"$PHP_BIN" artisan down --render="errors::503" --retry=15 --secret="deploying" || true

# Що б далі не сталося — сайт має піднятися.
trap '"$PHP_BIN" artisan up || true' EXIT

if [ "${SKIP_COMPOSER:-0}" != "1" ]; then
    step "composer install (без dev-залежностей)"
    "$COMPOSER_BIN" install --no-interaction --prefer-dist --no-dev --optimize-autoloader
fi

# public/build лежить у git — vite на сервері не потрібен.
step "storage:link"
"$PHP_BIN" artisan storage:link || true

if [ "${SKIP_MIGRATE:-0}" != "1" ]; then
    step "Міграції"
    "$PHP_BIN" artisan migrate --force
fi

step "Перебудова кешів"
"$PHP_BIN" artisan config:clear
"$PHP_BIN" artisan view:clear
"$PHP_BIN" artisan cache:clear || true
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan event:cache
"$PHP_BIN" artisan view:cache
# route:cache свідомо не робимо: у routes/web.php є closure-роути,
# їх Laravel серіалізувати не вміє і команда падає.

step "Перезапуск черги"
# Воркери підхоплять новий код: старі процеси гасяться після поточної джоби.
"$PHP_BIN" artisan queue:restart

step "Фільтр перевірок"
"$PHP_BIN" artisan about --only=environment || true

trap - EXIT
step "Maintenance off"
"$PHP_BIN" artisan up

step "Готово: $(git log -1 --pretty='%h %s')"
