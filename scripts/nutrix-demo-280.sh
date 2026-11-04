#!/usr/bin/env bash
# Перебудова демо-бази NutriX (~280 раціонів у будній день) з нуля.
#
#   scripts/nutrix-demo-280.sh
#
# Потрібно: локальний MySQL (root без пароля або MYSQL_ARGS), файл .env.nutrix280
# з DB_DATABASE=nutrix_demo_280. Дати рахуються від сьогодні — перезапуск «омолоджує» демо.
# Відео-базу nutrix_demo скрипт не чіпає.
set -euo pipefail

cd "$(dirname "$0")/.."
DB="${NUTRIX_DB:-nutrix_demo_280}"
MYSQL_ARGS="${MYSQL_ARGS:--uroot}"

if [ ! -f .env.nutrix280 ]; then
    echo "Немає .env.nutrix280 (див. docs/demo.md)" >&2
    exit 1
fi
if ! grep -q "^DB_DATABASE=${DB}$" .env.nutrix280; then
    echo ".env.nutrix280 має вказувати DB_DATABASE=${DB}" >&2
    exit 1
fi
if [ "$DB" = "nutrix_demo" ] || [ "$DB" = "ufitcrm" ]; then
    echo "Відмова: ${DB} — не демо-280 база" >&2
    exit 1
fi

echo "→ ${DB}: перестворюю базу"
mysql $MYSQL_ARGS -e "DROP DATABASE IF EXISTS \`${DB}\`; CREATE DATABASE \`${DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

echo "→ схема + довідники (database/demo/nutrix_base.sql.gz)"
gunzip -c database/demo/nutrix_base.sql.gz | mysql $MYSQL_ARGS "$DB"

echo "→ нові міграції"
APP_ENV=nutrix280 php artisan migrate --force --no-interaction

echo "→ сідер"
time APP_ENV=nutrix280 php -d memory_limit=2G artisan db:seed --class=NutrixDemo280Seeder --force --no-interaction

APP_ENV=nutrix280 php artisan optimize:clear >/dev/null
echo "Готово: APP_ENV=nutrix280 php artisan serve --port=8093"
