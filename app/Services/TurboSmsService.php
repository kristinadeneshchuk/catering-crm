<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Клієнт TurboSMS HTTP API v2 (https://turbosms.ua/api.html).
 *
 * Токен і альфа-імʼя відправника зберігаються в таблиці settings — так само,
 * як ant_access_key, щоб їх можна було міняти з адмінки без деплою.
 */
class TurboSmsService
{
    private string $baseUrl = 'https://api.turbosms.ua';

    public const KEY_TOKEN    = 'turbosms_token';
    public const KEY_SENDER   = 'turbosms_sender';
    public const KEY_TEMPLATE = 'turbosms_template';

    public const DEFAULT_TEMPLATE = "Ваш курʼєр: {courier}\nТелефон: {phone}\nАвто: {car}";

    /**
     * Коди відповіді TurboSMS → людський текст українською.
     * Повний перелік ширший; невідомі коди показуємо як «код + статус».
     */
    private const CODE_MESSAGES = [
        // Успіх
        0   => 'Успішно',
        800 => 'Повідомлення прийнято до відправки',
        801 => 'Повідомлення відправлено',
        802 => 'Прийнято частково — частину номерів виключено',
        803 => 'Відправлено частково',
        804 => 'Виконано',
        805 => 'Виконано частково',

        // Авторизація
        103 => 'Не вказано токен авторизації TurboSMS',
        104 => 'У запиті відсутні дані',
        105 => 'Помилка авторизації — невірний токен TurboSMS',
        106 => 'Обліковий запис TurboSMS заблоковано',
        301 => 'Невірний токен авторизації TurboSMS',

        // Альфа-імʼя відправника
        200 => 'Не вказано альфа-імʼя відправника',
        302 => 'Некоректне альфа-імʼя відправника',
        400 => 'Це альфа-імʼя не дозволене для вашого акаунта TurboSMS',
        401 => 'Альфа-імʼя неактивне (не оплачене або не зареєстроване)',
        415 => 'Альфа-імʼя не підтримує транзакційні повідомлення',

        // Отримувачі та текст
        202 => 'Не вказано жодного отримувача',
        204 => 'Не вказано текст повідомлення',
        305 => 'Некоректний номер телефону',
        406 => 'Відправка в цю країну не дозволена',

        // Баланс
        203 => 'Недостатньо коштів на рахунку TurboSMS',
    ];

    /**
     * Коди, які означають «повідомлення прийнято/відправлено».
     * Уся серія 8xx — успішна: якби 801 (відправлено) вважався помилкою,
     * успішна SMS писалась би в лог як невдала і клієнт отримав би дубль
     * при наступній відправці.
     */
    private const OK_CODES = [0, 800, 801, 802, 803, 804, 805];

    // -------------------------------------------------------------------------
    // Налаштування
    // -------------------------------------------------------------------------

    public function token(): ?string
    {
        $value = trim((string) (Setting::where('key', self::KEY_TOKEN)->value('value') ?? ''));

        return $value !== '' ? $value : null;
    }

    public function sender(): ?string
    {
        $value = trim((string) (Setting::where('key', self::KEY_SENDER)->value('value') ?? ''));

        return $value !== '' ? $value : null;
    }

    public function template(): string
    {
        $value = trim((string) (Setting::where('key', self::KEY_TEMPLATE)->value('value') ?? ''));

        return $value !== '' ? $value : self::DEFAULT_TEMPLATE;
    }

    public function isConfigured(): bool
    {
        return $this->token() !== null && $this->sender() !== null;
    }

    /**
     * Причина, чому відправка неможлива (null — все гаразд).
     */
    public function configurationError(): ?string
    {
        if ($this->token() === null) {
            return 'Не вказано токен TurboSMS. Заповніть його в «Налаштування бізнесу» → «Налаштування SMS».';
        }

        if ($this->sender() === null) {
            return 'Не вказано альфа-імʼя відправника TurboSMS. Заповніть його в «Налаштування бізнесу» → «Налаштування SMS».';
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Телефони
    // -------------------------------------------------------------------------

    /**
     * Нормалізує український номер до формату 380XXXXXXXXX.
     * Повертає null, якщо номер не схожий на валідний мобільний.
     */
    public static function normalizePhone(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);

        if ($digits === '') {
            return null;
        }

        // 0671234567 → 380671234567
        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $digits = '38' . $digits;
        } elseif (strlen($digits) === 9) {
            // 671234567 → 380671234567
            $digits = '380' . $digits;
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '80')) {
            // 80671234567 → 380671234567
            $digits = '3' . $digits;
        }

        if (strlen($digits) !== 12 || ! str_starts_with($digits, '380')) {
            return null;
        }

        return $digits;
    }

    // -------------------------------------------------------------------------
    // Відправка
    // -------------------------------------------------------------------------

    /**
     * Відправляє один і той самий текст групі номерів (один запит до API).
     *
     * Текст однаковий для всіх клієнтів одного маршруту, тому пакетна відправка
     * дає один HTTP-запит на маршрут замість одного на клієнта.
     *
     * results ключується нормалізованим номером — тим самим, який передали в
     * $phones, тож викликач шукає результат прямо за своїм номером.
     *
     * @param  string[]  $phones  нормалізовані номери 380XXXXXXXXX
     *
     * global — код глобальної відмови (немає коштів / токен), raw — сира
     * відповідь шлюзу для журналу.
     *
     * @return array{ok: bool, error: ?string, results: array<string, array{code: int, status: string, message_id: ?string, ok: bool, message: string}>, global: ?array{code: int, status: string}, raw: ?string}
     */
    public function sendBatch(array $phones, string $text): array
    {
        $phones = array_values(array_unique(array_filter($phones)));

        if (empty($phones)) {
            return ['ok' => false, 'error' => 'Порожній список отримувачів', 'results' => [], 'global' => null, 'raw' => null];
        }

        if ($error = $this->configurationError()) {
            return ['ok' => false, 'error' => $error, 'results' => [], 'global' => null, 'raw' => null];
        }

        try {
            $response = Http::timeout(30)
                ->withToken($this->token())
                ->acceptJson()
                ->asJson()
                ->post("{$this->baseUrl}/message/send.json", [
                    'recipients' => $phones,
                    'sms' => [
                        'sender' => $this->sender(),
                        'text'   => $text,
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::error('[TurboSMS] Request failed', ['error' => $e->getMessage()]);

            return [
                'ok' => false, 'error' => 'Немає звʼязку з TurboSMS: ' . $e->getMessage(),
                'results' => [], 'global' => null, 'raw' => $e->getMessage(),
            ];
        }

        if ($response->failed()) {
            Log::error('[TurboSMS] HTTP error', ['status' => $response->status(), 'body' => $response->body()]);

            return [
                'ok'      => false,
                'error'   => "TurboSMS повернув HTTP {$response->status()}",
                'results' => [],
                'global'  => null,
                'raw'     => $response->body(),
            ];
        }

        $data = $response->json() ?? [];

        $globalCode   = (int) ($data['response_code'] ?? -1);
        $globalStatus = (string) ($data['response_status'] ?? '');

        // Глобальна помилка (немає коштів, невірний токен, незареєстроване
        // альфа-імʼя) — жодне повідомлення не пішло.
        if (! in_array($globalCode, self::OK_CODES, true)) {
            $message = self::describe($globalCode, $globalStatus);
            Log::warning('[TurboSMS] Rejected', ['code' => $globalCode, 'status' => $globalStatus]);

            return [
                'ok' => false, 'error' => $message, 'results' => [],
                'global' => ['code' => $globalCode, 'status' => $globalStatus],
                'raw'    => $response->body(),
            ];
        }

        // Порезультатний розбір: TurboSMS може прийняти частину номерів.
        $rows = $data['response_result'] ?? [];

        // При одному отримувачі API може віддати сам обʼєкт, а не масив обʼєктів.
        if (isset($rows['response_code']) || isset($rows['phone'])) {
            $rows = [$rows];
        }

        $rows = array_values((array) $rows);

        // Позиційна відповідність «i-й результат = i-й отримувач» НЕ гарантована:
        // при коді 802 (прийнято частково) API цілком може взагалі не повернути
        // рядки по відхилених номерах, і тоді решта результатів зʼїде на одну
        // позицію — статус одного клієнта записався б іншому. Тому підстраховку
        // за позицією вмикаємо тільки для пакета з ОДНОГО отримувача, де вона
        // однозначна.
        $positionalFallback = count($phones) === 1 && count($rows) === 1;

        $results = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $code   = (int) ($row['response_code'] ?? -1);
            $status = (string) ($row['response_status'] ?? '');

            // Ключуємо за НОРМАЛІЗОВАНИМ номером: API може повернути його з «+»,
            // з пробілами або числом — інакше успішна відправка записалась би в
            // лог як помилка, і клієнт наступного разу отримав би дубль.
            $phone = self::normalizePhone((string) ($row['phone'] ?? ''));

            if ($phone === null && $positionalFallback) {
                $phone = $phones[0];
            }

            if ($phone === null) {
                continue;
            }

            $results[$phone] = [
                'code'       => $code,
                'status'     => $status,
                'message_id' => $row['message_id'] ?? null,
                'ok'         => in_array($code, self::OK_CODES, true),
                'message'    => self::describe($code, $status),
            ];
        }

        return ['ok' => true, 'error' => null, 'results' => $results, 'global' => null, 'raw' => $response->body()];
    }

    /**
     * Баланс рахунку TurboSMS (null, якщо не вдалося отримати).
     */
    public function balance(): ?float
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::timeout(15)
                ->withToken($this->token())
                ->acceptJson()
                ->get("{$this->baseUrl}/user/balance.json");

            if ($response->failed()) {
                return null;
            }

            $data = $response->json() ?? [];

            if ((int) ($data['response_code'] ?? -1) !== 0) {
                return null;
            }

            return isset($data['response_result']['balance'])
                ? (float) $data['response_result']['balance']
                : null;
        } catch (\Throwable $e) {
            Log::warning('[TurboSMS] Balance check failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public static function describe(int $code, string $status = ''): string
    {
        if (isset(self::CODE_MESSAGES[$code])) {
            return self::CODE_MESSAGES[$code];
        }

        return trim("Помилка TurboSMS (код {$code}" . ($status !== '' ? ", {$status}" : '') . ')');
    }
}
