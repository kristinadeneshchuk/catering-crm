<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TelegramService;
use App\Support\Security\DeviceLabel;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Захист входу: журнал невдалих спроб для fail2ban і сповіщення власнику
 * про вхід персоналу з нового пристрою.
 */
class LoginSecurityTest extends TestCase
{
    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.7 Mobile/15E148 Safari/604.1';
    private const WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36';

    private string $log;
    private array $sent = [];
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email')->unique(); $t->string('password');
            $t->string('role')->default('manager'); $t->rememberToken(); $t->timestamps();
        });
        Schema::create('user_login_devices', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->char('ua_hash', 40); $t->text('user_agent')->nullable();
            $t->string('last_ip', 45)->nullable(); $t->timestamp('first_seen_at')->nullable(); $t->timestamp('last_seen_at')->nullable();
            $t->unique(['user_id', 'ua_hash']);
        });

        $this->log = storage_path('logs/security-test.log');
        @unlink($this->log);
        config()->set('logging.channels.security.path', $this->log);

        $sent = &$this->sent;
        $this->app->instance(TelegramService::class, new class($sent) extends TelegramService {
            public function __construct(private array &$box) {}
            public function sendToOwner(string $text): void { $this->box[] = $text; }
        });

        $this->user = User::create(['name' => 'katya_manadger', 'email' => 'k@x.test', 'password' => bcrypt('x'), 'role' => 'manager']);
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
        parent::tearDown();
    }

    public function test_failed_login_is_logged_with_ip_before_attacker_text(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '45.148.10.40']);
        $this->app->instance('request', \Illuminate\Http\Request::create('/admin/login', 'POST', server: ['REMOTE_ADDR' => '45.148.10.40']));

        event(new Failed('web', null, ['email' => "admin\"\n[2026-01-01] fake ip=1.1.1.1", 'password' => 'x']));

        $line = trim(file_get_contents($this->log));
        $this->assertStringContainsString('WARNING: LOGIN_FAILED ip=45.148.10.40 guard=web login="', $line);
        $this->assertSame(1, substr_count($line, "\n") + 1, 'перенос рядка в логіні не має розривати запис');
        $this->assertStringNotContainsString('password', $line);

        // Той самий регулярний вираз, що у фільтрі fail2ban
        $this->assertMatchesRegularExpression('/^\[[^\]]+\] \w+\.WARNING: LOGIN_FAILED ip=45\.148\.10\.40 /', $line);
    }

    public function test_login_from_new_device_notifies_owner_once(): void
    {
        $this->loginFrom(self::IPHONE, '31.1.1.1');
        $this->loginFrom(self::IPHONE, '31.2.2.2'); // той самий телефон, інший IP мобільного
        $this->flushAfterResponse();

        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('katya_manadger (manager)', $this->sent[0]);
        $this->assertStringContainsString('iPhone · Safari', $this->sent[0]);
        $this->assertSame('31.2.2.2', DB::table('user_login_devices')->value('last_ip'));
    }

    public function test_known_device_from_sessions_seed_is_silent(): void
    {
        DB::table('user_login_devices')->insert(['user_id' => $this->user->id, 'ua_hash' => sha1(self::IPHONE), 'user_agent' => self::IPHONE]);

        $this->loginFrom(self::IPHONE, '31.1.1.1');
        $this->loginFrom(self::WINDOWS, '8.8.8.8');
        $this->flushAfterResponse();

        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('Windows · Chrome', $this->sent[0]);
    }

    public function test_ban_notify_lists_tried_logins(): void
    {
        file_put_contents($this->log, implode("\n", [
            '[2026-09-28 15:00:00] testing.WARNING: LOGIN_FAILED ip=45.148.10.40 guard=web login="admin"',
            '[2026-09-28 15:00:01] testing.WARNING: LOGIN_FAILED ip=9.9.9.9 guard=web login="other"',
            '[2026-09-28 15:00:02] testing.WARNING: LOGIN_FAILED ip=45.148.10.40 guard=web login="Myrzabek@ukr.net"',
        ]) . "\n");
        config()->set('logging.channels.security.path', $this->log);
        $this->app->useStoragePath(dirname(dirname($this->log)));
        copy($this->log, storage_path('logs/security.log'));

        $this->artisan('security:ban-notify', ['ip' => '45.148.10.40', '--failures' => 12, '--bantime' => 86400])->assertSuccessful();

        $this->assertStringContainsString('Заблоковано IP 45.148.10.40 на 24 год', $this->sent[0]);
        $this->assertStringContainsString('Myrzabek@ukr.net', $this->sent[0]);
        $this->assertStringNotContainsString('other', $this->sent[0]);
        @unlink(storage_path('logs/security.log'));
    }

    public function test_ban_notify_rejects_garbage_ip(): void
    {
        $this->artisan('security:ban-notify', ['ip' => '1.2.3.4; rm -rf /'])->assertFailed();
        $this->assertCount(0, $this->sent);
    }

    public function test_device_labels(): void
    {
        $this->assertSame('iPhone · Safari', DeviceLabel::fromUserAgent(self::IPHONE));
        $this->assertSame('Windows · Chrome', DeviceLabel::fromUserAgent(self::WINDOWS));
        $this->assertSame('невідомий пристрій', DeviceLabel::fromUserAgent(null));
    }

    private function loginFrom(string $ua, string $ip): void
    {
        $this->app->instance('request', \Illuminate\Http\Request::create('/admin/login', 'POST', server: ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $ua]));
        event(new Login('web', $this->user, false));
    }

    /** Колбеки «після відповіді»: у тестах вони накопичуються, тож запускаємо раз. */
    private function flushAfterResponse(): void
    {
        $this->app->terminate();
    }
}
