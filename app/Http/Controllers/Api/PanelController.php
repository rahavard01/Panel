<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\V2User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\PanelAnnouncement;


class PanelController extends Controller
{
    public function getProfile(Request $request)
    {
        try {
            $user = $request->user();
            $uid = $user->id ?? null;

            $pendingAnnouncements = PanelAnnouncement::publishedNow()
                ->when($uid, fn($q) => $q->whereDoesntHave('reads', fn($qq) =>
                    $qq->where('user_id', $uid)->whereNotNull('read_at')
                ))
                ->count();

            if (!$user) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Unauthorized',
                ], 401);
            }

            return response()->json([
                'status' => 'ok',
                'user'   => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                    'code'  => $user->code ?? null,
                ],
                'pending_counts' => [
                    'announcements' => $pendingAnnouncements, // ← شمارنده بج «اعلانات نخوانده»
                ],
            ], 200);

        } catch (\Throwable $e) {
            \Log::error('api/user/panel failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Server error',
            ], 500);
        }
    }

    public function getWallet(Request $request)
    {
        $user = $request->user(); // کاربر احراز هویت‌شده
        return response()->json([
            'status' => 'success',
            'credit' => (int)($user->credit ?? 0), // اگه ستون اسمش چیز دیگه‌ایه همینجا عوضش کن
        ]);
    }

    public function listUsers(Request $request)
    {
        $me = $request->user();
        if (!$me) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $code = $me->code ?? null; // همون کدی که بالای پنل توی دایره نشون می‌دیم
        if (!$code) {
            return response()->json(['status' => 'ok', 'data' => []], 200);
        }

        // اگر ستون created_at دارید، همین را نگه دارید. اگر ندارید، موقتاً با id سورت کنید.
        $query = V2User::query()
            ->where('email', 'like', '%'.$code.'%')
            ->orderBy('created_at', 'asc');

        $rows = $query->get([
            'id', 'email', 'banned', 'expired_at', 'u', 'd', 'transfer_enable', 'created_at', 't'
        ]);

        return response()->json([
            'status' => 'ok',
            'data'   => $rows,
        ], 200);
    }

    public function findByToken(Request $request)
    {
        $token = trim((string)$request->query('token', ''));
        if ($token === '') {
            return response()->json(['status' => 'ok', 'data' => []]);
        }

        $user = DB::table('v2_user')->where('token', $token)->first();

        if (!$user) {
            return response()->json(['status' => 'ok', 'data' => []]);
        }

        return response()->json(['status' => 'ok', 'data' => [$user]]);
    }

    public function findByEmail(Request $request)
    {
        $me = $request->user();
        if (!$me) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $email = trim((string) $request->query('email', ''));
        if ($email === '') {
            return response()->json(['status' => 'ok', 'data' => []], 200);
        }

        // اگر ادمین نیست، ایمیل باید شامل code خودش باشد (همسو با listUsers که %code% استفاده می‌کند)
        if ((int)($me->role ?? 2) !== 1) {
            $code = $me->code ?? '';
            if ($code !== '' && !\Illuminate\Support\Str::contains(strtolower($email), strtolower($code))) {
                // دسترسی ندارد → خروجی خالی (مثل قبل)
                return response()->json(['status' => 'ok', 'data' => []], 200);
            }
        }

        $u = \DB::table('v2_user')
            ->where('email', $email)
            ->select('email', 'token')
            ->first();

        if (!$u || empty($u->token)) {
            return response()->json(['status' => 'ok', 'data' => []], 200);
        }

        return response()->json(['status' => 'ok', 'data' => [$u]], 200);
    }


    public function updateBan(Request $request)
    {
        $me = $request->user();
        if (!$me) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'email'  => 'required|email',
            'banned' => 'required|in:0,1',
        ]);

        // اگر ادمین نیست، ایمیل باید شامل code خودش باشد (همسو با listUsers که %code% استفاده می‌کند)
        $role = (int)($me->role ?? 2);
        if ($role !== 1) {
            $code = trim((string)($me->code ?? ''));
            // ✅ مثل findByEmail: فقط وقتی محدود کنیم که code خالی نباشه
            if ($code !== '' && !Str::contains(Str::lower($validated['email']), Str::lower($code))) {
                return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
            }
            // اگر code خالیه، اینجا منع نمی‌کنیم تا رفتار با بقیهٔ مسیرها یکدست بمونه
        }

        $affected = DB::table('v2_user')
            ->where('email', $validated['email'])
            ->update(['banned' => (int) $validated['banned']]);

        if ($affected === 0) {
            return response()->json(['status' => 'error', 'message' => 'Not found'], 404);
        }

        return response()->json(['status' => 'ok']);
    }

    public function changePassword(\Illuminate\Http\Request $request)
    {
        $me = $request->user();
        if (!$me) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $v = Validator::make($request->all(), [
            'current_password'           => 'required|string',
            'new_password'               => 'required|string|min:4|confirmed', // نیازمند new_password_confirmation
        ], [
            'new_password.min'           => 'رمز جدید باید حداقل ۴ کاراکتر باشد',
            'new_password.confirmed'     => 'تأیید رمز جدید مطابقت ندارد',
            'current_password.required'  => 'رمز فعلی را وارد کنید',
        ]);

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        $email = $me->email ?? null;
        if (!$email) {
            return response()->json(['status' => 'error', 'message' => 'ایمیل کاربر نامشخص است'], 400);
        }

        $panelUser = DB::table('panel_users')->where('email', $email)->first();
        if (!$panelUser) {
            return response()->json(['status' => 'error', 'message' => 'کاربر یافت نشد'], 404);
        }

        if (!Hash::check($request->input('current_password'), $panelUser->password)) {
            return response()->json(['status' => 'error', 'message' => 'رمز عبور فعلی اشتباه است'], 422);
        }

        DB::table('panel_users')->where('id', $panelUser->id)->update([
            'password'   => Hash::make($request->input('new_password')),
            'updated_at' => now(),
        ]);

        // اختیاری: همگام‌سازی با جدول users اگر وجود دارد
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('users')) {
                DB::table('users')->where('email', $email)->update([
                    'password'   => Hash::make($request->input('new_password')),
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {}

        return response()->json(['status' => 'success', 'message' => 'رمز عبور با موفقیت تغییر کرد']);
    }

}
