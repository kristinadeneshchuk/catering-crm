<?php

namespace App\Services\Accounting;

use App\Jobs\ClassifyAccountingDocument;
use App\Jobs\ReadKitchenInvoice;
use App\Models\Account;
use App\Models\AccountingItem;
use App\Models\StockDocument;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Services\Ai\SupplierMatcher;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Support\Documents\InvoiceNumber;
use App\Support\Documents\SignedPdf;

/**
 * Група «Бухгалтерія»: накладні й квитанції про оплату.
 *
 *  фото/PDF → ШІ визначає тип →
 *   накладна   — «📦 Схоже на накладну … [➕ Додати] [✖️ Не накладна]»;
 *                «Додати» запускає звичайний розбір (ReadKitchenInvoice) зі
 *                звітом і питаннями про вагу штуки прямо в групі;
 *   квитанція  — оплата постачальнику: пропонує накладну, яку вона закриває,
 *                і позначає її оплаченою з рахунку платника;
 *                непрофільна оплата: питає лише власника в особистих, куди
 *                її занести, і записує витрату в касу;
 *   інше       — мовчки пропускає.
 *
 * Натискати кнопки в групі можуть лише accountingApproverIds(), відповідати на
 * непрофільні — лише власники (accounting_owner_ids, за замовчуванням — усі власники).
 */
class AccountingDesk
{
    public const CALLBACK = 'acc';

    /** Куди заносити непрофільні оплати (кнопки власнику). */
    public const CATEGORIES = [
        'Оренда', 'Комунальні', 'Обладнання та ремонт', 'Реклама',
        'Податки та банк', 'Господарські', 'Інше',
    ];

    public function __construct(private TelegramService $telegram, private SupplierMatcher $matcher)
    {
    }

    public static function chatId(): string
    {
        return (string) config('services.telegram.accounting_chat_id');
    }

    public static function isAccountingChat(string $chatId): bool
    {
        return self::chatId() !== '' && $chatId === self::chatId();
    }

    /** Фото, зображення файлом або PDF. */
    public static function fileOf(array $message): ?array
    {
        if (! empty($message['photo'])) {
            $p = end($message['photo']);

            return ['id' => (string) $p['file_id'], 'unique_id' => (string) ($p['file_unique_id'] ?? ''), 'mime' => 'image/jpeg'];
        }

        $mime = (string) ($message['document']['mime_type'] ?? '');

        if (str_starts_with($mime, 'image/') || $mime === 'application/pdf') {
            return [
                'id'        => (string) $message['document']['file_id'],
                'unique_id' => (string) ($message['document']['file_unique_id'] ?? ''),
                'mime'      => $mime,
            ];
        }

        return null;
    }

    /**
     * Документ у групі: зберегти файл, альбом — в один запис, розбір — один раз
     * із затримкою, коли дійдуть усі сторінки.
     */
    public function collect(array $message): void
    {
        $file = self::fileOf($message);

        if (! $file) {
            return;
        }

        $path = $this->telegram->downloadFile($file['id'], 'ops/accounting/'.now()->format('Y-m'));

        if (! $path) {
            return;
        }

        // Telegram шле PDF з розширенням .pdf, а фото без опису — тримаємо тип.
        $entry = ['path' => $path, 'unique_id' => $file['unique_id'], 'mime' => $file['mime']];

        // Квитанція з КЕП (monobank тощо): PDF загорнутий у підписаний контейнер.
        // Модель читає лише чистий PDF — вирізаємо його, оригінал лишаємо поруч.
        if ($file['mime'] === 'application/pdf'
            && ($pdf = SignedPdf::unwrap((string) Storage::disk('local')->get($path)))) {
            $plain = preg_replace('/\.pdf$/i', '', $path).'.unsigned.pdf';
            Storage::disk('local')->put($plain, $pdf);
            $entry = ['path' => $plain, 'signed' => $path] + $entry;
        }
        $chatId = (string) $message['chat']['id'];
        $group = $message['media_group_id'] ?? null;

        $item = $group
            ? AccountingItem::where('chat_id', $chatId)->where('media_group_id', $group)->first()
            : null;

        if ($item) {
            $item->update(['files' => array_merge($item->files ?? [], [$entry])]);

            return;
        }

        $item = AccountingItem::create([
            'chat_id'        => $chatId,
            'message_id'     => (int) $message['message_id'],
            'media_group_id' => $group,
            'files'          => [$entry],
        ]);

        ClassifyAccountingDocument::dispatch($item->id)
            ->delay(now()->addSeconds($group ? (int) config('services.telegram.kitchen_album_wait', 25) : 2));
    }

