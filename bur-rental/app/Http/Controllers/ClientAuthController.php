<?php

namespace App\Http\Controllers;

use App\Rules\UkrainianPhone;
use App\Services\Clients\LoginCodes;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Вхід у кабінет за телефоном і одноразовим кодом.
 *
 * Пароля немає навмисно: на сайті оренди його заводять раз і забувають до
 * наступного разу, а телефон клієнт і так диктує менеджеру. Два кроки —
 * номер, потім код.
 */
class ClientAuthController extends Controller
{
    public function __construct(private readonly LoginCodes $codes) {}

    public function form(Request $request): View|RedirectResponse
    {
        if (Auth::guard('client')->check()) {
            return redirect()->route('cabinet');
        }

        return view('pages.cabinet.login', [
            'phone' => $request->session()->get('login.phone'),
        ]);
    }

    /** Крок перший: номер телефону → код. */
    public function requestCode(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', new UkrainianPhone],
        ]);

        $phone = Phone::normalize($data['phone']);

        if (! $phone) {
            throw ValidationException::withMessages(['phone' => 'Введіть номер у форматі +380 XX XXX XX XX']);
        }

        $code = $this->codes->issue($phone, $request->ip());

        $request->session()->put('login.phone', $phone);

        // Тестовий майданчик показує код на екрані: SMS туди не ходять, а
        // доступу до логів у замовника немає. Умова подвійна свідомо — самої
        // змінної в .env замало, щоб віддавати код будь-кому.
        $hint = config('clients.show_code_on_screen') && config('app.noindex') ? $code : null;

        return redirect()->route('cabinet.code')->with('code_hint', $hint);
    }

    public function codeForm(Request $request): View|RedirectResponse
    {
        $phone = $request->session()->get('login.phone');

        if (! $phone) {
            return redirect()->route('cabinet.login');
        }

        return view('pages.cabinet.code', [
            'phone' => Phone::format($phone),
            'attemptsLeft' => $this->codes->attemptsLeft($phone),
        ]);
    }

    /** Крок другий: код → сесія. */
    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'digits:6'],
        ], [
            'code.digits' => 'Код — це шість цифр із SMS',
        ]);

        $phone = $request->session()->get('login.phone');

        if (! $phone) {
            return redirect()->route('cabinet.login');
        }

        $client = $this->codes->verify($phone, $data['code']);

        if (! $client) {
            throw ValidationException::withMessages([
                'code' => $this->codes->attemptsLeft($phone) > 0
                    ? 'Код не підійшов. Перевірте останню SMS.'
                    : 'Код більше не діє. Запросіть новий.',
            ]);
        }

        Auth::guard('client')->login($client, remember: true);

        $request->session()->regenerate();
        $request->session()->forget('login.phone');

        return redirect()->route('cabinet');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('client')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
