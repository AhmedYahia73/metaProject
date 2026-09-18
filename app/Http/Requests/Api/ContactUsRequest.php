<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ContactUsRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'f_name' => ['required', 'string', 'max:100'],
            'l_name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:25'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'message' => ['required', 'string', 'min:5', 'max:5000'],
        ];
    }

    /**
     * Get custom body parameter descriptions and examples for OpenAPI / Scramble documentation.
     *
     * @return array<string, array<string, string>>
     */
    public function bodyParameters(): array
    {
        return [
            'f_name' => [
                'description' => 'First name of the sender.',
                'example' => 'Ahmed',
            ],
            'l_name' => [
                'description' => 'Last name of the sender.',
                'example' => 'Yahia',
            ],
            'phone' => [
                'description' => 'Phone number of the sender.',
                'example' => '+201012345678',
            ],
            'email' => [
                'description' => 'Email address of the sender.',
                'example' => 'ahmed@example.com',
            ],
            'message' => [
                'description' => 'Contact message content.',
                'example' => 'Hello, I would like to inquire about your subscription packages.',
            ],
        ];
    }
}
