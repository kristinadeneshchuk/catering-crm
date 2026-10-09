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
}