    /** Після ШІ: що робимо з документом. */
    public function route(AccountingItem $item, ?array $data): void
    {
        if ($data === null) {
            $item->update(['status' => 'failed']);
            $this->reply($item, '🧾 Не вдалося прочитати документ. Перевірте фото — або внесіть вручну.');

            return;
        }

        $item->update(['kind' => $data['kind'], 'extracted' => $data]);

        // Те саме фото вже надсилали — Telegram дає йому той самий unique_id.
        if ($earlier = $this->sameFile($item)) {
            $item->update(['status' => 'duplicate']);
            $this->reply($item, '🔁 Цей документ уже надсилали '.$earlier->created_at->format('d.m H:i').' — пропускаю.');

            return;
        }

        match ($data['kind']) {
            AccountingItem::KIND_INVOICE => $this->proposeInvoice($item, $data['invoice'] ?? []),
            AccountingItem::KIND_PAYMENT => $this->proposePayment($item, $data['payment'] ?? []),
            default                      => $item->update(['status' => 'ignored']),
        };
    }

    // ── Накладні ────────────────────────────────────────────────────────────

    private function proposeInvoice(AccountingItem $item, array $inv): void
    {
        $supplierId = $this->matcher->supplierId($inv['supplier_name'] ?? null, $inv['supplier_code'] ?? null);
        $this->rememberCode($supplierId, $inv['supplier_code'] ?? null);

        if ($existing = $this->existingInvoice($supplierId, $inv)) {
            $item->update(['status' => 'duplicate', 'stock_document_id' => $existing->id]);
            $this->reply($item, '🔁 Ця накладна вже є в CRM ('
                .($existing->isDraft() ? 'чернетка' : 'проведена').', '
                .number_format((float) $existing->total_sum, 2, ',', ' ').' ₴): '.$this->url($existing));

            return;
        }

        $supplier = $supplierId ? Supplier::find($supplierId)?->name : ($inv['supplier_name'] ?? null);

        $text = "📦 <b>Схоже на накладну</b>\n"
            .e($supplier ?: 'постачальник не вказаний')
            .(! empty($inv['number']) ? ' · №'.e($inv['number']) : '')
            .(! empty($inv['date']) ? ' від '.$this->day($inv['date']) : '')
            ."\n"
            .(isset($inv['total']) ? 'Сума: '.$this->money($inv['total']).' ₴' : '')
            .(! empty($inv['lines']) ? ' · рядків: '.(int) $inv['lines'] : '');

        $id = $this->reply($item, $text, [[
            ['text' => '➕ Додати в CRM', 'callback_data' => self::CALLBACK.":add:{$item->id}"],
            ['text' => '✖️ Не накладна', 'callback_data' => self::CALLBACK.":no:{$item->id}"],
        ]]);

        $item->update(['status' => 'proposed', 'bot_message_id' => $id]);
    }

    /** Та сама накладна вже внесена: номер з бланка або постачальник + дата + сума. */
    private function existingInvoice(?int $supplierId, array $inv): ?StockDocument
    {
        $query = StockDocument::where('type', 'receipt');
        $number = (string) InvoiceNumber::normalize($inv['number'] ?? null);
        $date = $this->date($inv['date'] ?? null);

        if ($number !== '') {
            // Без постачальника номер сам по собі нічого не гарантує («№ 71» буває
            // в різних) — тоді ще й сума має збігтися.
            $byNumber = (clone $query)->where('invoice_number', $number)
                ->when($supplierId,
                    fn ($q) => $q->where('supplier_id', $supplierId),
                    fn ($q) => isset($inv['total'])
                        ? $q->whereBetween('total_sum', [(float) $inv['total'] - 1, (float) $inv['total'] + 1])
                        : $q->whereRaw('1 = 0'))
                ->first();

            if ($byNumber) {
                return $byNumber;
            }
        }

        if (! $supplierId || ! $date || ! isset($inv['total'])) {
            return null;
        }

        return (clone $query)->where('supplier_id', $supplierId)
            ->where(fn ($q) => $q->whereDate('invoice_date', $date)->orWhereDate('operation_date', $date))
            ->whereBetween('total_sum', [(float) $inv['total'] - 1, (float) $inv['total'] + 1])
            ->first();
    }

    // ── Оплати ──────────────────────────────────────────────────────────────

