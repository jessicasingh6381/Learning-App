<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CalendarEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        $calendar = $this->route('calendar');

        return $calendar && $this->user()->can('update', $calendar);
    }

    public function rules(): array
    {
        return [
            'event_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:event_date'],
            'event_type' => ['required', Rule::in([
                'holiday', 'break', 'teacher_workday', 'staff_development', 'weather_closure',
                'tenant_day_off', 'district_closure', 'instructional_makeup_day',
                'instructional_override', 'other',
            ])],
            'name' => ['required', 'string', 'max:255'],
            'instructional_effect' => ['required', Rule::in(['non_instructional', 'instructional', 'informational'])],
            'status' => ['sometimes', Rule::in(['active', 'archived'])],
            'notes' => ['nullable', 'string', 'max:5000'],
            'source_reference' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
