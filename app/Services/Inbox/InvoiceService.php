<?php

namespace App\Services\Inbox;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Виставлення рахунку на замовлення.
 *
 * Реквізити копіюємо в рахунок знімком: це документ, який клієнт уже отримав,
 * і він не має мінятись заднім числом, якщо у бренду поміняється ФОП чи банк.
 *
 * Нумерація — «порядковий/замовлення» (напр. 517/1499), порядковий рахується
 * окремо для кожного бренду.
 */
class InvoiceService
{
    /**
     * Рахунок для замовлення. Повторний виклик віддає наявний — щоб менеджер,
     * натиснувши кнопку двічі, не наплодив клієнту різних номерів на одне й те
     * саме замовлення.
     */
    public function forOrder(Order $order, bool $fresh = false): Invoice
    {
        if (! $fresh) {
            $existing = Invoice::where('order_id', $order->id)->latest('id')->first();

            if ($existing) {
                return $existing;
            }
        }

        $project = Project::where('slug', $order->project)->first();

        if (! $project) {
            throw ValidationException::withMessages([
                'project' => "Замовлення #{$order->id} не прив'язане до бренду — нема чиїх реквізитів ставити в рахунок.",
            ]);
        }

        $requisites = $this->requisites($project);

        if (empty($requisites['iban']) || empty($requisites['recipient_name'])) {
            throw ValidationException::withMessages([
                'requisites' => "У бренду «{$project->name}» не заповнені реквізити (отримувач та IBAN). Довідники → Проєкти.",
            ]);
        }

        $amount = (float) ($order->final_price ?? $order->total_price);

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => "Замовлення #{$order->id} має нульову суму — рахунок виставляти нема на що.",
            ]);
        }

        return DB::transaction(function () use ($order, $project, $requisites, $amount) {
            // Блокуємо рядки бренду, щоб два менеджери одночасно не отримали
            // однаковий порядковий номер.
            $sequence = (int) Invoice::where('project', $project->slug)
                ->lockForUpdate()
                ->max('sequence') + 1;

            $number = "{$sequence}/{$order->id}";
            $issued = now();

            return Invoice::create([
                'number'     => $number,
                'sequence'   => $sequence,
                'order_id'   => $order->id,
                'client_id'  => $order->client_id,
                'project'    => $project->slug,
                'issued_on'  => $issued->toDateString(),
                'amount'     => $amount,
                'purpose'    => $this->purpose($project, $number, $issued),
                'requisites' => $requisites,
                'token'      => Str::random(48),
                'created_by' => auth()->id(),
            ]);
        });
    }

    /** @return array<string, ?string> */
    protected function requisites(Project $project): array
    {
        return [
            'recipient_name' => $project->recipient_name,
            'iban'           => $project->iban,
            'tax_id'         => $project->tax_id,
            'bank_name'      => $project->bank_name,
            'mfo'            => $project->mfo,
        ];
    }

    /**
     * Призначення платежу. У банку воно має бути самодостатнім: за ним
     * бухгалтерія зіставляє надходження з рахунком.
     */
    protected function purpose(Project $project, string $number, \DateTimeInterface $issued): string
    {
        $template = $project->payment_purpose
            ?: 'оплата згідно рахунку №:number від :date за доставку здорового харчування';

        return strtr($template, [
            ':number' => $number,
            ':date'   => $issued->format('d.m.Y'),
            ':brand'  => $project->name,
        ]);
    }
}
