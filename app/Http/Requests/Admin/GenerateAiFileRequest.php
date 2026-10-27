<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class GenerateAiFileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare input before validation.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('instgram_item_id') && $this->has('instagram_item_id')) {
            $this->merge(['instgram_item_id' => $this->input('instagram_item_id')]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'whats_item_id' => ['nullable', 'integer', 'exists:whats_items,id'],
            'instgram_item_id' => ['nullable', 'integer', 'exists:instagram_items,id'],
            'messenger_account_id' => ['nullable', 'integer', 'exists:messenger_accounts,id'],
            'telegram_account_id' => ['nullable', 'integer', 'exists:telegram_accounts,id'],
            'tiktok_account_id' => ['nullable', 'integer', 'exists:tiktok_accounts,id'],
            'groups' => ['required', 'array', 'min:1'],
            'groups.*.name' => ['required', 'string', 'max:255'],
            'groups.*.data' => ['required', 'array', 'min:1'],
            'groups.*.data.*' => ['required', 'array', 'min:1'],
            'groups.*.data.*.*.lable' => ['required', 'string', 'max:255'],
            'groups.*.data.*.*.value' => ['nullable'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $accountKeys = [
                'whats_item_id',
                'instgram_item_id',
                'messenger_account_id',
                'telegram_account_id',
                'tiktok_account_id',
            ];

            $provided = array_filter(
                $accountKeys,
                fn ($key) => $this->filled($key)
            );

            if (count($provided) === 0) {
                $validator->errors()->add(
                    'account_id',
                    'Exactly one account ID is required: whats_item_id, instgram_item_id, messenger_account_id, telegram_account_id, or tiktok_account_id.'
                );
            } elseif (count($provided) > 1) {
                $validator->errors()->add(
                    'account_id',
                    'Only one account ID must be provided.'
                );
            }
        });
    }

    /**
     * Custom validation messages.
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
            'groups.*.data.min' => 'Each group must contain at least one row.',
            'groups.*.data.*.min' => 'Each row must contain at least one field.',
            'groups.*.data.*.*.lable.required' => 'Each field must have a lable.',
        ];
    }
}
