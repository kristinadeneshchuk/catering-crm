<?php

namespace App\Http\Middleware;

use App\Models\AiAgent;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Токен ШІ-агента: Authorization: Bearer agt_<slug>_… (у базі лише хеш).
 * Кожен агент — свій токен, свої дозволені типи пропозицій.
 */
class VerifyAgentToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $agent = AiAgent::byToken($request->bearerToken());
        if (!$agent) {
            return response()->json(['message' => 'Невірний токен агента.'], 401);
        }

        $agent->forceFill(['last_seen_at' => now()])->saveQuietly();
        $request->attributes->set('agent', $agent);

        return $next($request);
    }
}
