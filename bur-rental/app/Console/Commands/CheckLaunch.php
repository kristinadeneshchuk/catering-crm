<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\City;
use App\Models\Product;
use App\Models\Review;
use App\Services\Messaging\LogSms;
use Illuminate\Console\Command;

/**
 * Передстартова перевірка.
 *
 * Ставиться між «залили на домен» і «відкрили для Google». Ловить рівно ті
 * помилки, які на бойовому коштують дорого і при цьому непомітні: демо-контент,
 * вигадані рейтинги, забутий noindex, залишений файл установки.
 *
 * `php artisan check:launch` — червоне означає «не відкривати індексацію».
 */
class CheckLaunch extends Command
{
    protected $signature = 'check:launch';

    protected $description = 'Перевірити готовність до бойового запуску';

    /**
     * Демо-дані з сидів. Якщо вони досі в базі — контакти не замінили на живі,
     * а сайт із чужою адресою в Google Business Profile ловить блокування
     * картки швидше, ніж встигає проіндексуватися.
     *
     * @var list<string>
     */
    private const DEMO_MARKERS = [
        '067 245 80 80', '067 245 80 82', '067 245 80 90',
        'вул. Ревуцького 12', 'вул. Вербова 8',
    ];

    private int $errors = 0;

    private int $warnings = 0;

    public function handle(): int
    {
        $this->newLine();
        $this->line('<options=bold>Готовність до запуску</>');
        $this->newLine();

        $this->environment();
        $this->content();
        $this->channels();
        $this->security();

        $this->newLine();

        if ($this->errors) {
            $this->error("Блокерів: {$this->errors}. Індексацію не вмикати.");

            return self::FAILURE;
        }

        $this->info($this->warnings
            ? "Блокерів немає, попереджень: {$this->warnings}."
            : 'Усе чисто — можна відкривати індексацію.');

        return self::SUCCESS;
    }

    private function environment(): void
    {
        $this->section('Оточення');

        $this->check(
            ! config('app.debug'),
            'APP_DEBUG вимкнено',
            'APP_DEBUG=true на бойовому показує трасування помилок разом зі змінними оточення',
        );

        $this->check(
            str_starts_with((string) config('app.url'), 'https://'),
            'APP_URL на https',
            'APP_URL має бути https-адресою бойового домену: з неї будуються canonical і sitemap',
        );

        $this->check(
            ! str_contains((string) config('app.url'), 'localhost'),
            'APP_URL не localhost',
            'у canonical і мапі сайту піде localhost — Google проіндексує адреси, яких немає',
        );

        $this->check(
            ! config('app.noindex'),
            'сайт відкритий для пошуковиків',
            'SITE_NOINDEX=true — кожна сторінка віддає noindex, robots.txt закриває все, sitemap віддає 404',
            warning: true,
        );
    }

    private function content(): void
    {
        $this->section('Контент');

        $this->check(
            ! config('content.demo_reviews'),
            'демонстраційні відгуки приховані',
            'DEMO_REVIEWS=true показує вигадані відгуки — обман клієнта і привід для ручних санкцій Google',
        );

        $invented = Product::withoutGlobalScope('published')
            ->where('reviews_count', '>', 0)
            ->whereDoesntHave('reviews')
            ->count();

        $this->check(
            $invented === 0,
            'немає рейтингів без відгуків',
            "у {$invented} товарів стоїть кількість відгуків, яких немає в базі — приберіть числа або зберіть справжні відгуки",
        );

        $this->check(
            Review::withoutGlobalScope('real')->where('demo', false)->exists(),
            'є справжні відгуки',
            'жодного справжнього відгуку: сайт запуститься, але без соціального доказу і без зірок у видачі',
            warning: true,
        );

        $demoContacts = City::whereIn('phone', self::DEMO_MARKERS)->count()
            + Branch::where(fn ($q) => $q->whereIn('phone', self::DEMO_MARKERS)
                ->orWhereIn('address', self::DEMO_MARKERS))->count();

        $this->check(
            $demoContacts === 0,
            'контакти справжні',
            "{$demoContacts} записів із демонстраційними телефонами чи адресами — замініть у адмінці, інакше картка в Google Картах ловить блокування за неіснуючу адресу",
        );

        $drafts = Product::withoutGlobalScope('published')->where('published', false)->count();

        if ($drafts) {
            $this->note("чернеток у каталозі: {$drafts} (на сайті не показуються)");
        }
    }

    private function channels(): void
    {
        $this->section('Канали');

        $this->check(
            config('clients.sms') !== LogSms::class,
            'SMS-шлюз підключено',
            'SMS ідуть у лог: вхід у кабінет і нагадування про повернення клієнт не отримає',
            warning: true,
        );

        $this->check(
            (bool) config('services.telegram.token'),
            'Telegram-сповіщення налаштовані',
            'менеджер не дізнається про нову бронь, доки не відкриє адмінку',
            warning: true,
        );

        $this->check(
            ! config('clients.show_code_on_screen'),
            'код входу не показується на екрані',
            'CLIENT_SHOW_CODE=true віддає код будь-кому, хто введе чужий номер',
        );
    }

    private function security(): void
    {
        $this->section('Безпека');

        $this->check(
            ! file_exists(public_path('setup.php')),
            'файл установки видалено',
            'public/setup.php лишився на сервері — він уміє перезапускати міграції',
        );

        $this->check(
            config('app.key') !== '',
            'APP_KEY згенеровано',
            'без ключа не працюють сесії й шифрування',
        );

        $weak = in_array(env('ADMIN_PASSWORD'), [null, '', 'password', 'secret', 'admin'], true);

        $this->check(
            ! $weak,
            'пароль адмінки змінено',
            'ADMIN_PASSWORD порожній або словниковий',
        );
    }

    private function section(string $title): void
    {
        $this->line("  <fg=gray>{$title}</>");
    }

    private function check(bool $passed, string $ok, string $problem, bool $warning = false): void
    {
        if ($passed) {
            $this->line("  <fg=green>✓</> {$ok}");

            return;
        }

        if ($warning) {
            $this->warnings++;
            $this->line("  <fg=yellow>!</> {$problem}");

            return;
        }

        $this->errors++;
        $this->line("  <fg=red>✗</> {$problem}");
    }

    private function note(string $text): void
    {
        $this->line("  <fg=gray>·</> {$text}");
    }
}
