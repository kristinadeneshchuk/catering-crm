<?php

namespace Tests\Feature;

use App\Filament\Resources\BankOperationResource;
use App\Models\Account;
use App\Models\BankOperation;
use App\Models\User;
use App\Services\Bank\MonobankSync;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Автозвірка з monobank, етап 1: токен у рахунку і виписка в bank_operations.
 */
class MonobankSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(\Carbon\Carbon::parse('2026-10-09 20:00:00'));

        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('type')->nullable();
            $t->boolean('is_default')->default(false);
            $t->decimal('balance', 12, 2)->default(0);
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email');
            $t->string('password');
            $t->string('role')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });

        (require database_path('migrations/2026_10_09_200000_create_bank_operations.php'))->up();
        (require database_path('migrations/2026_10_10_090000_add_bank_only_to_accounts.php'))->up();

        config(['services.finance.super_admin_ids' => '7']);
    }

    protected function account(): Account
    {
        return Account::create(['name' => 'ФОП Катречко', 'type' => 'card', 'mono_token' => 'uTestToken-abcd1234']);
    }

    protected function op(string $id, int $time, int $amount, array $extra = []): array
    {
        return ['id' => $id, 'time' => $time, 'amount' => $amount, 'balance' => 100000, 'description' => "Оплата {$id}", 'mcc' => 4829] + $extra;
    }

    protected function sync(): MonobankSync
    {
        return new MonobankSync(fn () => null); // без пауз у тестах
    }

    public function test_the_token_is_stored_encrypted_and_never_serialized(): void
    {
        $account = $this->account();

        $raw = DB::table('accounts')->where('id', $account->id)->value('mono_token');
        $this->assertNotSame('uTestToken-abcd1234', $raw);
        $this->assertStringNotContainsString('abcd1234', $raw);

        $this->assertSame('uTestToken-abcd1234', $account->fresh()->mono_token);
        $this->assertArrayNotHasKey('mono_token', $account->fresh()->toArray());
        $this->assertSame('••••1234', $account->fresh()->maskedMonoToken());
    }

    public function test_it_picks_the_fop_account_and_stores_operations_once(): void
    {
        $t = now()->timestamp;
        Http::fake([
            '*/personal/client-info' => Http::response(['accounts' => [
                ['id' => 'black1', 'type' => 'black', 'currencyCode' => 980, 'iban' => 'UA00black'],
                ['id' => 'fopUAH', 'type' => 'fop', 'currencyCode' => 980, 'iban' => 'UA11fop'],
            ]]),
            '*/personal/statement/fopUAH/*' => Http::response([
                $this->op('a1', $t - 3600, 1050000, ['counterName' => 'Степанов В.', 'comment' => 'раціон']),
                $this->op('a2', $t - 7200, -2217000, ['counterName' => 'ФОП Атабеков', 'counterEdrpou' => '2634313872']),
            ]),
        ]);

        $account = $this->account();
        $r = $this->sync()->sync($account);
        $this->assertSame(['inserted' => 2, 'fetched' => 2], $r);

        $account->refresh();
        $this->assertSame('fopUAH', $account->mono_account_id);
        $this->assertSame('UA11fop', $account->mono_iban);
        $this->assertNotNull($account->mono_synced_at);

        $in = BankOperation::where('bank_id', 'a1')->first();
        $this->assertSame('10500.00', $in->amount);
        $this->assertSame('Степанов В.', $in->counter_name);
        $this->assertSame('-22170.00', BankOperation::where('bank_id', 'a2')->value('amount'));

        // Токен — лише в заголовку.
        Http::assertSent(fn (Request $req) => $req->hasHeader('X-Token', 'uTestToken-abcd1234')
            && !str_contains($req->url(), 'uTestToken'));

        // Повторний запуск дублів не створює.
        $r2 = $this->sync()->sync($account->fresh());
        $this->assertSame(0, $r2['inserted']);
        $this->assertSame(2, BankOperation::count());
    }

    public function test_a_full_page_pulls_older_operations(): void
    {
        $t = now()->timestamp;
        $page1 = [];
        for ($i = 0; $i < 500; $i++) $page1[] = $this->op("p{$i}", $t - 60 * ($i + 1), 100);
        $oldestOnPage1 = $t - 60 * 500;

        Http::fakeSequence('*/personal/statement/*')
            ->push($page1)
            ->push([$this->op('older', $oldestOnPage1 - 3600, 200)]);

        $account = $this->account();
        $account->forceFill(['mono_account_id' => 'fopUAH'])->save();

        $r = $this->sync()->sync($account);

        $this->assertSame(501, $r['inserted']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $req) => str_ends_with($req->url(), '/' . ($oldestOnPage1 - 1)));
    }

    public function test_bank_errors_are_reported_without_the_token(): void
    {
        Http::fake(['*' => Http::response(['errorDescription' => "Unknown 'X-Token'"], 403)]);

        try {
            $this->sync()->sync($this->account());
            $this->fail('мала бути помилка');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('403', $e->getMessage());
            $this->assertStringNotContainsString('abcd1234', $e->getMessage());
        }
    }

    public function test_only_the_super_admin_sees_the_bank(): void
    {
        $make = function (int $id, string $role) {
            $u = new User(['name' => "u{$id}", 'email' => "u{$id}@test.local"]);
            $u->id = $id;
            $u->role = $role;
            $u->password = 'x';
            $u->save();
            return $u;
        };

        $owner = $make(7, 'admin');
        $otherAdmin = $make(1, 'admin');
        $manager = $make(11, 'manager');

        $this->actingAs($owner);
        $this->assertTrue(BankOperationResource::canAccess());

        $this->actingAs($otherAdmin);
        $this->assertFalse(BankOperationResource::canAccess());

        $this->actingAs($manager);
        $this->assertFalse(BankOperationResource::canAccess());

        // Менеджер з id зі списку, але не admin — теж ні.
        config(['services.finance.super_admin_ids' => '11']);
        $this->assertFalse(BankOperationResource::canAccess());
    }

    public function test_the_month_filter_lists_months_with_operations(): void
    {
        $account = $this->account();
        BankOperation::create(['account_id' => $account->id, 'bank_id' => 'x', 'operated_at' => '2026-08-15 10:00:00', 'amount' => 5]);

        $this->assertSame(['2026-10', '2026-09', '2026-08'], array_keys(BankOperationResource::monthOptions()));
    }

    public function test_an_arbitrary_past_period_is_fetched(): void
    {
        Http::fake(['*/personal/statement/*' => Http::response([
            $this->op('old1', \Carbon\Carbon::parse('2026-08-28 12:00')->timestamp, 600000, ['counterName' => 'Лазутіна О.']),
        ])]);

        $account = $this->account();
        $account->forceFill(['mono_account_id' => 'fopUAH'])->save();

        $from = \Carbon\Carbon::parse('2026-08-20')->startOfDay();
        $to = \Carbon\Carbon::parse('2026-09-08')->endOfDay();
        $r = $this->sync()->syncRange($account, $from, $to);

        $this->assertSame(1, $r['inserted']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $req) => str_ends_with($req->url(), "/fopUAH/{$from->timestamp}/{$to->timestamp}"));
    }

    public function test_a_period_longer_than_31_days_is_split_into_windows(): void
    {
        Http::fake(['*/personal/statement/*' => Http::response([])]);

        $account = $this->account();
        $account->forceFill(['mono_account_id' => 'fopUAH'])->save();

        $from = \Carbon\Carbon::parse('2026-07-01')->startOfDay();
        $to = \Carbon\Carbon::parse('2026-09-08')->endOfDay(); // ~70 діб → 3 вікна

        $this->sync()->syncRange($account, $from, $to);

        $windows = [];
        Http::recorded(function (Request $req) use (&$windows) {
            [$f, $t] = array_slice(explode('/', $req->url()), -2);
            $windows[] = [(int) $f, (int) $t];
        });

        $this->assertCount(3, $windows);
        foreach ($windows as [$f, $t]) {
            $this->assertLessThanOrEqual(31 * 86400, $t - $f);
        }
        $this->assertSame($to->timestamp, $windows[0][1]);
        $this->assertSame($from->timestamp, end($windows)[0]);
        // Вікна стикуються без дір.
        $this->assertSame($windows[0][0] - 1, $windows[1][1]);
        $this->assertSame($windows[1][0] - 1, $windows[2][1]);
    }

    public function test_the_command_accepts_a_period(): void
    {
        Http::fake(['*/personal/statement/*' => Http::response([])]);
        $account = $this->account();
        $account->forceFill(['mono_account_id' => 'fopUAH'])->save();

        $this->artisan('bank:sync', ['--account' => $account->id, '--from' => '2026-08-20', '--to' => '2026-09-08'])
            ->assertSuccessful();

        $from = \Carbon\Carbon::parse('2026-08-20')->startOfDay()->timestamp;
        Http::assertSent(fn (Request $req) => str_contains($req->url(), "/fopUAH/{$from}/"));
    }

    public function test_a_bank_only_white_card_is_synced_but_hidden_from_payments(): void
    {
        Http::fake([
            '*/personal/client-info' => Http::response(['accounts' => [
                ['id' => 'fopUAH', 'type' => 'fop', 'currencyCode' => 980, 'iban' => 'UA11fop'],
                ['id' => 'whiteUAH', 'type' => 'white', 'currencyCode' => 980, 'iban' => 'UA22white'],
                ['id' => 'whiteUSD', 'type' => 'white', 'currencyCode' => 840, 'iban' => 'UA33usd'],
            ]]),
            '*/personal/statement/whiteUAH/*' => Http::response([
                $this->op('w1', now()->timestamp - 600, -250000, ['description' => 'Банкомат']),
            ]),
        ]);

        $fop = $this->account();
        $white = Account::create([
            'name' => 'Біла картка Строї', 'type' => 'card', 'bank_only' => true,
            'mono_token' => 'uTestToken-abcd1234', 'mono_account_type' => 'white',
        ]);

        // Для решти CRM рахунку немає: вибір рахунку для оплат, каса, find().
        $this->assertSame([$fop->id], Account::pluck('id')->all());
        $this->assertNull(Account::find($white->id));
        $this->assertCount(2, Account::withBankOnly()->get());

        // Синхронізація його бачить і бере саме гривневу білу картку.
        $this->artisan('bank:sync', ['--account' => $white->id])->assertSuccessful();

        $white = Account::withBankOnly()->find($white->id);
        $this->assertSame('whiteUAH', $white->mono_account_id);
        $op = BankOperation::with('account')->where('bank_id', 'w1')->first();
        $this->assertSame('-2500.00', $op->amount);
        $this->assertSame('Біла картка Строї', $op->account->name);
    }

    public function test_a_missing_card_type_is_a_clear_error(): void
    {
        Http::fake(['*/personal/client-info' => Http::response(['accounts' => [
            ['id' => 'fopUAH', 'type' => 'fop', 'currencyCode' => 980],
        ]])]);

        $acc = Account::create(['name' => 'Чорна', 'mono_token' => 'x', 'mono_account_type' => 'black', 'bank_only' => true]);

        $this->expectExceptionMessage('немає гривневого рахунку «Чорна картка»');
        $this->sync()->sync($acc);
    }

    public function test_transfers_between_own_accounts_are_marked_internal(): void
    {
        $fop = $this->account();
        $fop->forceFill(['mono_iban' => 'UA11fop'])->save();
        $white = Account::create(['name' => 'Біла', 'bank_only' => true, 'mono_iban' => 'UA22white']);
        $other = Account::create(['name' => 'ФОП Горенко', 'mono_iban' => 'UA33gor']);

        $mk = fn ($acc, $id, $at, $amount, $iban = null) => BankOperation::create([
            'account_id' => $acc->id, 'bank_id' => $id, 'operated_at' => $at, 'amount' => $amount, 'counter_iban' => $iban,
        ]);

        $out = $mk($fop, 'out', '2026-10-01 10:00:00', -10000);       // ФОП → своя картка
        $in = $mk($white, 'in', '2026-10-01 10:01:30', 10000);          // зустрічна на картці
        $supplier = $mk($fop, 'sup', '2026-10-01 10:00:30', -5000);     // постачальник
        $late = $mk($other, 'late', '2026-10-01 13:00:00', 10000);      // та сама сума, але через 3 год
        $byIban = $mk($other, 'iban', '2026-10-02 09:00:00', -3000, 'UA22white'); // на IBAN своєї картки
        $wages = $mk($white, 'wage', '2026-10-02 12:00:00', -8000);     // з картки людям

        $internal = BankOperation::internal()->pluck('bank_id')->sort()->values()->all();
        $this->assertSame(['iban', 'in', 'out'], $internal);

        // Без переказів між своїми — лише реальні гроші бізнесу.
        $real = BankOperation::internal(false)->pluck('bank_id')->sort()->values()->all();
        $this->assertSame(['late', 'sup', 'wage'], $real);
        $this->assertSame(-13000.0, (float) BankOperation::internal(false)->where('amount', '<', 0)->sum('amount'));
    }
}
