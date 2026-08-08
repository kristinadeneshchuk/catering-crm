<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeadRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['callback', 'b2b', 'contact', 'notify'])],
            'name' => ['nullable', 'string', 'max:120'],
            'phone' => ['required_unless:kind,notify', 'nullable', 'string', 'regex:/^\+?380\s?\d{2}\s?\d{3}\s?\d{2}\s?\d{2}$/'],
            'email' => ['required_if:kind,b2b', 'nullable', 'email', 'max:160'],
            'company' => ['required_if:kind,b2b', 'nullable', 'string', 'max:160'],
            'edrpou' => ['nullable', 'digits:8'],
            'context' => ['nullable', 'string', 'max:200'],
            'message' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return ['phone.regex' => 'Введіть номер у форматі +380 XX XXX XX XX'];
    }
}
