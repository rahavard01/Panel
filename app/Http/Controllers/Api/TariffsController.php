<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\PanelPricingService;
use Illuminate\Support\Facades\Schema;


class TariffsController extends Controller
{
    /**
     * GET /api/tariffs
     * خروجی فقط برای نمایش قیمت‌ها در پنل
     */
    public function show(Request $request, PanelPricingService $pricing)
    {
        $me = $request->user();
        if (!$me) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        // panel_user مرتبط با یوزر لاگین
        $panelUser = DB::table('panel_users')->where('email', $me->email)->first();
        if (!$panelUser) {
            return response()->json(['status' => 'error', 'message' => 'Panel user not found'], 404);
        }

        // ترتیب موردنظر شما
        $plansOrder = [
            ['key' => 'test', 'title' => 'اکانت تست',    'duration_days' => 3],
            ['key' => '1m',   'title' => 'اکانت یک‌ماهه', 'duration_days' => 30],
            ['key' => '3m',   'title' => 'اکانت سه‌ماهه', 'duration_days' => 90],
            ['key' => '6m',   'title' => 'اکانت شش‌ماهه', 'duration_days' => 180],
            ['key' => '12m',  'title' => 'اکانت یک‌ساله', 'duration_days' => 365],
        ];

        // کلیدهای پلن‌ها برای جستجو
        $keys = array_column($plansOrder, 'key');

        // خواندن ستون‌های جدول برای تشخیص نام‌های درست
        $planRows = collect();
        try {
            $cols = Schema::getColumnListing('panel_plan');

            // ستون کلید: ترجیح با plan_key
            $keyCol = in_array('plan_key', $cols) ? 'plan_key'
                    : (in_array('key', $cols) ? 'key'
                    : (in_array('slug', $cols) ? 'slug' : null));

            // ستون توضیح: details → description → desc → name (fallback)
            $detCol = in_array('details', $cols) ? 'details'
                    : (in_array('description', $cols) ? 'description'
                    : (in_array('desc', $cols) ? 'desc'
                    : (in_array('name', $cols) ? 'name' : null)));

            if ($keyCol && $detCol) {
                // در DB: k = کلید پلن، d = توضیح/نام
                $planRows = DB::table('panel_plan')
                    ->whereIn($keyCol, $keys)
                    ->select([$keyCol.' as k', $detCol.' as d'])
                    ->get()
                    ->keyBy('k');
            }
        } catch (\Throwable $e) {
            \Log::warning('TariffsController: plan details query failed: '.$e->getMessage());
            $planRows = collect();
        }

        // قیمت هر گیگ ترافیک از ستون panel_users.traffic_price
        $trafficPrice = $panelUser->traffic_price ?? null;
        $trafficFinal = is_null($trafficPrice) ? null : (int)$trafficPrice;

        // قیمت نهایی هر پلن با همان منطق (شخصی/دیفالت)
        $plansOut = [];
        foreach ($plansOrder as $pl) {
            $priceStr = $pricing->quoteUnitPrice((int)$panelUser->id, $pl['key']);
            $final = is_null($priceStr) ? null : (int)$priceStr;

            $details = optional($planRows->get($pl['key']))->d; // به‌جای ->details
            $plansOut[] = [
                'key'           => $pl['key'],
                'title'         => $pl['title'],
                'duration_days' => $pl['duration_days'],
                'final_price'   => $final,
                'details'       => $details,       // 👈 اضافه شد
            ];
        }

        return response()->json([
            'traffic' => [
                'price_per_gb' => $trafficFinal,
            ],
            'plans' => $plansOut,
        ]);
    }
}
