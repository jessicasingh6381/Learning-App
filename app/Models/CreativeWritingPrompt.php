<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreativeWritingPrompt extends Model
{
    use BelongsToTenant;

    protected $fillable = ['title','prompt','include_hints','category','minimum_grade_level_id','maximum_grade_level_id','active','source_type','source_key','created_by_user_id'];
    protected function casts(): array { return ['include_hints'=>'array','active'=>'boolean']; }
    public function minimumGradeLevel(): BelongsTo { return $this->belongsTo(GradeLevel::class, 'minimum_grade_level_id'); }
    public function maximumGradeLevel(): BelongsTo { return $this->belongsTo(GradeLevel::class, 'maximum_grade_level_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function entries(): HasMany { return $this->hasMany(CreativeWritingEntry::class); }
}
