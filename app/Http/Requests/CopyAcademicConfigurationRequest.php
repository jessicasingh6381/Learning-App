<?php

namespace App\Http\Requests;

use App\Services\PermissionService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CopyAcademicConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(PermissionService::class)->allows('academic-config.manage');
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $schoolYear = Rule::exists('school_years', 'id')->where('tenant_id', $tenantId);

        return [
            'source_school_year_id' => ['required', 'different:target_school_year_id', $schoolYear],
            'target_school_year_id' => ['required', $schoolYear],
        ];
    }
}
