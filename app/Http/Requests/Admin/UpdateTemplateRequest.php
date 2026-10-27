<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTemplateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'groups' => ['sometimes', 'required', 'array', 'min:1'],
            'groups.*.name' => ['required', 'string', 'max:255'],
            'groups.*.data' => ['required', 'array', 'min:1'],
            'groups.*.data.*' => ['required', 'array', 'min:1'],
            'groups.*.data.*.*.lable' => ['required', 'string', 'max:255'],
            'groups.*.data.*.*.value' => ['required', 'string', Rule::in(StoreTemplateRequest::ALLOWED_INPUT_TYPES)],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'groups.required' => 'The groups array is required.',
            'groups.min' => 'At least one group is required.',
            'groups.*.name.required' => 'Each group must have a name.',
            'groups.*.data.required' => 'Each group must have data rows.',
            'groups.*.data.min' => 'Each group must contain at least one row of fields.',
            'groups.*.data.*.min' => 'Each row must contain at least one field.',
            'groups.*.data.*.*.lable.required' => 'Each field must have a lable.',
            'groups.*.data.*.*.value.required' => 'Each field must have a value type.',
            'groups.*.data.*.*.value.in' => 'The field value type is invalid. Allowed types: '.implode(', ', StoreTemplateRequest::ALLOWED_INPUT_TYPES),
        ];
    }
}
