<?php

namespace App\Services\Ai;

use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\OrderDay;
use App\Models\Position;
use App\Services\TelegramService;
use Carbon\Carbon;

/**
 * Відмітки «+» у чаті кухні (docs/tz-ops-agent.md §2).
 *
 * Кухар пише «+», бот ставить зміну **чернеткою** в Табель — гроші рухаються
 * лише після підтвердження дня менеджером. У самому чаті бот нічого не питає:
 * незнайомий акаунт іде власнику в особисті з кнопками «це хто?».
 */
class KitchenAttendance
{
    public const CALLBACK_LINK = 'kin';

    /** Слова позиції в повідомленні: «+ пакування», «+ шеф». */
    private const POSITIONS = [
        'chef'      => ['шеф', 'shef', 'chef'],
        'cook'      => ['кухар', 'кухарь', 'повар', 'гаряч', 'горяч'],
        'assistant' => ['помічник', 'помощник', 'помічн', 'подсоб'],
        'packer'    => ['пакув', 'фасув', 'упаков'],
    ];

    public function __construct(private TelegramService $telegram)
    {
    }

    /**
     * Це відмітка про вихід? «+», «+ пакування», «плюс».
     *
     * Командні форми «/+» і «/плюс» — запасний шлях: у групі, куди бота додали
     * до вимкнення приватного режиму, Telegram віддає йому лише команди.
     */
    public function isCheckIn(?string $text): bool
    {
        $text = mb_strtolower(trim((string) $text));

        return $text !== '' && (bool) preg_match('/^(\/?\+|\/?плюс)(\s|$|@)/u', $text);
    }

    /**
     * @return string|null коротке пояснення для журналу; null — нічого не робили
     */
    public function checkIn(string $text, string $fromId, string $fromName, string $chatId, int $messageId, bool $anonymous = false): string
    {
        // Повідомлення «від імені групи» (анонімний адмін) не має автора, тож
        // зарахувати його нікому. Пояснюємо власнику, а не мовчимо.
        if ($anonymous || $fromId === '') {
            $this->note('«+» від імені групи — не зараховано');
            $this->telegram->sendToOwner(
                '👤 У чаті кухні «+» надіслано від імені групи, тож я не знаю, хто це. '
                .'Попросіть писати від свого акаунта — або вимкніть «Відправляти анонімно» в правах адміністратора.',
            );

            return 'анонімний адмін';
        }

        $employee = Employee::where('telegram_chat_id', $fromId)->first();

        if (! $employee) {
            $this->askOwnerWhoIsIt($fromId, $fromName);
            $this->note('«+» від невідомого акаунта '.$fromName.' (ID '.$fromId.') — питаю власника');

            return 'невідомий акаунт';
        }

        $shift = $this->markShift($employee, $this->position($text, $employee), share: $this->share($text), note: $text);

        $this->telegram->reactToMessage($chatId, $messageId, '✅');
        $this->note($employee->name.' · '.($shift->position_key ?: 'зміна'));

        return $employee->name.' · '.($shift->position_key ?: 'зміна');
    }

    /** Остання подія кухні — щоб /стан показував, що саме сталось. */
    private function note(string $text): void
    {
        \Illuminate\Support\Facades\Log::info('[Кухня] '.$text);

        if (\App\Support\SchemaReady::has('settings')) {
            \App\Models\Setting::updateOrCreate(
                ['key' => 'ops_last_kitchen_event'],
                ['value' => now()->format('d.m H:i').' — '.$text],
            );
        }
    }

    public static function lastEvent(): ?string
    {
        return \App\Support\SchemaReady::has('settings')
            ? \App\Models\Setting::where('key', 'ops_last_kitchen_event')->value('value')
            : null;
    }

    /**
     * Створити або оновити чернетку зміни на сьогодні.
     *
     * @param  float  $share  частина зміни: 1 — повна, 0.5 — пів зміни
     */
    public function markShift(
        Employee $employee,
        ?string $positionKey,
        ?Carbon $date = null,
        float $share = 1.0,
        ?string $note = null,
    ): EmployeeShift {
        $date  = ($date ?? now())->toDateString();
        $shift = EmployeeShift::where('employee_id', $employee->id)->whereDate('date', $date)->first();

        $attributes = [
            'source'       => EmployeeShift::SOURCE_KITCHEN_CHAT,
            'position_key' => $positionKey ?: $employee->position,
            'is_half'      => abs($share - 0.5) < 0.01,
            'ai_comment'   => $note === null ? null : 'У чаті кухні: «'.trim($note).'»'
                .($share < 1 ? ' → '.$this->shareLabel($share) : ''),
        ];

        if ($shift) {
            // Заплановану зміну відмітка підтверджує, але фактом її робить
            // менеджер у Табелі — там перераховується й ставка.
            $shift->update($attributes);

            return $shift;
        }

        return EmployeeShift::create($attributes + [
            'employee_id' => $employee->id,
            'date'        => $date,
            'shift_slot'  => EmployeeShift::SLOT_FULL,
            'rate'        => round((float) $employee->base_rate * $share, 2),
            'is_planned'  => true, // чернетка: балансу не чіпає
        ]);
    }

