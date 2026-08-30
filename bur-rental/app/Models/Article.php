<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Стаття блогу.
 *
 * Текст зберігається в markdown: його однаково зручно писати в адмінці й
 * читати в сидах, і він не дає авторові засмітити сторінку чужою розміткою.
 */
class Article extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['published' => 'bool', 'published_at' => 'date'];
    }

    /** Чернетки видно тільки в адмінці — так само, як із товарами. */
    protected static function booted(): void
    {
        static::addGlobalScope('published', fn (Builder $query) => $query->where('published', true));
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function kit(): BelongsTo
    {
        return $this->belongsTo(Kit::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Готовий HTML статті.
     *
     * `html_input: strip` навмисно: автор пише текст, а не верстку. Вставлений
     * із Word блок розмітки поламав би сторінку, а вставлений скрипт — довіру.
     */
    public function getHtmlAttribute(): string
    {
        return Str::markdown($this->body, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    /** Скільки читати. Рахуємо по 900 знаків на хвилину — темп читання з екрана. */
    public function getReadingMinutesAttribute(): int
    {
        return max(1, (int) round(mb_strlen($this->body) / 900));
    }
}
