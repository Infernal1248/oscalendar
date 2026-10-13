<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AuthenticateNpdApi
{
    public function handle(Request $request, Closure $next)
    {
        $secret = (string) config('npd.secret');
        if ($secret === '' || ! hash_equals($secret, (string) $request->bearerToken())) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request)->header('Cache-Control', 'private, no-store');
    }
}
