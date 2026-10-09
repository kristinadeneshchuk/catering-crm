<?php

namespace App\Services\Ai;

use App\Models\AccountingItem;
use App\Models\AiRun;

/**
 * Що за документ у «Бухгалтерії»: накладна, квитанція про оплату чи щось інше
 * (скрін, мем, графік). Одним запитом — тип і головні реквізити, без рядків
 * накладної: повний розбір позицій робить InvoiceReader, коли людина натисне
 * «Додати».
 */
class AccountingClassifier
{
    public function __construct(private OpsAi $ai)
    {
    }

    /** @return array<string, mixed>|null */
    public function read(AccountingItem $item): ?array
    {
        return $this->ai->ask(
            purpose: AiRun::PURPOSE_ACCOUNTING,
            system: $this->system(),
            prompt: 'Що це за документ? Сьогодні '.now()->format('d.m.Y').'. '
                .'Кілька сторінок — це один документ. Поверни JSON за схемою.',
            schema: $this->schema(),
            imagePaths: $item->paths(),
            subject: $item,
            meta: ['files' => count($item->files ?? [])],
        );
    }

    private function system(): string
    {
        return <<<'TXT'
        Ти розбираєш документи в бухгалтерському чаті кухні служби доставки харчування.

        Визнач `kind`:
        - invoice — видаткова/товарна накладна, товарний чек, рахунок постачальника з переліком товарів;
        - payment — квитанція/платіжка/скрін банку про переказ грошей (будь-кому);
        - other — усе інше: скріншоти переписок, графіки, меми, фото страв, таблиці.

        Для invoice заповни `invoice`, для payment — `payment`; інший блок — null.
        Бери лише те, що справді видно. Не вигадуй сум, дат і реквізитів. Числа — числами, кома → крапка.
        Дата — у форматі Y-m-d.

        Для payment:
        - `payer_name` — хто платив (ФОП/компанія/людина), `payer_bank` — банк платника;
        - `recipient_name`, `recipient_code` (ЄДРПОУ/ІПН), `recipient_iban` — кому;
        - `purpose` — призначення платежу дослівно;
        - `invoice_number` — номер накладної/рахунку з призначення («Оплата товару №ХВ00128602» → ХВ00128602), інакше null;
        - `is_supplier_payment` — true, якщо це оплата постачальнику продуктів, упаковки чи господарських товарів для кухні
          (за призначенням або отримувачем); false — оренда, комуналка, податки, реклама, зарплата, особисте тощо;
        - `category_guess` — коротко, що це за витрата («оренда», «комуналка», «реклама», «податок», «постачальник» …).
        TXT;
    }

    private function schema(): array
    {
        $str = ['type' => ['string', 'null']];
        $num = ['type' => ['number', 'null']];

        return [
            'type'       => 'object',
            'properties' => [
                'kind'    => ['type' => 'string', 'enum' => ['invoice', 'payment', 'other']],
                'invoice' => [
                    'type'       => ['object', 'null'],
                    'properties' => [
                        'supplier_name' => $str, 'supplier_code' => $str, 'number' => $str,
                        'date' => $str, 'total' => $num, 'lines' => ['type' => ['integer', 'null']],
                    ],
                    'required'             => ['supplier_name', 'supplier_code', 'number', 'date', 'total', 'lines'],
                    'additionalProperties' => false,
                ],
                'payment' => [
                    'type'       => ['object', 'null'],
                    'properties' => [
                        'amount' => $num, 'date' => $str, 'payer_name' => $str, 'payer_bank' => $str,
                        'recipient_name' => $str, 'recipient_code' => $str, 'recipient_iban' => $str,
                        'purpose' => $str, 'invoice_number' => $str,
                        'is_supplier_payment' => ['type' => 'boolean'], 'category_guess' => $str,
                    ],
                    'required'             => ['amount', 'date', 'payer_name', 'payer_bank', 'recipient_name',
                        'recipient_code', 'recipient_iban', 'purpose', 'invoice_number', 'is_supplier_payment', 'category_guess'],
                    'additionalProperties' => false,
                ],
                'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
            ],
            'required'             => ['kind', 'invoice', 'payment', 'confidence'],
            'additionalProperties' => false,
        ];
    }
}
