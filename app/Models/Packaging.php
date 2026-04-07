<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Project;

class Packaging extends Model
{
    protected $fillable = ['name', 'unit', 'stock', 'price', 'project'];

    public function projectData(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Project::class, 'project', 'slug');
    }
}