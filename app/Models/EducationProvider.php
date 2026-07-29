<?php

namespace App\Models;

use App\Models\Concerns\HasAcademicVisibility;
use Illuminate\Database\Eloquent\Model;

class EducationProvider extends Model
{
    use HasAcademicVisibility;

    protected $fillable = [
        'name', 'short_name', 'provider_type', 'state_or_region', 'country_code',
        'website_url', 'status', 'notes',
    ];
}
