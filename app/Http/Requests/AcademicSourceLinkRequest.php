<?php

namespace App\Http\Requests;

use App\Domain\AcademicSources\AcademicSourceOptions;
use App\Services\AcademicSourceLinkService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AcademicSourceLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('source'));
    }

    public function rules(): array
    {
        return [
            'link_type' => ['required', Rule::in(array_keys(AcademicSourceOptions::LINK_TYPES))],
            'link_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (! app(AcademicSourceLinkService::class)->resolves(
                    (string) $this->input('link_type'),
                    $this->integer('link_id'),
                )) {
                    $validator->errors()->add('link_id', 'The selected academic record is not available to this tenant.');
                }
            },
        ];
    }
}
