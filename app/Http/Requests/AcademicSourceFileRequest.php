<?php

namespace App\Http\Requests;

use App\Rules\AcademicSourceUpload;
use Illuminate\Foundation\Http\FormRequest;

class AcademicSourceFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $source = $this->route('source');

        return $source->source_kind === 'upload' && $this->user()->can('update', $source);
    }

    public function rules(): array
    {
        return ['source_file' => ['required', new AcademicSourceUpload]];
    }
}