    private function proposePayment(AccountingItem $item, array $pay): void
    {
        $amount = (float) ($pay['amount'] ?? 0);
        $supplierId = $this->matcher->supplierId($pay['recipient_name'] ?? null, $pay['recipient_code'] ?? null);
        $this->rememberCode($supplierId, $pay['recipient_code'] ?? null);
        $number = $this->paymentInvoiceNumber($pay);

        // Не постачальник і ШІ теж каже «не закупівля» — питаємо лише власника.
        if (! $supplierId && empty($pay['is_supplier_payment'])) {
            $this->toOwner($item);

            return;
        }

        $candidates = $this->unpaid($supplierId, $amount, $pay['date'] ?? null, $number);

        if ($candidates->isEmpty()) {
            // Схоже на постачальника, але відкритих накладних немає.
            $this->toOwner($item, $supplierId
                ? 'Неоплачених накладних цього постачальника в CRM немає.'
                : 'Схоже на оплату постачальнику, якого немає в CRM.');

            return;
        }

        $best = $candidates->first();
        // «Оплата товару №ХВ00128602» — номер з призначення точніший за суму.
        $byNumber = $number && InvoiceNumber::normalize($best->invoice_number) === $number;
        $exact = $byNumber || abs((float) $best->total_sum - $amount) <= 1;
        $account = $this->matcher->accountId($pay['payer_name'] ?? null);

        $text = "💳 <b>Оплата ".$this->money($amount).' ₴</b> → '
            .e($supplierId ? Supplier::find($supplierId)->name : ($pay['recipient_name'] ?? '?'))
            .(! empty($pay['date']) ? ' · '.$this->day($pay['date']) : '')
            ."\nЗ рахунку: ".e($account ? Account::find($account)->name : 'не впізнав — спитаю')
            ."\n\n".($exact
                ? 'Підходить накладна '.($byNumber ? '№'.e($best->invoice_number).' ' : '').$this->docLine($best)
                : 'Точної суми серед неоплачених немає. Яку накладну закриває ця оплата?');

        $keyboard = $exact
            ? [[
                ['text' => '✅ Позначити оплаченою', 'callback_data' => self::CALLBACK.":pay:{$item->id}:{$best->id}"],
                ['text' => '📄 Інша', 'callback_data' => self::CALLBACK.":other:{$item->id}"],
            ]]
            : $this->docButtons($item, $candidates);

        $keyboard[] = [['text' => '✖️ Не оплата постачальнику', 'callback_data' => self::CALLBACK.":np:{$item->id}"]];

        $id = $this->reply($item, $text, $keyboard);
        $item->update(['status' => 'proposed', 'bot_message_id' => $id]);
    }

    /**
     * Неоплачені накладні: спершу та, чий номер у призначенні платежу, далі —
     * постачальника, інакше будь-чиї з близькою сумою.
     */
    private function unpaid(?int $supplierId, float $amount, ?string $date, ?string $number = null)
    {
        $until = ($this->date($date) ?? now())->copy()->addDays(3);

        $byNumber = $number
            ? StockDocument::where('type', 'receipt')->where('is_paid', false)
                ->where('invoice_number', $number)->with('supplier')->get()
            : collect();

        return $byNumber->concat($this->unpaidBySupplier($supplierId, $amount, $until)
            ->reject(fn ($d) => $byNumber->contains('id', $d->id)))
            ->take(6)->values();
    }

    private function unpaidBySupplier(?int $supplierId, float $amount, Carbon $until)
    {
        return StockDocument::where('type', 'receipt')
            ->where('is_paid', false)
            ->whereDate('operation_date', '<=', $until)
            ->when($supplierId,
                fn ($q) => $q->where('supplier_id', $supplierId),
                fn ($q) => $q->whereBetween('total_sum', [$amount - 1, $amount + 1]))
            ->orderByRaw('ABS(total_sum - ?)', [$amount])
            ->orderByDesc('operation_date')
            ->limit(6)
            ->with('supplier')
            ->get();
    }

    // ── Непрофільні оплати — лише власнику ─────────────────────────────────

