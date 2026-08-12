<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Order;
use App\Services\Inbox\InvoiceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsInboxTestSchema;
use Tests\TestCase;

/**
 * Рахунки на оплату.
 *
 * Головне правило: реквізити потрапляють у рахунок знімком. Рахунок — це
 * документ, який клієнт уже отримав, і зміна ФОП чи банку не має переписувати
 * його заднім числом.
 */
class InvoiceTest extends TestCase
{
    use BuildsInboxTestSchema;

    protected array $catalog;

    protected int $clientId;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.inbox.token', 'test-service-token');

        $this->buildInboxSchema();
        $this->catalog  = $this->seedCatalog(pricePerDay: 898);
        $this->clientId = $this->makeClient();

        $this->fillRequisites();
    }

    protected function fillRequisites(array $overrides = []): void
    {
        DB::table('projects')->where('slug', 'afood')->update(array_merge([
            'recipient_name' => 'ФОП Горенко Поліна Сергіївна',
            'iban'           => 'UA703220010000026005340086870',
            'tax_id'         => '3748801687',
            'bank_name'      => 'УНІВЕРСАЛ БАНК',
            'mfo'            => '322001',
        ], $overrides));
    }

    protected function makeOrder(array $attrs = []): Order
    {
        return Order::create(array_merge([
            'client_id'    => $this->clientId,
            'project'      => 'afood',
            'tariff_id'    => $this->catalog['tariff_id'],
            'calories'     => 1600,
            'duration'     => 5,
            'start_date'   => '2026-08-17',
            'end_date'     => '2026-08-21',
            'scale_factor' => 1.0,
        ], $attrs));
    }

    public function test_it_issues_an_invoice_with_a_snapshot_of_the_requisites(): void
    {
        $order   = $this->makeOrder();
        $invoice = app(InvoiceService::class)->forOrder($order);

        $this->assertSame("1/{$order->id}", $invoice->number);
        $this->assertSame(4490.0, (float) $invoice->amount);
        $this->assertSame('ФОП Горенко Поліна Сергіївна', $invoice->requisites['recipient_name']);
        $this->assertSame('UA703220010000026005340086870', $invoice->requisites['iban']);
        $this->assertStringContainsString("№{$invoice->number}", $invoice->purpose);
    }

    public function test_changing_the_brand_requisites_does_not_rewrite_an_issued_invoice(): void
    {
        $invoice = app(InvoiceService::class)->forOrder($this->makeOrder());

        $this->fillRequisites(['recipient_name' => 'ФОП Інша Людина', 'iban' => 'UA999']);

        $invoice->refresh();

        // Документ, який клієнт уже бачив, лишається таким, яким був.
        $this->assertSame('ФОП Горенко Поліна Сергіївна', $invoice->requisites['recipient_name']);
        $this->assertSame('UA703220010000026005340086870', $invoice->requisites['iban']);
    }

    public function test_pressing_the_button_twice_does_not_create_a_second_number(): void
    {
        $order = $this->makeOrder();

        $first  = app(InvoiceService::class)->forOrder($order);
        $second = app(InvoiceService::class)->forOrder($order);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Invoice::count());
    }

    public function test_numbering_runs_per_brand(): void
    {
        $this->seedCatalog('u_fit', 'Тариф u-fit', 500);
        DB::table('projects')->where('slug', 'u_fit')->update([
            'recipient_name' => 'ФОП Друга', 'iban' => 'UA111',
        ]);

        $a1 = app(InvoiceService::class)->forOrder($this->makeOrder());
        $a2 = app(InvoiceService::class)->forOrder($this->makeOrder(['start_date' => '2026-09-01', 'end_date' => '2026-09-05']));

        $ufitTariff = DB::table('tariffs')->where('project', 'u_fit')->value('id');
        $u1 = app(InvoiceService::class)->forOrder($this->makeOrder([
            'project' => 'u_fit', 'tariff_id' => $ufitTariff,
        ]));

        $this->assertSame(1, $a1->sequence);
        $this->assertSame(2, $a2->sequence);
        // У кожного бренду власна нумерація.
        $this->assertSame(1, $u1->sequence);
    }

    public function test_it_refuses_to_issue_an_invoice_without_requisites(): void
    {
        $this->fillRequisites(['recipient_name' => null, 'iban' => null]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/реквізити/u');

        app(InvoiceService::class)->forOrder($this->makeOrder());
    }

    public function test_it_refuses_an_order_with_a_zero_total(): void
    {
        DB::table('tariff_prices')->where('tariff_id', $this->catalog['tariff_id'])->update(['price_per_day' => 0]);

        $this->expectException(ValidationException::class);

        app(InvoiceService::class)->forOrder($this->makeOrder());
    }

    public function test_the_requisites_text_is_ready_to_paste_into_a_messenger(): void
    {
        $text = app(InvoiceService::class)->forOrder($this->makeOrder())->requisitesText();

        $this->assertStringContainsString('UA703220010000026005340086870', $text);
        $this->assertStringContainsString('ФОП Горенко Поліна Сергіївна', $text);
        $this->assertStringContainsString('4 490.00 грн', $text);
    }

    // --- публічне посилання ------------------------------------------------

    public function test_the_public_link_renders_the_invoice(): void
    {
        $invoice = app(InvoiceService::class)->forOrder($this->makeOrder());

        $this->get("/invoices/{$invoice->token}")
            ->assertOk()
            ->assertSee($invoice->number)
            ->assertSee('UA703220010000026005340086870');
    }

    public function test_an_unknown_token_is_not_found(): void
    {
        $this->get('/invoices/nonsense')->assertNotFound();
    }

    public function test_the_pdf_is_generated(): void
    {
        $invoice = app(InvoiceService::class)->forOrder($this->makeOrder());

        $response = $this->get("/invoices/{$invoice->token}.pdf");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    // --- API ---------------------------------------------------------------

    public function test_the_api_returns_the_invoice_for_an_order(): void
    {
        $order = $this->makeOrder();

        $this->postJson(
            "/api/inbox/v1/orders/{$order->id}/invoice",
            [],
            ['Authorization' => 'Bearer '.config('services.inbox.token')],
        )
            ->assertStatus(201)
            ->assertJsonPath('invoice.number', "1/{$order->id}")
            ->assertJsonPath('invoice.amount', 4490)
            ->assertJsonPath('invoice.iban', 'UA703220010000026005340086870')
            ->assertJsonStructure(['invoice' => ['pdf_url', 'purpose', 'text']]);
    }

    public function test_the_api_needs_a_token(): void
    {
        $order = $this->makeOrder();

        $this->postJson("/api/inbox/v1/orders/{$order->id}/invoice")->assertStatus(401);
    }
}
