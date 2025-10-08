<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CheckUserBanned
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user) {
            return $next($request);
        }

        // banned را از panel_users بر اساس ایمیل بخوان
        $banned = DB::table('panel_users')->where('email', $user->email)->value('banned');

        if ((int) $banned === 1) {
            // توکن فعلی را پاک کن
            try { $user->currentAccessToken()?->delete(); } catch (\Throwable $e) {}

            return response()->json([
                'status'  => 'error',
                'message' => 'دسترسی شما به پنل محدود شده است'
            ], 403);
        }

        return $next($request);
    }
}