    private function toOwner(AccountingItem $item, ?string $note = null): void
    {
        $item->update(['status' => 'needs_owner']);

        $pay = $item->extracted['payment'] ?? [];
        $account = $this->matcher->accountId($pay['payer_name'] ?? null);

        $text = "💸 <b>Непрофільна оплата</b> з «Бухгалтерії»\n"
            .$this->money($pay['amount'] ?? 0).' ₴'.(! empty($pay['date']) ? ' · '.$this->day($pay['date']) : '')."\n"
            .'Отримувач: '.e($pay['recipient_name'] ?? '—')."\n"
            .'Призначення: '.e($pay['purpose'] ?? '—')."\n"
            .'Платник: '.e($pay['payer_name'] ?? '—').($account ? ' → «'.e(Account::find($account)->name).'»' : '')."\n"
            .(! empty($pay['category_guess']) ? 'ШІ думає: '.e($pay['category_guess'])."\n" : '')
            .($note ? e($note)."\n" : '')
            ."\nКуди заносити?";

        $buttons = collect(self::CATEGORIES)->map(fn ($c, $i) => [
            'text' => $c, 'callback_data' => self::CALLBACK.":cat:{$item->id}:{$i}",
        ])->chunk(2)->map(fn ($row) => $row->values()->all())->values()->all();
        $buttons[] = [['text' => '🚫 Не заносити', 'callback_data' => self::CALLBACK.":skip:{$item->id}"]];

        // Обом власникам; хто перший відповів — у другого кнопки зникнуть.
        $sent = [];
        foreach ($this->ownerIds() as $owner) {
            if ($id = $this->telegram->sendMessage($owner, $text, $buttons)) {
                $sent[] = [$owner, $id];
            }
        }

        $item->update(['extracted' => ($item->extracted ?? []) + ['owner_ask' => ['text' => $text, 'messages' => $sent]]]);
    }

    /** Хто вирішує непрофільні оплати: окремий список або всі власники. */
    private function ownerIds(): array
    {
        $ids = array_filter(array_map('trim', explode(',', (string) config('services.telegram.accounting_owner_ids'))));

        return $ids !== [] ? array_values($ids) : $this->telegram->ownerChatIds();
    }

    /** Другий власник бачить, що питання вже закрите, і не натисне вдруге. */
    private function closeOwnerAsk(AccountingItem $item, string $fromId, string $label): void
    {
        $ask = $item->fresh()->extracted['owner_ask'] ?? null;

        foreach ($ask['messages'] ?? [] as [$chat, $id]) {
            if ((string) $chat !== $fromId) {
                $this->telegram->editMessage((string) $chat, (int) $id, $ask['text']."\n\n".$label);
            }
        }
    }

    // ── Кнопки ──────────────────────────────────────────────────────────────

    /** @return array{answer: string, text?: string, keyboard?: array|null} */
    public function button(string $fromId, string $data): array
    {
        $parts = explode(':', $data);
        [, $action, $itemId] = array_pad($parts, 3, null);
        $item = AccountingItem::find((int) $itemId);

        if (! $item) {
            return ['answer' => 'Документ не знайдено.'];
        }

        $ownerOnly = in_array($action, ['cat', 'cacc', 'skip'], true);
        $allowed = $ownerOnly
            ? $fromId !== '' && in_array($fromId, $this->ownerIds(), true)
            : in_array($fromId, $this->telegram->accountingApproverIds(), true);

        if (! $allowed) {
            return ['answer' => $ownerOnly ? 'Тільки для власника.' : 'Немає прав на цю дію.'];
        }

        return match ($action) {
            'add'   => $this->add($item, $fromId),
            'no'    => $this->decide($item, $fromId, 'rejected', '✖️ Не накладна'),
            'pay'   => $this->pay($item, $fromId, (int) ($parts[3] ?? 0), isset($parts[4]) ? (int) $parts[4] : null),
            'other' => $this->otherDocs($item),
            'np'    => $this->notSupplier($item, $fromId),
            'cat'   => $this->record($item, $fromId, (int) ($parts[3] ?? -1), isset($parts[4]) ? (int) $parts[4] : null),
            'skip'  => $this->skip($item, $fromId),
            default => ['answer' => 'Невідома дія.'],
        };
    }

    private function add(AccountingItem $item, string $fromId): array
    {
        if ($item->status !== 'proposed') {
            return ['answer' => 'Уже вирішено.'];
        }

        $item->update(['status' => 'added', 'decided_by' => $fromId, 'decided_at' => now()]);

        // Той самий розбір, що й для накладних в особистих: чернетка, звіт,
        // кнопки постачальника й питання про вагу — тут же, у групі.
        $key = 'accounting-invoice:'.$item->id;
        Cache::put($key, $item->paths(), now()->addMinutes(30));
        ReadKitchenInvoice::dispatch($key, $item->chat_id, (int) $item->message_id, $item->chat_id, $item->id);

        return ['answer' => 'Додаю, звіт за хвилину.', 'text' => '✅ Додаю в CRM — звіт нижче'];
    }

