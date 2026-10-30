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

        // Authentication/revocation is checked above on every request. Only the
        // informational last-use timestamp is coalesced; no authorization cache.
        $usedAt = now();
        $cutoff = $usedAt->copy()->subMinute();
        if (! $token->last_used_at || $token->last_used_at->lte($cutoff)) {
            InternalApiToken::whereKey($token->id)
                ->where(fn ($query) => $query->whereNull('last_used_at')->orWhere('last_used_at', '<=', $cutoff))
                ->update(['last_used_at' => $usedAt]);
        }
        $request->attributes->set('internal_api_token_id', $token->id);

        return $next($request);
    }
}
