<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReplacementBundleItem extends Model
{
    protected $fillable = ['bundle_id', 'original_ingredient_id', 'replacement_ingredient_id'];

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(ReplacementBundle::class);
    }

    public function originalIngredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'original_ingredient_id');
    }

    public function replacementIngredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'replacement_ingredient_id');
    }
}
