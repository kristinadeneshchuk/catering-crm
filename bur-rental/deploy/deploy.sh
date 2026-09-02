#!/usr/bin/env bash
#
# Деплой БУР на хостинг із SSH.
#
# Запускати з машини, де є доступ до сервера, — з локальної сесії Claude Code
# або руками. У хмарній пісочниці не працює: там закриті 22 і 21 порти.
#
#   BUR_SSH=user@host BUR_PATH=/var/www/user/data/bur_app ./deploy/deploy.sh
#
# Що робить:ганяє тести, збирає збірку начисто, синхронізує файли, і вже на
# сервері виконує міграції, кеші й передстартову перевірку.
#
# Чого свідомо НЕ робить:
#   · не чіпає .env на сервері — там бойові паролі, і перезаписати їх файлом
#     з ноутбука означає покласти сайт;
#   · не чіпає storage/ — там завантаження й логи, які живуть тільки на сервері;
#   · не запускає db:seed на робочій базі — сиди створюють демо-дані.

set -euo pipefail

: "${BUR_SSH:?вкажіть BUR_SSH, напр. bur_new_hor__usr@hor-hosting.top}"
: "${BUR_PATH:?вкажіть BUR_PATH — папку застосунку на сервері}"

PHP_REMOTE="${BUR_PHP:-php}"          # на деяких хостингах це php8.4
SSH_PORT="${BUR_SSH_PORT:-22}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

cd "$ROOT"

say() { printf '\n\033[1m▸ %s\033[0m\n' "$1"; }

say "Тести"
php artisan test --compact

say "Збірка"
npm ci --silent
npm run build

# --no-dev: на сервері не потрібні ні PHPUnit, ні Pint. -o: пришвидшує автозавантаження.
composer install --no-dev --optimize-autoloader --no-interaction

say "Синхронізація на $BUR_SSH:$BUR_PATH"
rsync -az --delete --human-readable \
  -e "ssh -p $SSH_PORT" \
  --exclude '.git' \
  --exclude '.env' \
  --exclude 'node_modules' \
  --exclude 'storage/app/import' \
  --exclude 'storage/logs' \
  --exclude 'storage/framework/cache' \
  --exclude 'storage/framework/sessions' \
  --exclude 'storage/framework/views' \
  --exclude 'database/database.sqlite' \
  --exclude 'tests' \
  ./ "$BUR_SSH:$BUR_PATH/"

say "Міграції й кеші на сервері"
# shellcheck disable=SC2029
ssh -p "$SSH_PORT" "$BUR_SSH" "cd '$BUR_PATH' && \
  $PHP_REMOTE artisan down --render=errors::503 || true; \
  $PHP_REMOTE artisan migrate --force && \
  $PHP_REMOTE artisan search:reindex && \
  $PHP_REMOTE artisan config:cache && \
  $PHP_REMOTE artisan view:cache && \
  $PHP_REMOTE artisan event:cache && \
  $PHP_REMOTE artisan storage:link || true; \
  $PHP_REMOTE artisan up"

say "Передстартова перевірка"
# Ненульовий код тут означає «є блокери» — саме тому він не гаситься.
ssh -p "$SSH_PORT" "$BUR_SSH" "cd '$BUR_PATH' && $PHP_REMOTE artisan check:launch"

say "Готово"
echo "Не забудьте два крон-рядки на сервері:"
echo "  * * * * * cd $BUR_PATH && $PHP_REMOTE artisan queue:work --stop-when-empty --max-time=50"
echo "  * * * * * cd $BUR_PATH && $PHP_REMOTE artisan schedule:run >> /dev/null 2>&1"
