<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBookingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:20'],
            'items.*.from' => ['required', 'date'],
            'items.*.to' => ['required', 'date', 'after_or_equal:items.*.from'],
            'extras' => ['array'],
            'extras.*.extra_id' => ['required', 'exists:extras,id'],
            'extras.*.qty' => ['required', 'integer', 'min:1', 'max:50'],

            'branch_id' => ['required', 'exists:branches,id'],
            'client_type' => ['required', Rule::in(['person', 'company'])],
            'phone' => ['required', 'string', 'regex:/^\+?380\s?\d{2}\s?\d{3}\s?\d{2}\s?\d{2}$/'],
            'name' => ['required_if:client_type,person', 'nullable', 'string', 'max:120'],
            'company' => ['required_if:client_type,company', 'nullable', 'string', 'max:160'],
            'edrpou' => ['required_if:client_type,company', 'nullable', 'digits:8'],
            'email' => ['required_if:client_type,company', 'nullable', 'email', 'max:160'],

            'fulfilment' => ['required', Rule::in(['self', 'delivery'])],
            'delivery_zone_id' => ['required_if:fulfilment,delivery', 'nullable', 'exists:delivery_zones,id'],
            'address' => ['required_if:fulfilment,delivery', 'nullable', 'string', 'max:250'],
            'payment' => ['required', Rule::in(['card', 'cash', 'invoice', 'parts'])],
            'deposit_way' => ['required', Rule::in(['card-hold', 'cash', 'none'])],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Введіть номер у форматі +380 XX XXX XX XX',
            'edrpou.digits' => 'ЄДРПОУ складається з 8 цифр',
            'items.required' => 'Кошик порожній — додайте інструмент',
        ];
    }
}
