<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardAdminController extends Controller
{
    public function cards(Request $req)
    {
        // ===== Config & constants =====
        $cfg = config('dashboard');
        $T_USERS    = $cfg['tables']['users'] ?? 'v2_user';
        $T_PUSERS   = $cfg['tables']['panel_users'] ?? 'panel_users';
        $T_TRX      = $cfg['tables']['transactions'] ?? 'panel_transactions';
        $T_RECEIPTS = $cfg['tables']['wallet_receipts'] ?? 'panel_wallet_receipts';
        $T_REFS     = $cfg['tables']['referrals'] ?? 'panel_referrals';

        $TYPE_PURCHASE = $cfg['transaction_types']['purchase'] ?? 'account_purchase';
        $TYPE_RENEWAL  = $cfg['transaction_types']['renewal'] ?? 'account_extend';

        $STATUS_SUCCESS = $cfg['statuses']['success'] ?? 'success';
        $STATUS_SUBMIT  = $cfg['statuses']['pending_receipt'] ?? 'submitted';
        $STATUS_REF     = $cfg['statuses']['pending_referral'] ?? 'pending';

        $tz = $cfg['tz'] ?? 'Asia/Tehran';
        $presenceWindow = (int)($cfg['presence_window_sec'] ?? 60);

        // Always do time math in UTC inside MySQL
        DB::statement("SET time_zone = '+00:00'");

        // Now in Tehran (for boundaries / fallbacks)
        $nowTeh = Carbon::now($tz);
        $today0 = $nowTeh->copy()->startOfDay();
        $today1 = $nowTeh->copy()->endOfDay();

        // Gregorian prev day
        $y0 = $today0->copy()->subDay();
        $y1 = $today0->copy()->subSecond();

        // Jalaali month (provided by FE; fallback to Gregorian month if FE missed)
        $m0 = $nowTeh->copy()->startOfMonth();
        $m1 = $nowTeh->copy();
        $pm0 = $m0->copy()->subMonthNoOverflow()->startOfMonth();
        $pm1 = $m0->copy()->subSecond();

        // ---- Parse ISO/UTC coming from FE (we work in UTC everywhere in SQL) ----
        $parseIsoUtc = function (?string $s): ?Carbon {
            if (!$s) return null;
            try { return Carbon::parse($s)->utc(); } catch (\Throwable $e) { return null; }
        };

// فقط امروز و دیروز را از فرانت قبول کن (اگر آمد)
$tStart  = $parseIsoUtc($req->query('jtodayStart'))     ?: $today0->copy()->utc();
$tEnd    = $parseIsoUtc($req->query('jtodayEnd'))       ?: $today1->copy()->utc();
$yStart  = $parseIsoUtc($req->query('jyesterdayStart')) ?: $y0->copy()->utc();
$yEnd    = $parseIsoUtc($req->query('jyesterdayEnd'))   ?: $y1->copy()->utc();

// ماه جاری و ماه قبل همیشه توسط سرور تعیین شود
$mStart  = $m0->copy()->utc();
$mEnd    = $m1->copy()->utc();
$pmStart = $pm0->copy()->utc();
$pmEnd   = $pm1->copy()->utc();

        // Safety: اگر ماه == امروز شد، در سرور تصحیح کن به ماه‌جاری واقعی
        if ($mStart->isSameDay($tStart) && $mEnd->isSameDay($tEnd)) {
            $mStart = $m0->copy()->utc();
            $mEnd   = $m1->copy()->utc();
        }

        // ---- Reusable helpers ----
        $createdCol = 'created_at';
        $colRef     = DB::getQueryGrammar()->wrap($createdCol);
        $tsExpr     = "UNIX_TIMESTAMP($colRef)";

        $betweenTs = function ($q, Carbon $fromUtc, Carbon $toUtc) use ($tsExpr) {
            // inclusive range
            return $q->whereRaw("$tsExpr BETWEEN ? AND ?", [$fromUtc->timestamp, $toUtc->timestamp]);
        };

        $pct = function (int|float $cur, int|float $prev): float {
            if ($prev == 0) return $cur > 0 ? 100.0 : 0.0;
            return round((($cur - $prev) / $prev) * 100, 2);
        };

        // Count affected users from transactions (meta.user_ids OR quantity)
        $sumUserOps = function (string $type, Carbon $fromUtc, Carbon $toUtc) use ($T_TRX, $STATUS_SUCCESS, $betweenTs) {
            try {
                return (int) DB::table($T_TRX)
                    ->where('type', $type)
                    ->where('status', $STATUS_SUCCESS)
                    ->tap(fn($q) => $betweenTs($q, $fromUtc, $toUtc))
                    ->sum(DB::raw("
                        COALESCE(
                            JSON_LENGTH(JSON_EXTRACT(meta, '$.user_ids')),
                            quantity,
                            1
                        )
                    "));
            } catch (\Throwable $e) {
                // Fallback if JSON functions unavailable
                return (int) DB::table($T_TRX)
                    ->where('type', $type)
                    ->where('status', $STATUS_SUCCESS)
                    ->tap(fn($q) => $betweenTs($q, $fromUtc, $toUtc))
                    ->count();
            }
        };

        $sumDeposits = function (Carbon $fromUtc, Carbon $toUtc) use ($T_TRX, $STATUS_SUCCESS, $betweenTs) {
            return (int) DB::table($T_TRX)
                ->where('type', 'wallet_topup_card')
                ->where('status', $STATUS_SUCCESS)
                ->tap(fn($q) => $betweenTs($q, $fromUtc, $toUtc))
                ->sum('amount');
        };

        // ---- Metrics ----
        // New users (purchases)
        $nu_today = $sumUserOps($TYPE_PURCHASE, $tStart, $tEnd);
        $nu_yest  = $sumUserOps($TYPE_PURCHASE, $yStart, $yEnd);
        $nu_m     = $sumUserOps($TYPE_PURCHASE, $mStart, $mEnd);
        $nu_pm    = $sumUserOps($TYPE_PURCHASE, $pmStart, $pmEnd);

        // Renewals
        $re_today = $sumUserOps($TYPE_RENEWAL, $tStart, $tEnd);
        $re_yest  = $sumUserOps($TYPE_RENEWAL, $yStart, $yEnd);
        $re_m     = $sumUserOps($TYPE_RENEWAL, $mStart, $mEnd);
        $re_pm    = $sumUserOps($TYPE_RENEWAL, $pmStart, $pmEnd);

        // Deposits (IRT)
        $dep_m  = $sumDeposits($mStart, $mEnd);
        $dep_pm = $sumDeposits($pmStart, $pmEnd);

        // Pending counters (cached very briefly)
        $pending = Cache::remember('dash:pending', 10, function () use ($T_RECEIPTS, $T_REFS, $STATUS_SUBMIT, $STATUS_REF) {
            return [
                'deposits'        => (int) DB::table($T_RECEIPTS)->where('status', $STATUS_SUBMIT)->count(),
                'partnerRequests' => (int) DB::table($T_REFS)->where('status', $STATUS_REF)->count(),
            ];
        });

        // Panel status (cached briefly)
        $panelStatus = Cache::remember('dash:panel', 10, function () use ($T_PUSERS, $T_USERS, $nowTeh, $presenceWindow) {
            $nowUtc = Carbon::now('UTC');
            $usersTotal   = (int) DB::table($T_USERS)->count();
            $partnersTotal= (int) DB::table($T_PUSERS)->where('role', 2)->count();
            $usersOnline  = (int) DB::table($T_USERS)->where('t', '>', $nowUtc->timestamp - $presenceWindow)->count();
            $usersExpired = (int) DB::table($T_USERS)->where('expired_at', '>', 0)->where('expired_at', '<', $nowUtc->timestamp)->count();
            return compact('partnersTotal','usersTotal','usersOnline','usersExpired');
        });

        // ---- Response ----
        return response()->json([
            'panelStatus' => $panelStatus,

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

            'deposits' => [
                'thisMonthToman' => $dep_m,
                'vsPrevMonthPct' => $pct($dep_m, $dep_pm),
            ],

            'pending' => $pending,

            'meta' => [
                'generatedAt' => $nowTeh->toIso8601String(),
                // Debug: UTC ranges actually used:
                'ranges' => [
                    'today'     => ['from'=>$tStart->toIso8601String(),'to'=>$tEnd->toIso8601String()],
                    'yesterday' => ['from'=>$yStart->toIso8601String(),'to'=>$yEnd->toIso8601String()],
                    'month'     => ['from'=>$mStart->toIso8601String(),'to'=>$mEnd->toIso8601String()],
                    'prevMonth' => ['from'=>$pmStart->toIso8601String(),'to'=>$pmEnd->toIso8601String()],
                ],
                'pollSeconds' => 60,
            ],
        ]);
    }
}
