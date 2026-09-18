<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ApiLoginRequest extends FormRequest
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
            'email' => ['required_without_all:phone,login', 'nullable', 'string', 'email'],
            'phone' => ['required_without_all:email,login', 'nullable', 'string'],
            'login' => ['required_without_all:email,phone', 'nullable', 'string'],
            'password' => ['required', 'string'],
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
            'email' => [
                'description' => 'User email address (required if phone or login is not provided).',
                'example' => 'user@example.com',
            ],
            'phone' => [
                'description' => 'User phone number (required if email or login is not provided).',
                'example' => '01012345678',
            ],
            'login' => [
                'description' => 'Unified login identifier (email or phone, required if email or phone is not provided).',
                'example' => 'user@example.com',
            ],
            'password' => [
                'description' => 'User password.',
                'example' => 'password123',
            ],
        ];
    }
}
