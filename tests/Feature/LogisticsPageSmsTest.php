<?php

namespace Tests\Feature;

use App\Filament\Pages\LogisticsPage;
use App\Models\Setting;
use App\Models\SmsLog;
use App\Services\ScheduleService;
use App\Services\TurboSmsService;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSmsTestSchema;
use Tests\TestCase;

/**
 * Перевірка сторінки Логістики: стан кнопки, вміст модалок, дії в шапці.
 * Рендер Livewire тут не піднімаємо — інстанціюємо сторінку і смикаємо
 * ті самі методи, які викликає Blade.
 */
class LogisticsPageSmsTest extends TestCase
{
    use BuildsSmsTestSchema;

    protected string $deliveryDate = '2026-08-05';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSmsSchema();

        Setting::create(['key' => ScheduleService::CLOSED_SLOTS_KEY, 'value' => '[]']);
        ScheduleService::clearClosedSlotsCache();
        Setting::create(['key' => TurboSmsService::KEY_TOKEN, 'value' => 'test-token']);
        Setting::create(['key' => TurboSmsService::KEY_SENDER, 'value' => 'UFIT']);
    }

    private function page(string $shift = 'all'): LogisticsPage
    {
        $page = new LogisticsPage();
        $page->data = ['date' => $this->deliveryDate, 'shift' => $shift];

        return $page;
    }

    private function invokeHidden(LogisticsPage $page, string $method)
    {
        $ref = new \ReflectionMethod($page, $method);
        $ref->setAccessible(true);

        return $ref->invoke($page);
    }

    public function test_button_state_is_blocked_without_routes(): void
    {
        $page = $this->page();
        $page->loadSmsState();

        $this->assertFalse($page->smsReady);
        $this->assertNotNull($page->smsBlockReason);
        $this->assertSame(0, $page->smsSentCount);
    }

    public function test_button_state_becomes_ready(): void
    {
        $this->makeRoute(['employee_id' => $this->makeCourier('Іванов І.І.')]);
        $this->makeOrderDay();

        $page = $this->page();
        $page->loadSmsState();

        $this->assertTrue($page->smsReady, $page->smsBlockReason ?? '');
    }

    public function test_page_survives_missing_sms_tables(): void
    {
        // Імітуємо деплой без міграцій — сторінка Логістики має лишитись живою.
        \Illuminate\Support\Facades\Schema::drop('sms_logs');

        $page = $this->page();
        $page->loadSmsState();

        $this->assertFalse($page->smsReady);
        $this->assertStringContainsString('SMS-модуль недоступний', $page->smsBlockReason);
    }

    public function test_header_actions_expose_the_send_button_with_correct_state(): void
    {
        $this->makeRoute(['employee_id' => $this->makeCourier('Іванов І.І.')]);
        $this->makeOrderDay();

        $page = $this->page();
        $page->loadSmsState();

        $actions = collect($this->invokeHidden($page, 'getHeaderActions'))
            ->keyBy(fn ($a) => $a->getName());

        $this->assertTrue($actions->has('send_client_sms'));
        $this->assertTrue($actions->has('sms_log'));
        $this->assertFalse($actions->has('sms_settings'), 'налаштування SMS переїхали в Налаштування бізнесу');

        $send = $actions->get('send_client_sms');
        $this->assertSame('Відправити сповіщення клієнтам', $send->getLabel());
        $this->assertFalse($send->isDisabled(), 'кнопка має бути активною, коли все готово');
    }

    public function test_send_button_is_disabled_when_not_ready(): void
    {
        $this->makeRoute(['employee_id' => null]);

        $page = $this->page();
        $page->loadSmsState();

        $send = collect($this->invokeHidden($page, 'getHeaderActions'))
            ->keyBy(fn ($a) => $a->getName())
            ->get('send_client_sms');

        $this->assertTrue($send->isDisabled());
    }

    public function test_preview_modal_builds_and_blocks_submit_when_nobody_to_send(): void
    {
        // Маршрут із курʼєром є, але жодного замовлення на цю дату немає.
        $this->makeRoute(['employee_id' => $this->makeCourier('Іванов І.І.')]);

        $page = $this->page();
        $schema = $this->invokeHidden($page, 'buildSmsPreviewForm');

        $this->assertNotEmpty($schema);
        $this->assertFalse($page->smsCanSubmit, 'сабміт має бути заблокований, коли слати нікому');
    }

    public function test_preview_modal_allows_submit_when_there_are_recipients(): void
    {
        $this->makeRoute(['employee_id' => $this->makeCourier('Іванов І.І.')]);
        $this->makeOrderDay();

        $page = $this->page();
        $this->invokeHidden($page, 'buildSmsPreviewForm');

        $this->assertTrue($page->smsCanSubmit);
    }

    public function test_log_table_renders_text_and_response(): void
    {
        SmsLog::create([
            'date' => $this->deliveryDate, 'shift' => 'all',
            'client_name' => 'Клієнт Тест', 'phone' => '380501234567',
            'courier_name' => 'Іванов І.І.', 'courier_phone' => '380671112233',
            'car_number' => 'AA0000AA', 'text' => 'Ваш курʼєр: Іванов І.І.',
            'status' => SmsLog::STATUS_SENT, 'response_code' => 0,
            'response_status' => 'OK', 'response_body' => '{"response_code":0}',
        ]);

        $html = (string) $this->invokeHidden($this->page(), 'buildSmsLogTable');

        $this->assertStringContainsString('Клієнт Тест', $html);
        $this->assertStringContainsString('AA0000AA', $html);
        $this->assertStringContainsString('Ваш кур', $html, 'текст SMS має бути видно в журналі');
        $this->assertStringContainsString('код 0', $html, 'відповідь TurboSMS має бути видно');
    }

    public function test_log_table_reports_empty_day(): void
    {
        $html = (string) $this->invokeHidden($this->page(), 'buildSmsLogTable');

        $this->assertStringContainsString('відправок не було', $html);
    }

    public function test_sent_counter_respects_the_shift_filter(): void
    {
        $this->makeRoute(['employee_id' => $this->makeCourier('Іванов І.І.')]);

        SmsLog::create([
            'date' => $this->deliveryDate, 'shift' => 'morning',
            'phone' => '380501234567', 'text' => 'x', 'status' => SmsLog::STATUS_SENT,
        ]);

        $morning = $this->page('morning');
        $morning->loadSmsState();
        $this->assertSame(1, $morning->smsSentCount);

        $evening = $this->page('evening');
        $evening->loadSmsState();
        $this->assertSame(0, $evening->smsSentCount, 'ранкова розсилка не має рахуватись вечірньою');
    }
}
