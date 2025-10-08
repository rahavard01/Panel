<?php

namespace App\Services;

use App\Models\PasswordResetToken;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PasswordResetService
{
    public function createTokenFor(User $user, int $ttlMinutes = 5): string
    {
        // پاک‌سازی توکن‌های قدیمی/مصرف‌نشده برای همین کاربر
        PasswordResetToken::where('user_id', $user->id)
            ->where(function ($q) {
                $q->whereNull('used_at')
                  ->orWhere('expires_at', '<', now());
            })->delete();

        $raw   = Str::random(64);
        $hash  = hash('sha256', $raw);
        $exp   = Carbon::now()->addMinutes($ttlMinutes);

        PasswordResetToken::create([
            'user_id'    => $user->id,
            'token_hash' => $hash,
            'expires_at' => $exp,
        ]);

        return $raw; // لینک تلگرام با این RAW ساخته می‌شود
    }

    public function findValid(string $raw): ?PasswordResetToken
    {
        $hash = hash('sha256', $raw);

        /** @var PasswordResetToken|null $rec */
        $rec = PasswordResetToken::where('token_hash', $hash)->first();

        if (!$rec) return null;
        if ($rec->used_at) return null;
        if ($rec->expires_at->isPast()) return null;

        return $rec;
    }

    public function markUsed(PasswordResetToken $rec): void
    {
        $rec->used_at = now();
        $rec->save();
    }
}
