<?php

namespace App\Http\Middleware;

use App\Models\InternalApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuthenticateInternalApi
{
    public function handle(Request $request, Closure $next)
    {
        $plainToken = $request->bearerToken();

        if (! is_string($plainToken) || $plainToken === '') {
            Log::warning('Internal API rejected: missing bearer token', [
                'path' => $request->path(),
                'ip' => $request->ip(),
            ]);

            abort(401, 'Missing internal API token.');
        }

        $token = InternalApiToken::query()
            ->where('token_hash', InternalApiToken::hashToken($plainToken))
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if (! $token) {
            Log::warning('Internal API rejected: invalid bearer token', [
                'path' => $request->path(),
                'ip' => $request->ip(),
            ]);

            abort(401, 'Invalid internal API token.');
        }

        $token->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('internal_api_token_id', $token->id);

        Log::info('Internal API authenticated', [
            'path' => $request->path(),
            'method' => $request->method(),
            'token_id' => $token->id,
            'token_name' => $token->name,
        ]);

        return $next($request);
    }
}
