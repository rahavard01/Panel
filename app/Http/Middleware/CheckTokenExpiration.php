<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckTokenExpiration
{
    public function handle(Request $request, Closure $next)
    {
        $user  = $request->user();
        $token = $user?->currentAccessToken();

        if (!$token || ($token->expires_at && now()->greaterThan($token->expires_at))) {
            try { $token?->delete(); } catch (\Throwable $e) {}
            return response()->json([
                'status'  => 'error',
                'message' => 'Token expired or invalid',
            ], 401);
        }

        return $next($request);
    }
}
