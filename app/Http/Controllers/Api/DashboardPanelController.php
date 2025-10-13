<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class DashboardPanelController extends Controller
{
    public function cards(Request $req)
    {
        // ===== پایه =====
        $cfg = config('dashboard');
        $T_USERS    = $cfg['tables']['users']              ?? 'v2_user';
        $T_PUSERS   = $cfg['tables']['panel_users']        ?? 'panel_users';
        $T_TRX      = $cfg['tables']['transactions']       ?? 'panel_transactions';
        $T_RECEIPTS = $cfg['tables']['wallet_receipts']    ?? 'panel_wallet_receipts';

        $TYPE_PURCHASE = $cfg['transaction_types']['purchase'] ?? 'account_purchase';
        $TYPE_RENEWAL  = $cfg['transaction_types']['renewal']  ?? 'account_extend';
        $TYPE_TOPUP    = 'wallet_topup_card';

        $STATUS_SUCCESS = $cfg['statuses']['success'] ?? 'success';
        $STATUS_SUBMIT  = $cfg['statuses']['pending_receipt'] ?? 'submitted';

        $tz = $cfg['tz'] ?? 'Asia/Tehran';
        $presenceWindow = (int)($cfg['presence_window_sec'] ?? 60);

        // کاربر فعلی پنل
        /** @var \App\Models\PanelUser|\Illuminate\Contracts\Auth\Authenticatable $me */
        $me   = $req->user();
        $pid  = (int)($me->id ?? 0);
        $code = (string)($me->code ?? '');

        // همۀ محاسبات SQL در UTC
        DB::statement("SET time_zone = '+00:00'");

        // مرزبندی زمانی (به وقت تهران)
        $nowTeh = Carbon::now($tz);

        $tStart  = $nowTeh->copy()->startOfDay()->utc();
        $tEnd    = $nowTeh->copy()->endOfDay()->utc();

        $yStart  = $nowTeh->copy()->subDay()->startOfDay()->utc();
        $yEnd    = $nowTeh->copy()->startOfDay()->subSecond()->utc();

        $mStart  = $nowTeh->copy()->startOfMonth()->utc();
        $mEnd    = $nowTeh->copy()->endOfDay()->utc();

        $pmStart = $nowTeh->copy()->startOfMonth()->subMonthNoOverflow()->startOfMonth()->utc();
        $pmEnd   = $nowTeh->copy()->startOfMonth()->subSecond()->utc();

        // هِلپرها
        $pct = function (int|float $cur, int|float $prev): float {
            if ($prev == 0) return $cur > 0 ? 100.0 : 0.0;
            return round((($cur - $prev) / $prev) * 100, 2);
        };

        $betweenTs = function ($q, Carbon $fromUtc, Carbon $toUtc) {
            return $q->whereBetween(DB::raw('UNIX_TIMESTAMP(created_at)'), [$fromUtc->timestamp, $toUtc->timestamp]);
        };

        // --- تراکنش‌ها (فقط مربوط به همین پنل) ---
        $sumUserOps = function (string $type, Carbon $fromUtc, Carbon $toUtc) use ($T_TRX, $STATUS_SUCCESS, $betweenTs, $pid, $code) {
            try {
                return (int) DB::table($T_TRX)
                    ->where('type', $type)
                    ->where('status', $STATUS_SUCCESS)
                    // اسکوپ: یا با id خود پنل، یا با code داخل متا
                    ->where(function ($q) use ($pid, $code) {
                        $q->where('panel_user_id', $pid)
                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta,'$.panel_code')) = ?", [$code]);
                    })
                    ->tap(fn($q) => $betweenTs($q, $fromUtc, $toUtc))
                    ->sum(DB::raw("COALESCE(JSON_LENGTH(JSON_EXTRACT(meta,'$.user_ids')), quantity, 1)"));
            } catch (\Throwable $e) {
                return (int) DB::table($T_TRX)
                    ->where('type', $type)
                    ->where('status', $STATUS_SUCCESS)
                    ->where(function ($q) use ($pid, $code) {
                        $q->where('panel_user_id', $pid)
                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta,'$.panel_code')) = ?", [$code]);
                    })
                    ->tap(fn($q) => $betweenTs($q, $fromUtc, $toUtc))
                    ->count();
            }
        };

        $sumTopups = function (Carbon $fromUtc, Carbon $toUtc) use ($T_TRX, $STATUS_SUCCESS, $betweenTs, $pid, $code, $TYPE_TOPUP) {
            return (int) DB::table($T_TRX)
                ->where('type', $TYPE_TOPUP)
                ->where('status', $STATUS_SUCCESS)
                ->where(function ($q) use ($pid, $code) {
                    $q->where('panel_user_id', $pid)
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta,'$.panel_code')) = ?", [$code]);
                })
                ->tap(fn($q) => $betweenTs($q, $fromUtc, $toUtc))
                ->sum('amount');
        };

        // --- وضعیت کاربران تحت مدیریت این پنل ---
        $emailLike = '%@' . $code . '.com';

        $usersTotal = (int) DB::table($T_USERS)
            ->where('email', 'LIKE', $emailLike)
            ->count();

        $nowUtc = Carbon::now('UTC')->timestamp;

        $usersOnline = (int) DB::table($T_USERS)
            ->where('email', 'LIKE', $emailLike)
            ->where('t', '>', $nowUtc - $presenceWindow)
            ->count();

        $usersExpired = (int) DB::table($T_USERS)
            ->where('email', 'LIKE', $emailLike)
            ->where('expired_at', '>', 0)
            ->where('expired_at', '<', $nowUtc)
            ->count();

        // --- KPIها ---
        $nu_today = $sumUserOps($TYPE_PURCHASE, $tStart, $tEnd);
        $nu_yest  = $sumUserOps($TYPE_PURCHASE, $yStart, $yEnd);
        $nu_m     = $sumUserOps($TYPE_PURCHASE, $mStart, $mEnd);
        $nu_pm    = $sumUserOps($TYPE_PURCHASE, $pmStart, $pmEnd);

        $re_today = $sumUserOps($TYPE_RENEWAL, $tStart, $tEnd);
        $re_yest  = $sumUserOps($TYPE_RENEWAL, $yStart, $yEnd);
        $re_m     = $sumUserOps($TYPE_RENEWAL, $mStart, $mEnd);
        $re_pm    = $sumUserOps($TYPE_RENEWAL, $pmStart, $pmEnd);

        $top_m    = $sumTopups($mStart, $mEnd);
        $top_pm   = $sumTopups($pmStart, $pmEnd);

        // --- پاسخ ---
        return response()->json([
            'panelStatus' => [
                'usersTotal'   => $usersTotal,
                'usersOnline'  => $usersOnline,
                'usersExpired' => $usersExpired,
            ],

            'wallet' => [
                'thisMonthToman' => $top_m,
                'vsPrevMonthPct' => $pct($top_m, $top_pm),
            ],

            // هیچ «در انتظار تأیید»ی برای پنل نمایش نمی‌دهیم
            'pending' => [
                'deposits'        => 0,
                'partnerRequests' => 0,
            ],

            'newUsers' => [
                'today'          => $nu_today,
                'vsYesterdayPct' => $pct($nu_today, $nu_yest),
                'thisMonth'      => $nu_m,
                'vsPrevMonthPct' => $pct($nu_m, $nu_pm),
            ],

            'renewals' => [
                'today'          => $re_today,
                'vsYesterdayPct' => $pct($re_today, $re_yest),
                'thisMonth'      => $re_m,
                'vsPrevMonthPct' => $pct($re_m, $re_pm),
            ],

            'meta' => [
                'generatedAt' => $nowTeh->toIso8601String(),
                'ranges' => [
                    'today'     => ['from'=>$tStart->toIso8601String(),'to'=>$tEnd->toIso8601String()],
                    'yesterday' => ['from'=>$yStart->toIso8601String(),'to'=>$yEnd->toIso8601String()],
                    'month'     => ['from'=>$mStart->toIso8601String(),'to'=>$mEnd->toIso8601String()],
                    'prevMonth' => ['from'=>$pmStart->toIso8601String(),'to'=>$pmEnd->toIso8601String()],
                ],
                'pollSeconds' => $presenceWindow,
            ],
        ]);
    }
}
