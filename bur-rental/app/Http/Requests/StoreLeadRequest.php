<?php

namespace App\Http\Requests;

use App\Rules\UkrainianPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeadRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['callback', 'b2b', 'contact', 'notify'])],
            'name' => ['nullable', 'string', 'max:120'],
            'phone' => ['required_unless:kind,notify', 'nullable', 'string', new UkrainianPhone],
            'email' => ['required_if:kind,b2b', 'nullable', 'email', 'max:160'],
            'company' => ['required_if:kind,b2b', 'nullable', 'string', 'max:160'],
            'edrpou' => ['nullable', 'digits:8'],
            'context' => ['nullable', 'string', 'max:200'],
            'message' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
