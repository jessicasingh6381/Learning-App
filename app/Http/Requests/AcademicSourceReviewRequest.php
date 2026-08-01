<?php

namespace App\Http\Requests;

use App\Domain\AcademicSources\AcademicSourceOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AcademicSourceReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('review', $this->route('source'));
    }

    public function rules(): array
    {
        $source = $this->route('source');
        $allowed = AcademicSourceOptions::REVIEW_TRANSITIONS[$source->review_status] ?? [];

        return ['review_status' => ['required', Rule::in($allowed)]];
    }
}
