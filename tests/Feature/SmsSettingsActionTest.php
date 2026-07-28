<?php

namespace Tests\Feature;

use App\Filament\Resources\SettingResource\Pages\ListSettings;
use App\Models\Setting;
use App\Services\TurboSmsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Налаштування TurboSMS живуть у «Налаштування бізнесу» (SettingResource),
 * а не на сторінці Логістики.
 */
class SmsSettingsActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->text('value')->nullable();
            $t->timestamps();
        });
    }

    private function smsAction()
    {
        $page = new ListSettings();

        $ref = new \ReflectionMethod($page, 'getHeaderActions');
        $ref->setAccessible(true);

        return collect($ref->invoke($page))
            ->keyBy(fn ($a) => $a->getName())
            ->get('sms_settings');
    }

    public function test_sms_settings_action_is_available_on_business_settings(): void
    {
        $action = $this->smsAction();

        $this->assertNotNull($action, 'кнопка має бути в шапці «Налаштування бізнесу»');
        $this->assertSame('Налаштування SMS', $action->getLabel());
    }

    public function test_it_saves_token_sender_and_template(): void
    {
        $this->smsAction()->call(['data' => [
            TurboSmsService::KEY_TOKEN    => 'new-token',
            TurboSmsService::KEY_SENDER   => 'NEWNAME',
            TurboSmsService::KEY_TEMPLATE => 'Курʼєр {courier}, {phone}',
        ]]);

        $this->assertSame('new-token', Setting::where('key', TurboSmsService::KEY_TOKEN)->value('value'));
        $this->assertSame('NEWNAME', Setting::where('key', TurboSmsService::KEY_SENDER)->value('value'));
        $this->assertSame('Курʼєр {courier}, {phone}', app(TurboSmsService::class)->template());
    }

    public function test_it_updates_existing_values_instead_of_duplicating(): void
    {
        Setting::create(['key' => TurboSmsService::KEY_SENDER, 'value' => 'OLD']);

        $this->smsAction()->call(['data' => [TurboSmsService::KEY_SENDER => 'NEW']]);

        $this->assertSame(1, Setting::where('key', TurboSmsService::KEY_SENDER)->count());
        $this->assertSame('NEW', Setting::where('key', TurboSmsService::KEY_SENDER)->value('value'));
    }

    public function test_template_falls_back_to_the_default_when_empty(): void
    {
        $this->assertSame(TurboSmsService::DEFAULT_TEMPLATE, app(TurboSmsService::class)->template());
    }
}
