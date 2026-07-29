<?php

namespace App\Http\Requests;

use App\Domain\SchoolYears\InstructionalSchedule;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SchoolYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('school-years.manage');
    }

    public function rules(): array
    {
        $schoolYear = $this->route('school_year');
        $id = $schoolYear?->id;

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('school_years')->where('tenant_id', app(TenantContext::class)->tenantId())->ignore($id)],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'timezone' => ['required', 'timezone'],
            'status' => ['required', Rule::in(['draft', 'active', 'closed', 'archived'])],
            'instructional_week_type' => ['required', Rule::in(InstructionalSchedule::TYPES)],
            'instructional_weekdays' => ['required', 'array', 'min:1', 'max:7'],
            'instructional_weekdays.*' => [
                'bail',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_int($value)) {
                        $fail('Each instructional weekday must be an integer ISO weekday.');
                    }
                },
                'integer',
                'between:1,7',
                'distinct:strict',
            ],
            'instructional_day_target' => ['nullable', 'integer', 'between:1,366'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $weekdays = $this->input('instructional_weekdays');

        if (
            is_array($weekdays)
            && array_is_list($weekdays)
            && collect($weekdays)->every(
                static fn (mixed $weekday): bool => is_int($weekday),
            )
        ) {
            $this->merge([
                'instructional_weekdays' => InstructionalSchedule::normalize($weekdays),
            ]);
        }
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $weekdays = $this->input('instructional_weekdays');
            $hasWeekdayErrors = collect($validator->errors()->keys())->contains(
                static fn (string $key): bool => str_starts_with(
                    $key,
                    'instructional_weekdays',
                ),
            );

            if (is_array($weekdays) && ! array_is_list($weekdays)) {
                $validator->errors()->add(
                    'instructional_weekdays',
                    'Instructional weekdays must be a list of ISO weekday numbers.',
                );
                $hasWeekdayErrors = true;
            }

            if (
                ! $validator->errors()->has('instructional_week_type')
                && ! $hasWeekdayErrors
                && is_array($weekdays)
                && ! InstructionalSchedule::matchesPreset(
                    (string) $this->input('instructional_week_type'),
                    $weekdays,
                )
            ) {
                $validator->errors()->add(
                    'instructional_week_type',
                    'The selected weekdays do not match this preset. Choose Custom schedule or restore the preset weekdays.',
                );
            }

            $schoolYear = $this->route('school_year');
            $requestedStatus = (string) $this->input('status');

            if (! $schoolYear && ! in_array($requestedStatus, ['draft', 'active'], true)) {
                $validator->errors()->add('status', 'A new school year must begin as draft or active.');

                return;
            }

            if (! $schoolYear) {
                return;
            }

            $transitions = [
                'draft' => ['draft', 'active', 'archived'],
                'active' => ['active', 'closed', 'archived'],
                'closed' => ['closed', 'archived'],
                'archived' => ['archived'],
            ];

            if (! in_array($requestedStatus, $transitions[$schoolYear->status] ?? [], true)) {
                $validator->errors()->add('status', "A {$schoolYear->status} school year cannot transition to {$requestedStatus}.");
            }
        }];
    }
}
