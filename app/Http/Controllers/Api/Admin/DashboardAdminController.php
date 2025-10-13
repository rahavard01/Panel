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

        // ===== Tehran-based boundaries, then convert to UTC for SQL =====
        $nowTeh = Carbon::now($tz);
        $today0 = $nowTeh->copy()->startOfDay();
        $today1 = $nowTeh->copy()->endOfDay();

        $y0 = $today0->copy()->subDay();
        $y1 = $today0->copy()->subSecond();

        $m0  = $nowTeh->copy()->startOfMonth();
        $m1  = $nowTeh->copy()->endOfDay();
        $pm0 = $m0->copy()->subMonthNoOverflow()->startOfMonth();
        $pm1 = $m0->copy()->subSecond();

        // Server-owned ranges (ignore FE to avoid mismatch)
        $tStart  = $today0->copy()->utc();
        $tEnd    = $today1->copy()->utc();
        $yStart  = $y0->copy()->utc();
        $yEnd    = $y1->copy()->utc();
        $mStart  = $m0->copy()->utc();
        $mEnd    = $m1->copy()->utc();
        $pmStart = $pm0->copy()->utc();
        $pmEnd   = $pm1->copy()->utc();

        // ===== Helpers =====
        $createdCol = 'created_at';
        $colRef     = DB::getQueryGrammar()->wrap($createdCol);

        // Build a fixed offset string for Tehran (DST-safe; Iran has no DST now)
        $offMin = Carbon::now($tz)->utcOffset(); // e.g., +210 for +03:30
        $sign   = $offMin >= 0 ? '+' : '-';
        $hh     = str_pad(intval(abs($offMin) / 60), 2, '0', STR_PAD_LEFT);
        $mm     = str_pad(abs($offMin) % 60, 2, '0', STR_PAD_LEFT);
        $offStr = "{$sign}{$hh}:{$mm}"; // like +03:30

        // Compare created_at (stored as Tehran local time) after converting to UTC epoch
        $betweenTs = function ($q, Carbon $fromUtc, Carbon $toUtc) use ($colRef, $offStr) {
            $epochUtc = "TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', CONVERT_TZ($colRef, '{$offStr}', '+00:00'))";
            return $q->whereRaw("$epochUtc BETWEEN ? AND ?", [$fromUtc->timestamp, $toUtc->timestamp]);
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
        $nu_today = $sumUserOps($TYPE_PURCHASE, $tStart, $tEnd);
        $nu_yest  = $sumUserOps($TYPE_PURCHASE, $yStart, $yEnd);
        $nu_m     = $sumUserOps($TYPE_PURCHASE, $mStart, $mEnd);
        $nu_pm    = $sumUserOps($TYPE_PURCHASE, $pmStart, $pmEnd);

        $re_today = $sumUserOps($TYPE_RENEWAL, $tStart, $tEnd);
        $re_yest  = $sumUserOps($TYPE_RENEWAL, $yStart, $yEnd);
        $re_m     = $sumUserOps($TYPE_RENEWAL, $mStart, $mEnd);
        $re_pm    = $sumUserOps($TYPE_RENEWAL, $pmStart, $pmEnd);

        $dep_m  = $sumDeposits($mStart, $mEnd);
        $dep_pm = $sumDeposits($pmStart, $pmEnd);

        // Pending counters (cached briefly)
        $pending = Cache::remember('dash:pending', 10, function () use ($T_RECEIPTS, $T_REFS, $STATUS_SUBMIT, $STATUS_REF) {
            return [
                'deposits'        => (int) DB::table($T_RECEIPTS)->where('status', $STATUS_SUBMIT)->count(),
                'partnerRequests' => (int) DB::table($T_REFS)->where('status', $STATUS_REF)->count(),
            ];
        });

        // Panel status (cached briefly)
        $panelStatus = Cache::remember('dash:panel', 10, function () use ($T_PUSERS, $T_USERS, $presenceWindow) {
            $nowUtc = Carbon::now('UTC');
            $usersTotal    = (int) DB::table($T_USERS)->count();
            $partnersTotal = (int) DB::table($T_PUSERS)->where('role', 2)->count();
            $usersOnline   = (int) DB::table($T_USERS)->where('t', '>', $nowUtc->timestamp - $presenceWindow)->count();
            $usersExpired  = (int) DB::table($T_USERS)->where('expired_at', '>', 0)->where('expired_at', '<', $nowUtc->timestamp)->count();
            return compact('partnersTotal', 'usersTotal', 'usersOnline', 'usersExpired');
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
