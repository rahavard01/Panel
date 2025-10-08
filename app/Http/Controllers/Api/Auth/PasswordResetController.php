<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PasswordResetService;
use App\Services\TelegramNotifier; // استفاده از سرویس فعلی خودت
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public function __construct(
        protected PasswordResetService $resetService,
        protected TelegramNotifier $telegram // فرض بر این که قبلاً داریش
    ) {}

    public function forgot(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        /** @var User|null $user */
        $user = User::where('email', $data['email'])->first();

        if (!$user) {
            return response()->json(['code' => 'not_found', 'message' => 'کاربری با این ایمیل یافت نشد.'], 404);
        }

        // تلاش برای یافتن telegram_user_id
        $telegramId = null;

        if (Schema::hasColumn($user->getTable(), 'telegram_user_id')) {
            $telegramId = $user->telegram_user_id;
        }

        // در صورت نبود ستون/مقدار، تلاش اختیاری برای واکشی از panel_users با ایمیل یکسان
        if (!$telegramId && class_exists(\App\Models\PanelUser::class)) {
            $panelUser = \App\Models\PanelUser::where('email', $user->email)->first();
            if ($panelUser && Schema::hasColumn($panelUser->getTable(), 'telegram_user_id')) {
                $telegramId = $panelUser->telegram_user_id;
            }
        }

        if (!$telegramId) {
            return response()->json(['code' => 'no_telegram', 'message' => 'ایمیل شما به تلگرام متصل نیست.'], 200);
        }

        // ساخت توکن 5 دقیقه‌ای
        $rawToken = $this->resetService->createTokenFor($user, 5);

        $link = rtrim(config('app.url'), '/') . '/reset-password?token=' . urlencode($rawToken);
        $text = "🔐 بازیابی رمز عبور\n\n"
              . "با استفاده از لینک زیر رمز عبور خود را بازیابی کنید:\n\n"
              . $link;

        try {
            $this->telegram->sendTextTo((int) $telegramId, $text);
        } catch (\Throwable $e) {
            \Log::error('TG reset send failed', [
                'chat' => $telegramId,
                'err'  => $e->getMessage(),
            ]);
            return response()->json(['code' => 'telegram_error', 'message' => 'ارسال پیام تلگرام ناموفق بود.'], 500);
        }

        return response()->json(['code' => 'sent', 'message' => 'لینک بازیابی به تلگرام شما ارسال شد.']);
    }

    public function verifyToken(Request $request)
    {
        $request->validate([
            'token' => ['required', 'string'],
        ]);

        $rec = $this->resetService->findValid($request->string('token'));
        if (!$rec) {
            // اینجا می‌تونیم تشخیص used/expired بدیم، ولی برای سادگی پیام جنریک می‌دهیم
            return response()->json(['valid' => false, 'code' => 'invalid_or_expired'], 410);
        }

        return response()->json(['valid' => true]);
    }

    public function reset(Request $request)
    {
        $data = $request->validate([
            'token'                 => ['required', 'string'],
            'password'              => ['required', 'string', 'min:4', 'confirmed'],
            // password_confirmation هم لازم است
        ], [
            'password.min' => 'رمز عبور باید حداقل ۴ کاراکتر باشد.',
        ]);

        $rec = $this->resetService->findValid($data['token']);
        if (!$rec) {
            return response()->json(['ok' => false, 'code' => 'invalid_or_expired'], 410);
        }

        $user = $rec->user;
        $user->password = Hash::make($data['password']);
        $user->save();

        $this->resetService->markUsed($rec);

        return response()->json(['ok' => true]);
    }
}
