<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PanelRepsController extends Controller
{
    /**
     * GET /api/panel/my-reps
     * params:
     *  - status: all|approved|pending|rejected  (default: all)
     *  - page:   int (default: 1)
     *  - per_page: int (default: 15)
     *
     * خروجی:
     *  data: [ { id, name, code, commission_percent, total_commission, joined_at, status } ]
     *  pagination: { page, per_page, total, last_page }
     */
    public function index(Request $request)
    {
        $me = $request->user();
        if (!$me) {
            return response()->json(['ok' => false, 'message' => 'unauthorized'], 401);
        }

        $status   = strtolower((string)$request->query('status', 'all'));
        $page     = max(1, (int)$request->query('page', 1));
        $perPage  = max(1, min(100, (int)$request->query('per_page', 15)));

        $wantApproved = in_array($status, ['all','approved'], true);
        $wantPending  = in_array($status, ['all','pending'], true);
        $wantRejected = in_array($status, ['all','rejected'], true);

        $rows = [];

        // === Approved: از panel_users (نمایندگانی که تایید شده‌اند و حساب پنلی دارند) ===
        if ($wantApproved) {
            $approved = DB::table('panel_users as u')
                ->leftJoin('panel_referrals as r', function($join) {
                    // برای گرفتن decided_atِ تایید. اگر رکورد پیدا نشد از created_at کاربر استفاده می‌کنیم.
                    $join->on('r.referee_email', '=', 'u.email')
                         ->orOn('r.referee_code',  '=', 'u.code');
                })
                ->where('u.referred_by_id', $me->id)
                ->select([
                    'u.id',
                    DB::raw('COALESCE(NULLIF(TRIM(u.name), ""), u.email) as name'),
                    'u.code',
                    'u.ref_commission_rate as commission_percent',
                    DB::raw("CASE WHEN r.status='approved' THEN r.decided_at ELSE u.created_at END as joined_at")
                ])
                ->get();

            $approvedIds = $approved->pluck('id')->all();

            // مجموع کمیسیون برای هر نماینده‌ی تاییدشده:
            // از رسیدهای کیف‌پول (panel_wallet_receipts) که کمیسیون‌شان پرداخت و تراکنش آن ثبت شده
            // و تراکنش مربوطه از نوع referrer_commission برای همین معرّف است.
            $totals = [];
            if (!empty($approvedIds)) {
                $totals = DB::table('panel_wallet_receipts as wr')
                    ->join('panel_transactions as t', 't.id', '=', 'wr.commission_tx_id')
                    ->where('wr.status', 'verified')
                    ->where('wr.commission_paid', 1)
                    ->whereNotNull('wr.commission_tx_id')
                    ->where('t.type', 'referrer_commission')
                    ->where('t.panel_user_id', $me->id)
                    ->whereIn('wr.user_id', $approvedIds)
                    ->select('wr.user_id', DB::raw('SUM(t.amount) as amount'))
                    ->groupBy('wr.user_id')
                    ->pluck('amount', 'wr.user_id')   // ✅ مهم: pluck با alias
                    ->toArray();
            }

            foreach ($approved as $it) {
                $rows[] = [
                    'id'                  => (int)$it->id,
                    'name'                => (string)$it->name,
                    'code'                => (string)($it->code ?? ''),
                    'commission_percent'  => is_null($it->commission_percent) ? null : (float)$it->commission_percent,
                    'total_commission'    => (int)($totals[$it->id] ?? 0),
                    'joined_at'           => $it->joined_at ? (string)$it->joined_at : null,
                    'status'              => 'approved',
                ];
            }
        }

        // === Pending/Rejected: از panel_referrals (هنوز کاربر پنلی ندارند یا رد شده‌اند) ===
        if ($wantPending || $wantRejected) {
            $statuses = [];
            if ($wantPending)  $statuses[] = 'pending';
            if ($wantRejected) $statuses[] = 'rejected';

            if (!empty($statuses)) {
                $refs = DB::table('panel_referrals')
                    ->where('referrer_id', $me->id)
                    ->whereIn('status', $statuses)
                    ->select([
                        'id',
                        DB::raw('COALESCE(NULLIF(TRIM(referee_name), ""), referee_email) as name'),
                        'referee_code as code',
                        'decided_at',
                        'status'
                    ])
                    ->get();

                foreach ($refs as $it) {
                    $rows[] = [
                        'id'                  => (int)$it->id,
                        'name'                => (string)$it->name,
                        'code'                => (string)($it->code ?? ''),
                        'commission_percent'  => null,
                        'total_commission'    => 0,
                        'joined_at'           => $it->decided_at ? (string)$it->decided_at : null, // برای pending ممکن است تهی باشد
                        'status'              => (string)$it->status,
                    ];
                }
            }
        }

        // مرتب‌سازی: جدیدترین در بالا بر اساس joined_at (خالی‌ها انتهای لیست)
        usort($rows, function($a, $b) {
            $ta = $a['joined_at'] ? strtotime($a['joined_at']) : -1;
            $tb = $b['joined_at'] ? strtotime($b['joined_at']) : -1;
            return $tb <=> $ta;
        });

        // صفحه‌بندی در PHP (برای سایزهای معمولی لیست کاملاً کافی است)
        $total     = count($rows);
        $lastPage  = max(1, (int)ceil($total / $perPage));
        $offset    = ($page - 1) * $perPage;
        $pagedData = array_slice($rows, $offset, $perPage);

        return response()->json([
            'data' => array_values($pagedData),
            'pagination' => [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }
}