    /**
     * Частина зміни з тексту: «+ 0,5», «+ пів», «+ половина» — це половина.
     * Число більше за 1 (наприклад «+ 12:00») — це не частка, а час, і зміна
     * лишається повною.
     */
    public function share(string $text): float
    {
        $haystack = mb_strtolower($text);

        if (preg_match('/(пів|пол(овин)?)/u', $haystack)) {
            return 0.5;
        }

        // Час («до 15», «з 9 до 14») часткою не вважаємо.
        if (preg_match('/\d{1,2}\s*:\s*\d{2}/u', $haystack) || preg_match('/(до|з|с|from|до\s)\s*\d{1,2}/u', $haystack)) {
            return 1.0;
        }

        if (preg_match('/(\d+[.,]\d+)/u', $haystack, $m)) {
            $value = (float) str_replace(',', '.', $m[1]);

            return $value > 0 && $value <= 1 ? $value : 1.0;
        }

        return 1.0;
    }

    private function shareLabel(float $share): string
    {
        return abs($share - 0.5) < 0.01 ? 'пів зміни' : round($share * 100).'% зміни';
    }

    public function position(string $text, Employee $employee): ?string
    {
        $haystack = mb_strtolower($text);

        foreach (self::POSITIONS as $key => $words) {
            foreach ($words as $word) {
                if (str_contains($haystack, $word)) {
                    return Position::where('key', $key)->exists() ? $key : null;
                }
            }
        }

        return $employee->position;
    }

    /** Незнайомий акаунт: питаємо власника в особисті, не в чаті кухні. */
    private function askOwnerWhoIsIt(string $fromId, string $fromName): void
    {
        $candidates = Employee::whereNull('telegram_chat_id')
            ->where('is_active', true)
            ->whereIn('position', ['chef', 'cook', 'assistant', 'packer'])
            ->orderBy('name')
            ->limit(8)
            ->get();

        if ($candidates->isEmpty()) {
            $this->telegram->askOwners("👤 У чаті кухні відмітився «{$fromName}», але всі співробітники вже привʼязані. Перевірте Табель.");

            return;
        }

        $rows = [];

        foreach ($candidates->chunk(2) as $pair) {
            $rows[] = $pair->map(fn (Employee $e) => [
                'text'          => mb_substr($e->name, 0, 28),
                'callback_data' => self::CALLBACK_LINK.":{$fromId}:{$e->id}",
            ])->values()->all();
        }

        $this->telegram->askOwners(
            "👤 У чаті кухні відмітився <b>".e($fromName)."</b> (Telegram ID <code>{$fromId}</code>), але я не знаю, хто це.\n"
            ."Оберіть співробітника — і далі відмічатиму його сам.",
            $rows,
        );
    }

    /** Натиснули кнопку «це такий-то»: запамʼятовуємо акаунт і ставимо зміну. */
    public function link(string $telegramId, int $employeeId): string
    {
        $employee = Employee::find($employeeId);

        if (! $employee) {
            return 'Співробітника не знайдено.';
        }

        $employee->update(['telegram_chat_id' => $telegramId]);
        $this->markShift($employee, $employee->position, note: 'привʼязка акаунта');

        return $employee->name.' — записав і відмітив зміну.';
    }

    /**
     * Фонд оплати кухні на день проти порцій.
     *
     * @return array{fot: float, portions: int, per_portion: float, people: \Illuminate\Support\Collection}
     */
    public function payrollOfDay(?Carbon $date = null): array
    {
        $date = ($date ?? now())->toDateString();

        $shifts = EmployeeShift::with('employee')
            ->whereDate('date', $date)
            ->get()
            ->filter(fn (EmployeeShift $s) => in_array(
                $s->position_key ?: $s->employee?->position,
                ['chef', 'cook', 'assistant', 'packer'],
                true,
            ));

        $portions = OrderDay::whereDate('date', $date)->count();
        $fot      = (float) $shifts->sum('rate');

        return [
            'fot'         => $fot,
            'portions'    => $portions,
            'per_portion' => $portions > 0 ? round($fot / $portions, 2) : 0.0,
            'people'      => $shifts,
        ];
    }
}
