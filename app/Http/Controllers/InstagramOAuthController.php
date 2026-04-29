<?php

namespace App\Http\Controllers;

use App\Models\MessengerAccount;
use App\Services\Messenger\Instagram\InstagramChannelDriver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * OAuth-флоу для Instagram через нову "Instagram Login API"
 * (запущена Meta наприкінці 2024 року на заміну старій Facebook Login).
 *
 * Відрізняється від старого підходу:
 *  - Аутентифікація напряму через instagram.com, не через facebook.com
 *  - Не потрібна Facebook Page
 *  - Окремий Instagram App ID/Secret (config('services.meta.instagram'))
 *  - Endpoint'и graph.instagram.com замість graph.facebook.com
 *
 * Документація:
 *  https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login
 *
 * Шляхи:
 *  /admin/messenger-accounts/{id}/oauth-instagram/start  — починає, редиректить на instagram.com
 *  /oauth/instagram/callback                              — приймає code, обмінює на токени
 */
class InstagramOAuthController extends Controller
{
    private const IG_OAUTH_URL = 'https://www.instagram.com/oauth/authorize';
    private const API_BASE     = 'https://api.instagram.com';
    private const GRAPH_BASE   = 'https://graph.instagram.com';

    private const SCOPES = [
        'instagram_business_basic',
        'instagram_business_manage_messages',
        'instagram_business_manage_comments',
        'instagram_business_content_publish',
    ];

    public function start(Request $request, MessengerAccount $account): RedirectResponse
    {
        if ($account->channel !== MessengerAccount::CHANNEL_INSTAGRAM) {
            abort(400, 'Цей акаунт не Instagram');
        }

        $appId = config('services.meta.instagram.app_id');
        if (! $appId) {
            abort(500, 'INSTAGRAM_APP_ID не налаштований у .env');
        }

        // CSRF-захист: state = account_id + випадковий рядок
        $state = $account->id . ':' . Str::random(40);
        session(['instagram_oauth_state' => $state]);

        $url = self::IG_OAUTH_URL . '?' . http_build_query([
            'enable_fb_login'      => '0',
            'force_authentication' => '1',
            'client_id'            => $appId,
            'redirect_uri'         => $this->redirectUri(),
            'response_type'        => 'code',
            'scope'                => implode(',', self::SCOPES),
            'state'                => $state,
        ]);

        return redirect()->away($url);
    }

    public function callback(Request $request, InstagramChannelDriver $driver): RedirectResponse
    {
        // Помилки авторизації
        if ($request->has('error')) {
            return $this->backWithError(
                'Instagram відхилив авторизацію: ' . $request->query('error_description', $request->query('error'))
            );
        }

        // Перевірка state
        $state    = $request->query('state');
        $expected = session('instagram_oauth_state');
        session()->forget('instagram_oauth_state');

        if (! $state || $state !== $expected) {
            return $this->backWithError('Невалідний state. Спробуйте підключити заново.');
        }

        [$accountId,] = explode(':', $state, 2);
        $account = MessengerAccount::find((int) $accountId);

        if (! $account) {
            return $this->backWithError('Акаунт не знайдено');
        }

        // Instagram після #_ додає fragment, який браузер не передає у GET-параметри.
        // Code приходить чисто у query string — використовуємо його.
        $code = $request->query('code');
        if (! $code) {
            return $this->backWithError('Не отримано authorization code');
        }

        $appId     = config('services.meta.instagram.app_id');
        $appSecret = config('services.meta.instagram.app_secret');

        try {
            // 1) code → short-lived access token (1 година)
            $shortRes = Http::asForm()
                ->timeout(15)
                ->post(self::API_BASE . '/oauth/access_token', [
                    'client_id'     => $appId,
                    'client_secret' => $appSecret,
                    'grant_type'    => 'authorization_code',
                    'redirect_uri'  => $this->redirectUri(),
                    'code'          => $code,
                ]);

            if (! $shortRes->successful()) {
                throw new \RuntimeException('Token exchange: ' . $shortRes->body());
            }

            $shortLivedToken = $shortRes->json('access_token');
            $userId          = $shortRes->json('user_id');

            if (! $shortLivedToken || ! $userId) {
                throw new \RuntimeException('Token exchange повернув порожні поля: ' . $shortRes->body());
            }

            // 2) short-lived → long-lived (60 днів)
            $longRes = Http::timeout(15)->get(self::GRAPH_BASE . '/access_token', [
                'grant_type'    => 'ig_exchange_token',
                'client_secret' => $appSecret,
                'access_token'  => $shortLivedToken,
            ]);

            if (! $longRes->successful()) {
                throw new \RuntimeException('Long-lived exchange: ' . $longRes->body());
            }

            $longLivedToken = $longRes->json('access_token');
            $expiresIn      = (int) $longRes->json('expires_in');

            // 3) Профіль користувача.
            // Тут id = Page-Scoped User ID (PSID), а user_id = IG Business Account ID.
            // Webhook'и приходять з entry.id = IG Business Account ID, тому саме його
            // треба зберігати як external_account_id для матчингу.
            $profileRes = Http::timeout(10)->get(self::GRAPH_BASE . '/v23.0/me', [
                'fields'       => 'id,user_id,username,name,account_type,profile_picture_url',
                'access_token' => $longLivedToken,
            ]);

            $profile = $profileRes->successful() ? (array) $profileRes->json() : [];
            $username = $profile['username'] ?? null;
            $igBusinessId = $profile['user_id'] ?? (string) $userId;

            $account->update([
                'external_account_id' => (string) $igBusinessId,
                'display_name'        => $account->display_name ?: ('@' . ($username ?: $igBusinessId)),
                'credentials'         => [
                    'access_token'         => $longLivedToken,
                    'user_id'              => (string) $userId,
                    'ig_business_id'       => (string) $igBusinessId,
                    'username'             => $username,
                    'name'                 => $profile['name'] ?? null,
                    'account_type'         => $profile['account_type'] ?? null,
                    'profile_picture_url'  => $profile['profile_picture_url'] ?? null,
                    'token_expires_at'     => $expiresIn ? now()->addSeconds($expiresIn)->toIso8601String() : null,
                    'token_refreshed_at'   => now()->toIso8601String(),
                ],
                'status'         => MessengerAccount::STATUS_ACTIVE,
                'last_error'     => null,
                'last_synced_at' => now(),
            ]);

            // 5) connect() в новій API — це просто перевірка токена.
            // Підписка на webhooks конфігурується на рівні App у Meta Dashboard,
            // окремих subscribe_apps викликів не потрібно.
            $driver->connect($account);

            return redirect()
                ->to('/admin/messenger-accounts/' . $account->id . '/edit')
                ->with('instagram_oauth_status', 'connected');
        } catch (\Throwable $e) {
            Log::error('Instagram OAuth callback failed', [
                'account_id' => $account->id,
                'error'      => $e->getMessage(),
            ]);

            $account->update([
                'status'     => MessengerAccount::STATUS_ERROR,
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            return $this->backWithError($e->getMessage());
        }
    }

    protected function redirectUri(): string
    {
        return url('/oauth/instagram/callback');
    }

    protected function backWithError(string $msg): RedirectResponse
    {
        return redirect('/admin/messenger-accounts')
            ->with('instagram_oauth_error', $msg);
    }
}
