<?php

namespace App\Models;

use App\Models\Concerns\HasAcademicVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    use HasAcademicVisibility;

    protected $fillable = ['name', 'code', 'description', 'sort_order', 'status'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }
}