    private function skip(AccountingItem $item, string $fromId): array
    {
        $result = $this->decide($item, $fromId, 'skipped', '🚫 Не заносимо');

        if (isset($result['text'])) {
            $this->closeOwnerAsk($item, $fromId, $result['text']);
        }

        return $result;
    }

    private function decide(AccountingItem $item, string $fromId, string $status, string $label): array
    {
        if (! in_array($item->status, ['proposed', 'needs_owner'], true)) {
            return ['answer' => 'Уже вирішено.'];
        }

        $item->update(['status' => $status, 'decided_by' => $fromId, 'decided_at' => now()]);

        return ['answer' => 'Готово.', 'text' => $label];
    }

    private function pay(AccountingItem $item, string $fromId, int $documentId, ?int $accountId): array
    {
        if ($item->status !== 'proposed') {
            return ['answer' => 'Уже вирішено.'];
        }

        $document = StockDocument::where('type', 'receipt')->find($documentId);

        if (! $document || $document->is_paid) {
            return ['answer' => 'Цю накладну вже оплачено або її немає.'];
        }

        $pay = $item->extracted['payment'] ?? [];
        $accountId ??= $this->matcher->accountId($pay['payer_name'] ?? null);

        // З якого рахунку — не впізнали з квитанції: питаємо кнопками.
        if (! $accountId) {
            $buttons = Account::orderBy('id')->get()->map(fn ($a) => [
                'text' => $a->name, 'callback_data' => self::CALLBACK.":pay:{$item->id}:{$document->id}:{$a->id}",
            ])->chunk(2)->map(fn ($row) => $row->values()->all())->values()->all();

            return ['answer' => 'З якого рахунку платили?', 'keep' => true, 'keyboard' => $buttons];
        }

        DB::transaction(function () use ($item, $document, $accountId, $fromId) {
            // is_paid → syncTransaction: проведена накладна одразу дає витрату
            // «Закупівля», чернетка — коли її проведуть.
            $document->update(array_filter([
                'is_paid'     => true,
                'account_id'  => $accountId,
                // У бланку постачальника часто немає — з квитанції він відомий.
                'supplier_id' => $document->supplier_id ?: $this->matcher->supplierId(
                    $item->extracted['payment']['recipient_name'] ?? null,
                    $item->extracted['payment']['recipient_code'] ?? null,
                ),
            ], fn ($v) => $v !== null));
            $item->update([
                'status' => 'paid', 'stock_document_id' => $document->id,
                'decided_by' => $fromId, 'decided_at' => now(),
            ]);
        });

        $amount = (float) ($pay['amount'] ?? 0);
        $diff = abs((float) $document->total_sum - $amount) > 1
            ? "\n⚠️ Сума оплати ".$this->money($amount).' ₴ ≠ сумі накладної '.$this->money($document->total_sum).' ₴'
            : '';

        return [
            'answer' => 'Позначив оплаченою.',
            'text'   => '✅ Оплачено: накладна '.$this->docLine($document->fresh('supplier'))
                .' з «'.e(Account::find($accountId)->name).'»'
                .($document->isDraft() ? "\nВитрата зʼявиться в касі, коли накладну проведуть." : '')
                .$diff,
        ];
    }

    private function otherDocs(AccountingItem $item): array
    {
        $pay = $item->extracted['payment'] ?? [];
        $supplierId = $this->matcher->supplierId($pay['recipient_name'] ?? null, $pay['recipient_code'] ?? null);
        $docs = $this->unpaid($supplierId, (float) ($pay['amount'] ?? 0), $pay['date'] ?? null, $this->paymentInvoiceNumber($pay));

        $keyboard = $this->docButtons($item, $docs);
        $keyboard[] = [['text' => '✖️ Не оплата постачальнику', 'callback_data' => self::CALLBACK.":np:{$item->id}"]];

        return ['answer' => 'Оберіть накладну.', 'keep' => true, 'keyboard' => $keyboard];
    }

    private function notSupplier(AccountingItem $item, string $fromId): array
    {
        if ($item->status !== 'proposed') {
            return ['answer' => 'Уже вирішено.'];
        }

        $item->update(['decided_by' => $fromId, 'decided_at' => now()]);
        $this->toOwner($item);

        return ['answer' => 'Передав власнику.', 'text' => '↪️ Не оплата постачальнику — питання власнику'];
    }

