<?php

namespace App\Http\Requests;

use App\Domain\Calendars\CalendarProfileCompatibility;
use App\Http\Requests\Concerns\ValidatesAcademicOwnership;
use App\Models\AcademicYearConfiguration;
use App\Models\CalendarProfile;
use App\Models\SchoolYear;
use App\Rules\ValidStatusTransition;
use App\Services\PermissionService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AcademicYearConfigurationRequest extends FormRequest
{
    use ValidatesAcademicOwnership;

    public function authorize(): bool
    {
        return app(PermissionService::class)->allows('academic-config.manage');
    }

    public function rules(): array
    {
        return [
            'school_year_id' => ['required', Rule::exists('school_years', 'id')->where('tenant_id', app(TenantContext::class)->tenantId())],
            'education_provider_id' => ['nullable', $this->visibleExists('education_providers')],
            'calendar_profile_id' => ['nullable', $this->visibleExists('calendar_profiles')],
            'standards_framework_id' => ['nullable', $this->visibleExists('standards_frameworks')],
            'curriculum_package_id' => ['nullable', $this->visibleExists('curriculum_packages')],
            'status' => [
                'required',
                Rule::in(['draft', 'active', 'closed', 'archived']),
                new ValidStatusTransition(
                    AcademicYearConfiguration::query()
                        ->where('school_year_id', $this->integer('school_year_id'))
                        ->value('status'),
                    [
                        'draft' => ['active', 'archived'],
                        'active' => ['closed', 'archived'],
                        'closed' => ['archived'],
                        'archived' => [],
                    ],
                ),
            ],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $schoolYear = SchoolYear::query()->find($this->integer('school_year_id'));
                $calendar = CalendarProfile::query()->find($this->integer('calendar_profile_id'));

                if ($schoolYear && $calendar && ! app(CalendarProfileCompatibility::class)->supports(
                    $calendar,
                    $schoolYear,
                    $this->integer('education_provider_id') ?: null,
                )) {
                    $validator->errors()->add(
                        'calendar_profile_id',
                        'Select a draft or active calendar profile that covers the full school year and matches the configured provider.',
                    );
                }
            },
        ];
    }
}
