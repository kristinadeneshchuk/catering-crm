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
 * OAuth-флоу для Instagram (через Facebook Login).
 *
 * /admin/messenger-accounts/{id}/oauth-instagram/start    — починає, редиректить на FB
 * /oauth/instagram/callback                               — приймає код, обмінює на токени
 */
class InstagramOAuthController extends Controller
{
    private const FB_OAUTH = 'https://www.facebook.com/v19.0/dialog/oauth';
    private const GRAPH    = 'https://graph.facebook.com/v19.0';

    private const SCOPES = [
        'instagram_basic',
        'instagram_manage_messages',
        'pages_show_list',
        'pages_messaging',
        'pages_manage_metadata',
        'business_management',
    ];

    public function start(Request $request, MessengerAccount $account): RedirectResponse
    {
        if ($account->channel !== MessengerAccount::CHANNEL_INSTAGRAM) {
            abort(400, 'Цей акаунт не Instagram');
        }

        if (! config('services.meta.app_id')) {
            abort(500, 'META_APP_ID не налаштований у .env');
        }

        // CSRF-захист: state = випадковий рядок + account_id
        $state = $account->id . ':' . Str::random(40);
        session(['instagram_oauth_state' => $state]);

        $url = self::FB_OAUTH . '?' . http_build_query([
            'client_id'     => config('services.meta.app_id'),
            'redirect_uri'  => $this->redirectUri(),
            'state'         => $state,
            'scope'         => implode(',', self::SCOPES),
            'response_type' => 'code',
        ]);

        return redirect()->away($url);
    }

    public function callback(Request $request, InstagramChannelDriver $driver): RedirectResponse
    {
        // Ловимо помилки з боку FB
        if ($request->has('error')) {
            return $this->backWithError(
                'Facebook відхилив авторизацію: ' . $request->query('error_description', $request->query('error'))
            );
        }

        $state = $request->query('state');
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

        $code = $request->query('code');
        if (! $code) {
            return $this->backWithError('Не отримано authorization code');
        }

        try {
            // 1) code → short-lived user access token
            $tokenRes = Http::timeout(15)->get(self::GRAPH . '/oauth/access_token', [
                'client_id'     => config('services.meta.app_id'),
                'client_secret' => config('services.meta.app_secret'),
                'redirect_uri'  => $this->redirectUri(),
                'code'          => $code,
            ]);

            if (! $tokenRes->successful()) {
                throw new \RuntimeException('Token exchange: ' . $tokenRes->body());
            }

            $shortLived = $tokenRes->json('access_token');

            // 2) short-lived → long-lived (60 днів)
            $longRes = Http::timeout(15)->get(self::GRAPH . '/oauth/access_token', [
                'grant_type'        => 'fb_exchange_token',
                'client_id'         => config('services.meta.app_id'),
                'client_secret'     => config('services.meta.app_secret'),
                'fb_exchange_token' => $shortLived,
            ]);

            if (! $longRes->successful()) {
                throw new \RuntimeException('Long-lived exchange: ' . $longRes->body());
            }

            $userToken = $longRes->json('access_token');

            // 3) Список Pages, до яких користувач дав доступ
            $pagesRes = Http::timeout(15)->get(self::GRAPH . '/me/accounts', [
                'access_token' => $userToken,
                'fields'       => 'id,name,access_token,instagram_business_account',
            ]);

            if (! $pagesRes->successful()) {
                throw new \RuntimeException('Get pages: ' . $pagesRes->body());
            }

            $pages = $pagesRes->json('data') ?? [];
            $pageWithIg = collect($pages)->first(fn ($p) => isset($p['instagram_business_account']));

            if (! $pageWithIg) {
                throw new \RuntimeException(
                    'Серед ваших Facebook Pages не знайшлось жодної з підключеним Instagram Business акаунтом. '
                    . 'Переконайтесь що IG → Business + повʼязаний з FB Page.'
                );
            }

            $pageId   = $pageWithIg['id'];
            $pageName = $pageWithIg['name'] ?? null;
            $pageToken = $pageWithIg['access_token'];
            $igAccountId = $pageWithIg['instagram_business_account']['id'] ?? null;

            // 4) Дотягуємо username Instagram-акаунта (для display name)
            $igInfo = Http::timeout(10)->get(self::GRAPH . "/{$igAccountId}", [
                'fields'       => 'username,name',
                'access_token' => $pageToken,
            ]);
            $igUsername = $igInfo->json('username');

            // 5) Зберігаємо credentials і external_account_id = Page ID
            $account->update([
                'external_account_id' => $pageId,
                'display_name'        => $account->display_name ?: ('@' . $igUsername),
                'credentials'         => [
                    'user_access_token'    => $userToken,
                    'page_access_token'    => $pageToken,
                    'page_id'              => $pageId,
                    'page_name'            => $pageName,
                    'instagram_account_id' => $igAccountId,
                    'instagram_username'   => $igUsername,
                    'token_refreshed_at'   => now()->toIso8601String(),
                ],
                'status'         => MessengerAccount::STATUS_INACTIVE,
                'last_error'     => null,
                'last_synced_at' => now(),
            ]);

            // 6) Підписуємось на webhooks для цієї Page
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