    private function record(AccountingItem $item, string $fromId, int $category, ?int $accountId): array
    {
        if ($item->status !== 'needs_owner' || ! isset(self::CATEGORIES[$category])) {
            return ['answer' => 'Уже вирішено.'];
        }

        $pay = $item->extracted['payment'] ?? [];
        $accountId ??= $this->matcher->accountId($pay['payer_name'] ?? null);

        if (! $accountId) {
            $buttons = Account::orderBy('id')->get()->map(fn ($a) => [
                'text' => $a->name, 'callback_data' => self::CALLBACK.":cat:{$item->id}:{$category}:{$a->id}",
            ])->chunk(2)->map(fn ($row) => $row->values()->all())->values()->all();

            return ['answer' => 'З якого рахунку?', 'keep' => true, 'keyboard' => $buttons];
        }

        $transaction = DB::transaction(function () use ($item, $pay, $category, $accountId, $fromId) {
            $transaction = Transaction::create([
                'type'       => 'expense',
                'category'   => self::CATEGORIES[$category],
                'amount'     => round((float) ($pay['amount'] ?? 0), 2),
                'account_id' => $accountId,
                'date'       => $this->date($pay['date'] ?? null) ?? now(),
                'comment'    => trim('Квитанція з «Бухгалтерії»: '.($pay['recipient_name'] ?? '').'. '.($pay['purpose'] ?? '')),
            ]);

            $item->update([
                'status' => 'recorded', 'transaction_id' => $transaction->id,
                'decided_by' => $fromId, 'decided_at' => now(),
            ]);

            return $transaction;
        });

        $label = '✅ Витрата «'.self::CATEGORIES[$category].'» '.$this->money($transaction->amount)
            .' ₴ з «'.e(Account::find($accountId)->name).'»';
        $this->closeOwnerAsk($item, $fromId, $label);

        return ['answer' => 'Записав.', 'text' => $label];
    }

    // ── Дрібниці ────────────────────────────────────────────────────────────

    private function paymentInvoiceNumber(array $pay): ?string
    {
        return InvoiceNumber::normalize($pay['invoice_number'] ?? null) ?? InvoiceNumber::fromPurpose($pay['purpose'] ?? null);
    }

    /** Постачальника впізнали за прізвищем, а в документі є його код — запамʼятати. */
    private function rememberCode(?int $supplierId, ?string $code): void
    {
        $code = preg_replace('/\D+/', '', (string) $code);

        if ($supplierId && strlen($code) >= 8) {
            Supplier::whereKey($supplierId)->where(fn ($q) => $q->whereNull('inn')->orWhere('inn', ''))->update(['inn' => $code]);
        }
    }

    private function docButtons(AccountingItem $item, $docs): array
    {
        return $docs->map(fn (StockDocument $d) => [[
            'text'          => mb_substr(($d->supplier?->name ?? '—'), 0, 18).' · '.$d->operation_date->format('d.m')
                .' · '.$this->money($d->total_sum).' ₴',
            'callback_data' => self::CALLBACK.":pay:{$item->id}:{$d->id}",
        ]])->values()->all();
    }

    private function docLine(StockDocument $d): string
    {
        return 'від '.$d->operation_date->format('d.m').' на '.$this->money($d->total_sum).' ₴'
            .($d->supplier ? ' ('.e($d->supplier->name).')' : '')
            .($d->isDraft() ? ' — чернетка' : '');
    }

    private function sameFile(AccountingItem $item): ?AccountingItem
    {
        $ids = array_filter(array_column($item->files ?? [], 'unique_id'));

        if ($ids === []) {
            return null;
        }

        return AccountingItem::where('id', '<', $item->id)
            ->whereNotIn('status', ['ignored', 'failed', 'rejected'])
            ->get()
            ->first(fn (AccountingItem $other) => array_intersect($ids, array_column($other->files ?? [], 'unique_id')) !== []);
    }

    private function reply(AccountingItem $item, string $text, ?array $keyboard = null): ?int
    {
        return $this->telegram->sendMessage($item->chat_id, $text, $keyboard, (int) $item->message_id);
    }

    private function url(StockDocument $d): string
    {
        return \App\Filament\Resources\StockDocumentResource::getUrl('edit', ['record' => $d]);
    }

    private function date(?string $raw): ?Carbon
    {
        try {
            return $raw ? Carbon::parse($raw)->startOfDay() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function day(?string $raw): string
    {
        return $this->date($raw)?->format('d.m.Y') ?? e((string) $raw);
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, ',', ' ');
    }
}
