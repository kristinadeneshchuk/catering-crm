<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\LunchCompanyLink;
use App\Models\LunchOrder;
use App\Models\Order;
use App\Models\Transaction;
use App\Services\Accounting\CashBook;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsInboxTestSchema;
use Tests\TestCase;

/**
 * Гроші за корпоративні обіди: нараховане зі знімків Lunch Hub — борг клієнта-
 * компанії, «Оплата обідів» його гасить. Клієнтів без обідів це не зачіпає.
 */
class LunchMoneyTest extends TestCase
{
    use BuildsInboxTestSchema;

    protected array $catalog;
    protected Client $company;
    protected Client $regular;
    protected int $accountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInboxSchema();
        if (! \Illuminate\Support\Facades\Schema::hasTable('users')) {
            \Illuminate\Support\Facades\Schema::create('users', fn ($t) => $t->id());
        }
        (require database_path('migrations/2026_11_11_100000_create_lunch_orders.php'))->up();
        (require database_path('migrations/2026_11_13_100000_create_lunch_company_links.php'))->up();
        (require database_path('migrations/2026_11_13_200000_add_company_amount_to_lunch_orders.php'))->up();

        $this->catalog   = $this->seedCatalog(pricePerDay: 1000);
        $this->company   = Client::find($this->makeClient());
        $this->regular   = Client::find($this->makeClient());
        $this->accountId = $this->makeAccount();
    }

    private function lunchDay(string $date, float $amount, int $companyId = 12, ?int $clientId = null): LunchOrder
    {
        return LunchOrder::create([
            'date' => $date, 'company_id' => $companyId, 'company_name' => 'ТЕСТ від ХП',
            'client_id' => $clientId, 'sets' => 1, 'amount' => $amount,
        ]);
    }

    private function payLunch(float $amount, ?int $clientId = null, ?int $accountId = null): Transaction
    {
        return Transaction::create([
            'type' => 'income', 'category' => CashBook::LUNCH, 'amount' => $amount, 'date' => now(),
            'client_id' => $clientId ?? $this->company->id, 'account_id' => $accountId ?? $this->accountId,
        ]);
    }

    private function order(Client $client): Order
    {
        return Order::create([
            'client_id' => $client->id, 'project' => 'afood', 'tariff_id' => $this->catalog['tariff_id'],
            'calories' => 1600, 'duration' => 6, 'start_date' => '2026-11-16', 'end_date' => '2026-11-21', 'scale_factor' => 1.0,
        ]);
    }

    private function balance(Client $c): float
    {
        return (float) $c->fresh()->balance;
    }

    public function test_a_client_without_lunches_is_counted_exactly_as_before(): void
    {
        $order = $this->order($this->regular);
        Transaction::create(['type' => 'income', 'category' => 'Оплата клієнта', 'amount' => 2500, 'date' => now(), 'order_id' => $order->id, 'account_id' => $this->accountId]);

        // Чужі обіди й чужі оплати обідів на нього не впливають.
        $this->lunchDay('2026-11-16', 4900, clientId: $this->company->id);
        $this->payLunch(1000);
        $this->regular->syncBalance();

        $this->assertSame(-3500.0, $this->balance($this->regular));
        $this->assertSame(0.0, $this->regular->lunchBalance());
    }

    public function test_linking_a_company_turns_its_days_into_debt(): void
    {
        $this->lunchDay('2026-11-16', 4900);
        $this->lunchDay('2026-11-17', 3500);
        $this->assertSame(0.0, $this->balance($this->company));

        LunchCompanyLink::link(12, 'ТЕСТ від ХП', $this->company->id);

        $this->assertSame(-8400.0, $this->balance($this->company));

        LunchCompanyLink::unlink(12);
        $this->assertSame(0.0, $this->balance($this->company));
    }

    public function test_lunch_payment_pays_the_debt_and_lands_in_the_cash(): void
    {
        $this->lunchDay('2026-11-16', 4900);
        LunchCompanyLink::link(12, 'ТЕСТ від ХП', $this->company->id);
        $cashBefore = (float) Account::find($this->accountId)->balance;

        $t = $this->payLunch(3000);
        $this->assertSame(-1900.0, $this->balance($this->company));
        $this->assertSame($cashBefore + 3000, (float) Account::find($this->accountId)->balance);

        $t->update(['amount' => 4900]);
        $this->assertSame(0.0, $this->balance($this->company));

        $t->delete();
        $this->assertSame(-4900.0, $this->balance($this->company));
    }

    public function test_lunch_debt_adds_to_ordinary_orders_of_the_same_client(): void
    {
        $this->order($this->company);                       // 6000 за раціони
        $this->lunchDay('2026-11-16', 4900);
        LunchCompanyLink::link(12, 'ТЕСТ від ХП', $this->company->id);

        $this->assertSame(-10900.0, $this->balance($this->company));

        // Оплата обідів не ставить «оплачено» на замовленні раціонів.
        $this->payLunch(4900);
        $this->assertSame(-6000.0, $this->balance($this->company));
        $this->assertFalse((bool) Order::where('client_id', $this->company->id)->first()->is_paid);
    }

    public function test_a_corrected_snapshot_from_lunch_hub_recounts_the_debt(): void
    {
        config()->set('services.lunch.token', 'test-lunch-token');
        LunchCompanyLink::link(12, 'ТЕСТ від ХП', $this->company->id);

        $send = fn (array $companies, string $date) => $this->postJson('/api/lunch/orders', ['date' => $date, 'companies' => $companies],
            ['Authorization' => 'Bearer test-lunch-token'])->assertOk();
        $co = fn (int $sets, float $amount) => ['id' => 12, 'name' => 'ТЕСТ від ХП', 'sets' => $sets, 'amount' => $amount,
            'lines' => [['dish_id' => null, 'name' => 'Страва', 'kind' => 'main', 'qty' => $sets, 'weight_g' => 300]]];

        $send([$co(14, 4900)], '2026-11-16');
        $this->assertSame(-4900.0, $this->balance($this->company));

        // Другий день додається, виправлення першого — замінює, а не дублює.
        $send([$co(10, 3500)], '2026-11-17');
        $send([$co(12, 4200)], '2026-11-16');
        $this->assertSame(-7700.0, $this->balance($this->company));

        // Компанія зняла всі обіди дня — борг за цей день зникає.
        $send([], '2026-11-17');
        $this->assertSame(-4200.0, $this->balance($this->company));
    }

    public function test_with_split_payment_only_the_company_share_is_its_debt(): void
    {
        config()->set('services.lunch.token', 'test-lunch-token');
        LunchCompanyLink::link(12, 'ТЕСТ від ХП', $this->company->id);
        $line = [['dish_id' => null, 'name' => 'Страва', 'kind' => 'main', 'qty' => 1, 'weight_g' => 300]];

        // 50/50: половину платить компанія, половину — співробітники самі.
        $this->postJson('/api/lunch/orders', ['date' => '2026-11-16', 'companies' => [[
            'id' => 12, 'name' => 'ТЕСТ від ХП', 'sets' => 2, 'amount' => 440, 'company_amount' => 220, 'employees_amount' => 220, 'lines' => $line,
        ]]], ['Authorization' => 'Bearer test-lunch-token'])->assertOk();
        // День без поділу — компанія винна всю суму.
        $this->postJson('/api/lunch/orders', ['date' => '2026-11-17', 'companies' => [[
            'id' => 12, 'name' => 'ТЕСТ від ХП', 'sets' => 1, 'amount' => 350, 'lines' => $line,
        ]]], ['Authorization' => 'Bearer test-lunch-token'])->assertOk();

        $this->assertSame(-570.0, $this->balance($this->company));
        $this->assertSame(570.0, $this->company->fresh()->lunchCharged());

        $day = LunchOrder::whereDate('date', '2026-11-16')->first();
        $this->assertSame(440.0, (float) $day->amount);
        $this->assertSame(220.0, $day->companyDue());
        $this->assertSame(220.0, (float) $day->employees_amount);

        // Компанія платить за все сама, а не навпіл: частина компанії нуль — боргу немає.
        $this->postJson('/api/lunch/orders', ['date' => '2026-11-16', 'companies' => [[
            'id' => 12, 'name' => 'ТЕСТ від ХП', 'sets' => 2, 'amount' => 440, 'company_amount' => 0, 'employees_amount' => 440, 'lines' => $line,
        ]]], ['Authorization' => 'Bearer test-lunch-token'])->assertOk();
        $this->assertSame(-350.0, $this->balance($this->company));
    }

    public function test_lunch_payment_needs_a_cash_account_and_a_client(): void
    {
        foreach ([['client_id' => $this->company->id], ['account_id' => $this->accountId]] as $partial) {
            try {
                Transaction::create(['type' => 'income', 'category' => CashBook::LUNCH, 'amount' => 100, 'date' => now()] + $partial);
                $this->fail('Оплату без каси або без клієнта прийнято');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, Transaction::where('category', CashBook::LUNCH)->count());
    }
}
