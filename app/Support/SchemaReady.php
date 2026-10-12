<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Чи вже є таблиця — з кешем на один запит.
 *
 * Навіщо. Бейджі в меню рахуються на КОЖНІЙ сторінці адмінки. Якщо бейдж
 * читає нову таблицю, а міграція ще не пройшла, падає вся бічна панель. Таке
 * вікно буває завжди:
 *   — на horenko між git pull і migrate у crm-deploy (composer + npm build,
 *     хвилина-дві);
 *   — на afood і shinshin, куди деплой робить лише git pull, без міграцій.
 */
final class SchemaReady
{
    /** @var array<string, bool> */
    private static array $cache = [];

    public static function has(string $table): bool
    {
        return self::$cache[$table] ??= self::check($table);
    }

    private static function check(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Чи вже є колонка (нова міграція могла ще не пройти). */
    public static function hasColumn(string $table, string $column): bool
    {
        return self::$cache[$table.'.'.$column] ??= (function () use ($table, $column) {
            try {
                return Schema::hasColumn($table, $column);
            } catch (\Throwable) {
                return false;
            }
        })();
    }

    /** Для тестів: схема між ними створюється заново. */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
