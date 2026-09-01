<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Client;
use App\Services\Loyalty;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Кабінет: історія оренд, свої дані, обране.
 *
 * Головна цінність тут не «особистий простір», а два питання, з якими клієнт
 * дзвонить менеджеру: «коли мені здавати» і «скільки я винен». Обидві
 * відповіді на першому екрані.
 */
class CabinetController extends Controller
{
    public function __construct(private readonly Loyalty $loyalty) {}

    public function index(): View
    {
        $client = $this->client();

        $bookings = $client->bookings()
            ->with(['items.product', 'items.extra', 'branch.city'])
            ->get();

        return view('pages.cabinet.index', [
            'client' => $client,
            // Активні — те, що зараз на руках або ось-ось почнеться.
            'active' => $bookings->whereIn('status', ['new', 'confirmed', 'issued']),
            'past' => $bookings->whereIn('status', ['closed', 'cancelled']),
            'favourites' => $client->favourites()->with(['brand', 'tiers'])->take(4)->get(),
            'discountPercent' => $this->loyalty->percentFor($client),
            'loyaltyTitle' => $this->loyalty->titleFor($client),
            // Скільки лишилось до наступної сходинки — головне, заради чого
            // цей блок узагалі є: він має мотивувати повернутися.
            'toNextLevel' => $this->loyalty->rentalsToNextLevel($client),
            'completedRentals' => $this->loyalty->completedRentals($client),
        ]);
    }

    public function booking(Booking $booking): View
    {
        // Чужу бронь не показуємо навіть за прямим номером у адресі.
        abort_unless($booking->client_id === $this->client()->id, 404);

        return view('pages.booking-confirmed', [
            'booking' => $booking->load(['items.product', 'items.extra', 'branch.city', 'deliveryZone']),
        ]);
    }

    public function profile(): View
    {
        return view('pages.cabinet.profile', ['client' => $this->client()]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190'],
            'company' => ['nullable', 'string', 'max:190'],
            'edrpou' => ['nullable', 'digits_between:8,10'],
            'marketing_opt_out' => ['nullable', 'boolean'],
        ]);

        // Чекбокс без відмітки в форму не приходить узагалі.
        $data['marketing_opt_out'] = $request->boolean('marketing_opt_out');

        // Телефон не редагується: він і є логін. Зміна номера — це новий вхід.
        $this->client()->update($data);

        return redirect()->route('cabinet.profile')->with('saved', true);
    }

    private function client(): Client
    {
        return Auth::guard('client')->user();
    }
}
