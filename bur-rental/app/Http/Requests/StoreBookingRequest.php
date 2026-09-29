<?php

namespace App\Http\Requests;

use App\Models\DeliveryZone;
use App\Models\Product;
use App\Rules\UkrainianPhone;
use App\Services\RentalPricing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'phone' => ['required', 'string', new UkrainianPhone],
            'name' => ['required_if:client_type,person', 'nullable', 'string', 'max:120'],
            'company' => ['required_if:client_type,company', 'nullable', 'string', 'max:160'],
            'edrpou' => ['required_if:client_type,company', 'nullable', 'digits:8'],
            'email' => ['required_if:client_type,company', 'nullable', 'email', 'max:160'],

            'fulfilment' => ['required', Rule::in(['self', 'delivery'])],
            // Рядок-правило «Важка техніка» зоною не є: вибравши його, будь-який
            // кошик отримував би безкоштовну доставку.
            'delivery_zone_id' => [
                'required_if:fulfilment,delivery', 'nullable',
                Rule::exists('delivery_zones', 'id')->whereNot('price_mode', DeliveryZone::INFO),
            ],
            'address' => ['required_if:fulfilment,delivery', 'nullable', 'string', 'max:250'],
            'payment' => ['required', Rule::in(['card', 'cash', 'invoice', 'parts'])],
            'deposit_way' => ['required', Rule::in(['card-hold', 'cash', 'none'])],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Важку техніку не видаємо самовивозом. Форма вже ховає цей варіант,
     * але кошик живе в localStorage, і запит можна зібрати руками — тому
     * останнє слово тут.
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->input('fulfilment') !== 'self') {
                return;
            }

            $heavy = Product::whereIn('id', collect($this->input('items', []))->pluck('product_id'))
                ->where('weight_kg', '>=', RentalPricing::HEAVY_KG)
                ->pluck('name');

            if ($heavy->isNotEmpty()) {
                $validator->errors()->add(
                    'fulfilment',
                    'Техніку від '.RentalPricing::HEAVY_KG.' кг самовивозом не видаємо: '.$heavy->implode(', ')
                    .'. Оберіть доставку — привеземо з гідробортом.'
                );
            }
        }];
    }

    public function messages(): array
    {
        return [
            'edrpou.digits' => 'ЄДРПОУ складається з 8 цифр',
            'items.required' => 'Кошик порожній — додайте інструмент',
        ];
    }
}
