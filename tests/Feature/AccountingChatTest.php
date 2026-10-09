<?php

namespace Tests\Feature;

use App\Jobs\ClassifyAccountingDocument;
use App\Jobs\ReadKitchenInvoice;
use App\Models\Account;
use App\Models\AccountingItem;
use App\Models\StockDocument;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Services\Accounting\AccountingDesk;
use App\Services\Ai\OpsAi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsStockTestSchema;
use Tests\Support\CourierShiftScenario;
use Tests\TestCase;

/**
 * Група «Бухгалтерія»: накладні додаються кнопкою, квитанції закривають
 * накладні або (непрофільні) йдуть лише власнику.
 *
 * Модель підмінена: перевіряємо, що бот робить з її відповіддю.
 */
class AccountingChatTest extends TestCase
{
    use CourierShiftScenario;
    use BuildsStockTestSchema;

    private const GROUP = '-1003972130729';

    private Supplier $atabekov;

    private Account $fop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCourierWorld();
        $this->buildStockSchema();

        config()->set('services.anthropic.key', 'test-key');
        config()->set('services.telegram.accounting_chat_id', self::GROUP);
        config()->set('services.telegram.accounting_approvers', '300');
        config()->set('services.telegram.owner_chat_id', '100,101'); // двоє власників

        DB::table('warehouses')->insert(['name' => 'Продукти']);
        $this->atabekov = Supplier::create(['name' => 'Атабеков (Сергій Столичний)', 'inn' => '3011223344']);
        $this->fop = Account::create(['name' => 'ФОП Настечина', 'type' => 'online', 'balance' => 100000]);
        Account::create(['name' => 'Готівка', 'type' => 'cash', 'balance' => 5000, 'is_default' => true]);
    }

    private function fakeAi(array $answer): void
    {
        $this->app->bind(OpsAi::class, fn () => new class($answer) extends OpsAi
        {
            public function __construct(private array $answer)
            {
            }

            protected function send(string $system, string $prompt, array $schema, array $imagePaths): array
            {
                return ['text' => json_encode($this->answer), 'usage' => ['input' => 1000, 'output' => 100, 'cache_read' => 0]];
            }
        });
    }

    private function photo(int $messageId, string $uniqueId = 'u1', ?string $group = null, int $from = 300): void
    {
        $this->postJson('/webhooks/telegram-bot', [
            'update_id' => $messageId,
            'message'   => array_filter([
                'message_id'     => $messageId,
                'chat'           => ['id' => (int) self::GROUP, 'type' => 'supergroup', 'title' => 'Бухгалтерія'],
                'from'           => ['id' => $from],
                'media_group_id' => $group,
                'photo'          => [['file_id' => 'small', 'file_unique_id' => 's'.$uniqueId], ['file_id' => 'big', 'file_unique_id' => $uniqueId]],
            ]),
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'sec'])->assertOk();
    }

    private function press(string $data, int $from = 300, string $chat = self::GROUP): void
    {
        $this->postJson('/webhooks/telegram-bot', [
            'update_id'      => 999,
            'callback_query' => [
                'id'      => 'cb1',
                'from'    => ['id' => $from],
                'data'    => $data,
                'message' => ['message_id' => 42, 'chat' => ['id' => (int) $chat], 'text' => 'пропозиція'],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'sec'])->assertOk();
    }

    private function classify(array $answer, int $messageId = 10, string $uniqueId = 'u1'): AccountingItem
    {
        $this->photo($messageId, $uniqueId);
        $this->fakeAi($answer);
        $item = AccountingItem::latest('id')->first();
        app()->call([new ClassifyAccountingDocument($item->id), 'handle']);

        return $item->fresh();
    }

    private function invoiceAnswer(array $invoice = []): array
    {
        return ['kind' => 'invoice', 'confidence' => 'high', 'payment' => null, 'invoice' => array_merge([
            'supplier_name' => 'Атабеков', 'supplier_code' => '3011223344', 'number' => 'XB00127160',
            'date' => '2026-09-25', 'total' => 30351.56, 'lines' => 49,
        ], $invoice)];
    }

    private function paymentAnswer(array $payment = []): array
    {
        return ['kind' => 'payment', 'confidence' => 'high', 'invoice' => null, 'payment' => array_merge([
            'amount' => 30351.56, 'date' => '2026-09-26', 'payer_name' => 'ФОП Настечина Вікторія', 'payer_bank' => 'ПриватБанк',
            'recipient_name' => 'ФОП Атабеков', 'recipient_code' => '3011223344', 'recipient_iban' => null,
            'purpose' => 'Оплата за продукти згідно накладної', 'invoice_number' => null,
            'is_supplier_payment' => true, 'category_guess' => 'постачальник',
        ], $payment)];
    }

    private function draft(float $sum, string $date = '2026-09-25', bool $paid = false): StockDocument
    {
        return StockDocument::create([
            'type' => 'receipt', 'status' => StockDocument::STATUS_DRAFT, 'source' => 'ai', 'warehouse_id' => 1,
            'supplier_id' => $this->atabekov->id, 'operation_date' => $date, 'total_sum' => $sum, 'is_paid' => $paid,
        ]);
    }

    private function sent(string $chat, string $needle): bool
    {
        return Http::recorded(fn ($r) => str_contains($r->url(), 'sendMessage')
            && (string) $r['chat_id'] === $chat && str_contains((string) $r['text'], $needle))->isNotEmpty();
    }

    public function test_photo_in_accounting_group_is_stored_and_classified_later(): void
    {
        $this->photo(10);

        $item = AccountingItem::first();
        $this->assertSame(self::GROUP, $item->chat_id);
        $this->assertCount(1, $item->files);
        Queue::assertPushed(ClassifyAccountingDocument::class);
    }

    public function test_album_becomes_one_document(): void
    {
        $this->photo(10, 'p1', 'alb');
        $this->photo(11, 'p2', 'alb');

        $this->assertSame(1, AccountingItem::count());
        $this->assertCount(2, AccountingItem::first()->files);
        Queue::assertPushed(ClassifyAccountingDocument::class, 1);
    }

    public function test_invoice_is_proposed_with_add_button_as_reply(): void
    {
        $item = $this->classify($this->invoiceAnswer());

        $this->assertSame('proposed', $item->status);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && str_contains((string) $r['text'], 'Схоже на накладну')
            && ($r['reply_parameters']['message_id'] ?? null) === 10
            && $r['reply_markup']['inline_keyboard'][0][0]['callback_data'] === "acc:add:{$item->id}");
    }

    public function test_add_button_runs_the_usual_invoice_reader_into_the_group(): void
    {
        $item = $this->classify($this->invoiceAnswer());

        $this->press("acc:add:{$item->id}");

        $this->assertSame('added', $item->fresh()->status);
        Queue::assertPushed(ReadKitchenInvoice::class);
        $this->assertSame($item->paths(), Cache::get('accounting-invoice:'.$item->id));
    }

    public function test_stranger_cannot_add(): void
    {
        $item = $this->classify($this->invoiceAnswer());

        $this->press("acc:add:{$item->id}", from: 999);

        $this->assertSame('proposed', $item->fresh()->status);
        Queue::assertNotPushed(ReadKitchenInvoice::class);
    }

    public function test_invoice_already_in_crm_is_reported_as_duplicate(): void
    {
        $existing = $this->draft(30351.56);
        $existing->update(['invoice_number' => 'XB00127160']);

        $item = $this->classify($this->invoiceAnswer());

        $this->assertSame('duplicate', $item->status);
        $this->assertSame($existing->id, $item->stock_document_id);
        $this->assertTrue($this->sent(self::GROUP, 'вже є в CRM'));
    }

    public function test_same_photo_twice_is_skipped(): void
    {
        $this->classify($this->invoiceAnswer(), 10, 'same');
        $second = $this->classify($this->invoiceAnswer(['number' => 'інший']), 11, 'same');

        $this->assertSame('duplicate', $second->status);
    }

    public function test_other_documents_are_ignored_silently(): void
    {
        $item = $this->classify(['kind' => 'other', 'invoice' => null, 'payment' => null, 'confidence' => 'high']);

        $this->assertSame('ignored', $item->status);
        $this->assertFalse($this->sent(self::GROUP, ''));
    }

    public function test_supplier_payment_marks_matching_invoice_paid_from_payer_account(): void
    {
        $doc = $this->draft(30351.56);

        $item = $this->classify($this->paymentAnswer());
        $this->assertTrue($this->sent(self::GROUP, 'Підходить накладна'));

        $this->press("acc:pay:{$item->id}:{$doc->id}");

        $doc->refresh();
        $this->assertTrue((bool) $doc->is_paid);
        $this->assertSame($this->fop->id, (int) $doc->account_id);
        $this->assertSame('paid', $item->fresh()->status);
        // Чернетка — каса не рухається, поки накладну не проведуть.
        $this->assertSame(0, Transaction::count());
    }

    public function test_paying_a_posted_invoice_creates_purchase_expense(): void
    {
        $doc = $this->draft(30351.56);
        $doc->update(['status' => StockDocument::STATUS_POSTED]);

        $item = $this->classify($this->paymentAnswer());
        $this->press("acc:pay:{$item->id}:{$doc->id}");

        $this->assertSame('Закупівля', Transaction::first()?->category);
        $this->assertEqualsWithDelta(30351.56, (float) Transaction::first()->amount, 0.01);
    }

    public function test_unknown_payer_account_is_asked_with_buttons(): void
    {
        $doc = $this->draft(30351.56);
        $item = $this->classify($this->paymentAnswer(['payer_name' => 'Хтось Невідомий']));

        $this->press("acc:pay:{$item->id}:{$doc->id}");

        $this->assertFalse((bool) $doc->fresh()->is_paid);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'editMessageText')
            && str_contains(json_encode($r['reply_markup']), "acc:pay:{$item->id}:{$doc->id}:{$this->fop->id}"));

        $this->press("acc:pay:{$item->id}:{$doc->id}:{$this->fop->id}");
        $this->assertTrue((bool) $doc->fresh()->is_paid);
    }

    public function test_non_profile_payment_goes_only_to_owner_and_is_recorded(): void
    {
        $item = $this->classify($this->paymentAnswer([
            'recipient_name' => 'ТОВ Київенерго', 'recipient_code' => '99999999',
            'purpose' => 'Оплата за електроенергію', 'is_supplier_payment' => false, 'category_guess' => 'комуналка',
            'amount' => 4200,
        ]));

        $this->assertSame('needs_owner', $item->status);
        $this->assertTrue($this->sent('100', 'Непрофільна оплата'));
        $this->assertFalse($this->sent(self::GROUP, 'Непрофільна'));

        // Кнопку власника не може натиснути ніхто інший.
        $this->press("acc:cat:{$item->id}:1", from: 300, chat: '100');
        $this->assertSame(0, Transaction::count());

        $this->press("acc:cat:{$item->id}:1", from: 100, chat: '100');

        $t = Transaction::first();
        $this->assertSame('Комунальні', $t->category);
        $this->assertSame('expense', $t->type);
        $this->assertEqualsWithDelta(4200, (float) $t->amount, 0.01);
        $this->assertSame($this->fop->id, (int) $t->account_id);
        $this->assertSame('recorded', $item->fresh()->status);

        // Другий власник теж отримав питання — після відповіді першого кнопки в нього зникли.
        $this->assertTrue($this->sent('101', 'Непрофільна оплата'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'editMessageText') && (string) $r['chat_id'] === '101'
            && str_contains((string) $r['text'], 'Комунальні'));

        // А натиснути вдруге — нічого не задвоїть.
        $this->press("acc:cat:{$item->id}:2", from: 101, chat: '101');
        $this->assertSame(1, Transaction::count());
    }

    public function test_marking_not_supplier_payment_hands_over_to_owner(): void
    {
        $this->draft(30351.56);
        $item = $this->classify($this->paymentAnswer());

        $this->press("acc:np:{$item->id}");

        $this->assertSame('needs_owner', $item->fresh()->status);
        $this->assertTrue($this->sent('100', 'Непрофільна оплата'));
    }

    public function test_supplier_matcher_finds_fop_by_surname(): void
    {
        $ivankov = Supplier::create(['name' => 'ФОП Іванков І.А. (риба)', 'inn' => '2632518412']);

        $matcher = app(\App\Services\Ai\SupplierMatcher::class);

        $this->assertSame($ivankov->id, $matcher->supplierId('Фізична особа підприємець Іванков Ігор Андрійович'));
        $this->assertSame($this->fop->id, $matcher->accountId('ФОП Настечина Вікторія Олександрівна'));
        $this->assertNull($matcher->accountId('Зовсім Інша Людина'));
    }

    public function test_pdf_files_are_accepted(): void
    {
        $this->postJson('/webhooks/telegram-bot', [
            'update_id' => 1,
            'message'   => [
                'message_id' => 20,
                'chat'       => ['id' => (int) self::GROUP, 'type' => 'supergroup'],
                'from'       => ['id' => 300],
                'document'   => ['file_id' => 'pdf1', 'file_unique_id' => 'updf', 'mime_type' => 'application/pdf'],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'sec'])->assertOk();

        $this->assertSame('application/pdf', AccountingItem::first()->files[0]['mime']);
    }

    public function test_plain_text_in_accounting_group_is_ignored(): void
    {
        $this->postJson('/webhooks/telegram-bot', [
            'update_id' => 1,
            'message'   => ['message_id' => 21, 'chat' => ['id' => (int) self::GROUP, 'type' => 'supergroup'], 'from' => ['id' => 300], 'text' => 'привіт'],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'sec'])->assertOk();

        $this->assertSame(0, AccountingItem::count());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'sendMessage'));
    }

    public function test_signed_bank_pdf_is_unwrapped_before_reading(): void
    {
        // monobank: PDF усередині PKCS#7 (SEQUENCE … OCTET STRING з PDF).
        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\n%%EOF\n";
        $octet = "\x04\x81".chr(strlen($pdf)).$pdf;
        $signed = "\x30\x82\x01\x00\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x07\x02".$octet."\x31\x00SIGNATURE";

        $this->assertSame($pdf, \App\Support\Documents\SignedPdf::unwrap($signed));
        $this->assertNull(\App\Support\Documents\SignedPdf::unwrap($pdf)); // чистий PDF не чіпаємо

        // Заглушки з setUp перехоплюють запити першими — ставимо свої з нуля.
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            'api.telegram.org/*/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'documents/file_25.pdf']]),
            'api.telegram.org/file/*'     => Http::response($signed),
            'api.telegram.org/*'          => Http::response(['ok' => true, 'result' => ['message_id' => 42]]),
        ]);

        $this->postJson('/webhooks/telegram-bot', [
            'update_id' => 1,
            'message'   => [
                'message_id' => 20,
                'chat'       => ['id' => (int) self::GROUP, 'type' => 'supergroup'],
                'from'       => ['id' => 300],
                'document'   => ['file_id' => 'pdf1', 'file_unique_id' => 'updf', 'mime_type' => 'application/pdf'],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'sec'])->assertOk();

        $file = AccountingItem::first()->files[0];
        $this->assertStringEndsWith('.unsigned.pdf', $file['path']);
        $this->assertSame($pdf, \Illuminate\Support\Facades\Storage::disk('local')->get($file['path']));
        $this->assertNotEmpty($file['signed']); // оригінал з підписом лишається
    }

    public function test_payment_finds_invoice_by_number_in_purpose_and_fills_supplier(): void
    {
        // Накладна без постачальника (у бланку його немає) і з іншою сумою —
        // але номер з призначення платежу збігається, навіть кирилицею.
        $doc = $this->draft(24000.00, '2026-10-07');
        $doc->update(['supplier_id' => null, 'invoice_number' => 'XB00128602']);
        $this->atabekov->update(['inn' => null]);

        $item = $this->classify($this->paymentAnswer([
            'amount' => 24767.95, 'recipient_name' => 'АТАБЕКОВ АРСЕН РАФАЕЛОВИЧ', 'recipient_code' => '2634313872',
            'purpose' => 'Оплата товару №ХВ00128602 від 07.10.2026', 'invoice_number' => null,
        ]));

        $this->assertTrue($this->sent(self::GROUP, '№XB00128602'));
        $this->assertSame('2634313872', $this->atabekov->fresh()->inn); // код запамʼятали

        $this->press("acc:pay:{$item->id}:{$doc->id}");

        $doc->refresh();
        $this->assertTrue((bool) $doc->is_paid);
        $this->assertSame($this->atabekov->id, (int) $doc->supplier_id);
    }

    public function test_invoice_numbers_compare_across_cyrillic_and_latin(): void
    {
        $n = \App\Support\Documents\InvoiceNumber::class;

        $this->assertSame('XB00128602', $n::normalize('№ ХВ00128602'));
        $this->assertSame('XB00128602', $n::fromPurpose('Оплата товару №ХВ00128602 від 07.10.2026'));
        $this->assertNull($n::fromPurpose('Оплата за електроенергію'));
    }
}
