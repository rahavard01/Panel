<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AppController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();

        // اگر لاگین نیست
        if (!$user) {
            $bootstrap = [
                'guest' => true,
                'user'  => null,
                'wallet'=> ['balance' => null],
                'pending_counts' => ['topups'=>0, 'referrals'=>0, 'commissions'=>0],
            ];
            return view('app', compact('bootstrap'));
        }

        // اطلاعات سبکِ نمایشی
        $userLite = [
            'id'    => $user->id,
            'name'  => (string)($user->name ?? ''),
            'email' => (string)($user->email ?? ''),
            'code'  => (string)($user->code ?? ''), // اگر ستونه توی panel_users هست
        ];

        // اعتبار کیف پول: طبق کدت در PanelController از خودِ مدل User فیلد credit استفاده می‌کنی
        $balance = (int)($user->credit ?? 0);

        // شمارنده‌های Pending
        $pendingTopups = 0;
        if (Schema::hasTable('panel_wallet_receipts')) {
            $pendingTopups = (int) DB::table('panel_wallet_receipts')
                ->where('status', 'pending')->count();
        }

        $pendingReferrals = 0;
        if (Schema::hasTable('panel_referrals')) {
            $pendingReferrals = (int) DB::table('panel_referrals')
                ->where('status', 'pending')->count();
        }

        // کمیسیون‌های در انتظار: رسیدهای تأیید شده‌ای که کمیسیون‌شان هنوز پرداخت نشده
        $pendingCommissions = 0;
        if (Schema::hasTable('panel_wallet_receipts')) {
            $pendingCommissions = (int) DB::table('panel_wallet_receipts')
                ->where('status', 'verified')
                ->where(function($q){
                    $q->whereNull('commission_paid')
                      ->orWhere('commission_paid', false);
                })
                ->count();
        }

        $bootstrap = [
            'guest' => false,
            'user'  => $userLite,
            'wallet'=> ['balance' => $balance],
            'pending_counts' => [
                'topups'      => $pendingTopups,
                'referrals'   => $pendingReferrals,
                'commissions' => $pendingCommissions,
            ],
        ];

        return view('app', compact('bootstrap'));
    }
}
