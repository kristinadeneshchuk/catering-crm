<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\Position;
use App\Services\Ai\KitchenAttendance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\CourierShiftScenario;
use Tests\TestCase;

/**
 * «+» у чаті кухні: зміна стає чернеткою в Табелі, гроші рухає тільки
 * підтвердження менеджера. У самому чаті бот нічого не питає.
 */
class KitchenAttendanceTest extends TestCase
{
    use CourierShiftScenario;

    protected Employee $cook;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCourierWorld();
        config()->set('services.telegram.kitchen_chat_id', '-5116331458');

        foreach ([['chef', 'Шеф-кухар'], ['cook', 'Кухар'], ['assistant', 'Помічник'], ['packer', 'Пакувальник']] as [$key, $name]) {
            Position::firstOrCreate(['key' => $key], ['name' => $name, 'payment_type' => 'per_shift']);
        }

        $this->cook = Employee::create([
            'name' => 'Олена Кухар', 'position' => 'cook', 'base_rate' => 1200,
            'balance' => 0, 'is_active' => true, 'telegram_chat_id' => '700',
        ]);
    }

    protected function say(string $text, string $fromId = '700', string $name = 'Олена'): void
    {
        $this->postJson('/webhooks/telegram-bot', [
            'update_id' => random_int(1, 10000),
            'message' => [
                'message_id' => random_int(1, 10000),
                'chat'       => ['id' => -5116331458, 'type' => 'group', 'title' => 'Кухня'],
                'from'       => ['id' => (int) $fromId, 'first_name' => $name],
                'text'       => $text,
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'sec'])->assertOk();
    }

    public function test_a_plus_becomes_a_draft_shift(): void
    {
        $this->say('+');

        $shift = EmployeeShift::first();
        $this->assertSame($this->cook->id, $shift->employee_id);
        $this->assertTrue((bool) $shift->is_planned, 'чернетка: гроші не нараховані');
        $this->assertSame(EmployeeShift::SOURCE_KITCHEN_CHAT, $shift->source);
        $this->assertSame('cook', $shift->position_key);
        $this->assertEquals(0, (float) $this->cook->fresh()->balance);

        // У чаті — лише реакція, жодного тексту.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'setMessageReaction'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'sendMessage') && (string) $r['chat_id'] === '-5116331458');
    }

    public function test_a_slash_plus_works_when_the_group_still_hides_messages(): void
    {
        // У старій групі Telegram віддає боту лише команди, тож «/+» має
        // працювати так само, як «+».
        $this->say('/+ пакування');

        $shift = EmployeeShift::first();
        $this->assertNotNull($shift);
        $this->assertSame('packer', $shift->position_key);
    }

    public function test_the_position_of_the_day_comes_from_the_message(): void
    {
        $this->say('+ пакування');

        $this->assertSame('packer', EmployeeShift::first()->position_key);
    }

    public function test_a_second_plus_does_not_double_the_shift(): void
    {
        $this->say('+');
        $this->say('+ шеф');

        $this->assertSame(1, EmployeeShift::count());
        $this->assertSame('chef', EmployeeShift::first()->position_key);
    }

    public function test_an_unknown_account_is_asked_about_in_private(): void
    {
        Employee::create(['name' => 'Марія Помічник', 'position' => 'assistant', 'base_rate' => 900, 'is_active' => true]);

        $this->say('+', fromId: '999', name: 'Марія');

        $this->assertSame(0, EmployeeShift::count(), 'зміни немає, поки не знаємо, хто це');

        // Питання пішло власнику, а не в чат кухні.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && (string) $r['chat_id'] === '100'
            && str_contains((string) $r['text'], 'Марія')
            && str_contains(json_encode($r['reply_markup'] ?? [], JSON_UNESCAPED_UNICODE), 'Марія Помічник'));

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && (string) $r['chat_id'] === '-5116331458');
    }

    public function test_the_owner_links_the_account_with_a_button(): void
    {
        $maria = Employee::create(['name' => 'Марія Помічник', 'position' => 'assistant', 'base_rate' => 900, 'is_active' => true]);

        $press = fn (int $fromId) => $this->postJson('/webhooks/telegram-bot', [
            'update_id' => 91,
            'callback_query' => [
                'id'      => 'cb-link',
                'from'    => ['id' => $fromId],
                'data'    => "kin:999:{$maria->id}",
                'message' => ['message_id' => 5, 'chat' => ['id' => 100], 'text' => 'Хто це?'],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'sec'])->assertOk();

        $press(999); // сторонній
        $this->assertNull($maria->fresh()->telegram_chat_id);

        $press(100); // власник
        $this->assertSame('999', $maria->fresh()->telegram_chat_id);
        $this->assertSame(1, EmployeeShift::where('employee_id', $maria->id)->count());

        // Далі відмічається сама.
        $this->say('+ помічник', fromId: '999');
        $this->assertSame(1, EmployeeShift::where('employee_id', $maria->id)->count());
    }

    public function test_payroll_of_the_day_is_measured_per_portion(): void
    {
        $this->say('+');
        Employee::create(['name' => 'Іван', 'position' => 'packer', 'base_rate' => 800, 'is_active' => true, 'telegram_chat_id' => '701']);
        $this->say('+', fromId: '701', name: 'Іван');

        // 20 порцій на сьогодні.
        $clientId = $this->makeClient();
        $catalog  = $this->seedCatalog();
        $order    = \App\Models\Order::create([
            'client_id' => $clientId, 'project' => 'afood', 'tariff_id' => $catalog['tariff_id'],
            'calories' => 1600, 'duration' => 20, 'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(19)->toDateString(), 'scale_factor' => 1.0,
        ]);

        for ($i = 0; $i < 20; $i++) {
            DB::table('order_days')->insert(['order_id' => $order->id, 'date' => now()->toDateString()]);
        }

        $data = app(KitchenAttendance::class)->payrollOfDay();

        $this->assertEquals(2000, $data['fot']);
        $this->assertSame(20, $data['portions']);
        $this->assertEquals(100, $data['per_portion']);

        // 100 ₴/порція — у нормі, сигналу немає.
        $this->artisan('kitchen:payroll-check')->assertSuccessful();
        Http::assertNotSent(fn ($r) => str_contains($r['text'] ?? '', 'ФОТ кухні вище норми'));

        // А якщо поріг 90 — власник має дізнатись.
        config()->set('ops.kitchen_fot_per_portion', 90);
        $this->artisan('kitchen:payroll-check')->assertSuccessful();
        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', 'ФОТ кухні вище норми')
            && str_contains($r['text'], '100 ₴/порція'));
    }
}
