<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TariffsAdminController extends Controller
{
    // کلیدهای معتبر پلن‌ها
    private const PLAN_KEYS = ['gig','test','1m','3m','6m','12m'];

    // GET /api/admin/tariffs
    public function index()
    {
        // گزینه‌های کشویی v2_plan
        $v2 = DB::table('v2_plan')
            ->select(['id','name','transfer_enable'])
            ->orderBy('id')
            ->get()
            ->map(function($r){
                // نمایش: name - transfer_enable G  (همون‌طور که گفتی)
                $label = trim(($r->name ?? '') . ' - ' . (string)($r->transfer_enable ?? 0) . 'G');
                return [
                    'id'    => (int)$r->id,
                    'label' => $label,
                ];
            })
            ->values();

        // خواندن ردیف‌های panel_plan بر اساس plan_key
        $rows = DB::table('panel_plan')
            ->whereIn('plan_key', self::PLAN_KEYS)
            ->get()
            ->keyBy('plan_key');

        // خروجی استاندارد برای UI
        $items = [];
        foreach (self::PLAN_KEYS as $key) {
            $r = $rows[$key] ?? null;
            $items[] = [
                'plan_key'      => $key,
                'default_price' => $r ? (string)($r->default_price ?? '') : '',
                'details'       => $r ? (string)($r->details ?? '') : '',
                'enable'        => $r ? (int)($r->enable ?? 0) : 0,
                'v2_plan_id'    => $key === 'gig' ? null : ($r ? ($r->v2_plan_id ?? null) : null),
            ];
        }

        return response()->json([
            'status'     => 'ok',
            'v2_options' => $v2,
            'items'      => $items,
        ]);
    }

    // PUT /api/admin/tariffs  (ذخیره باکس 1)
    public function update(Request $req)
    {
        $items = $req->input('items', []);
        if (!is_array($items) || empty($items)) {
            return response()->json(['status'=>'error','message'=>'ورودی معتبر نیست'], 422);
        }

        // ایندکس بر اساس plan_key
        $byKey = [];
        foreach ($items as $it) {
            $k = $it['plan_key'] ?? null;
            if ($k && in_array($k, self::PLAN_KEYS, true)) {
                $byKey[$k] = $it;
            }
        }

        // — اعتبارسنجی‌های لازم طبق خواسته‌ات —
        // 1) قیمت همه پلن‌ها باید وارد شده باشد
        foreach (self::PLAN_KEYS as $k) {
            $p = trim((string)($byKey[$k]['default_price'] ?? ''));
            if ($p === '') {
                return response()->json(['status'=>'error','message'=>'قیمت کلیه پلن‌ها را وارد کنید'], 422);
            }
            // فقط اجازه ارقام و نقطه
            if (!preg_match('/^\d+(\.\d+)?$/', $p)) {
                return response()->json(['status'=>'error','message'=>'قیمت‌ها باید عددی باشند'], 422);
            }
        }

        // 2) توضیحات برای همه به‌جز gig اجباری است
        foreach (self::PLAN_KEYS as $k) {
            if ($k === 'gig') continue;
            $d = trim((string)($byKey[$k]['details'] ?? ''));
            if ($d === '') {
                return response()->json(['status'=>'error','message'=>'توضیحات کلیه پلن‌ها را وارد کنید'], 422);
            }
        }

        // 3) v2_plan_id برای همه به‌جز gig اجباری است
        foreach (self::PLAN_KEYS as $k) {
            if ($k === 'gig') continue;
            $v = $byKey[$k]['v2_plan_id'] ?? null;
            if (!$v) {
                return response()->json(['status'=>'error','message'=>'پلن متصل به پنل را برای کلیه پلن‌ها انتخاب کنید'], 422);
            }
        }

        $changed = 0;
        DB::transaction(function() use (&$changed, $byKey) {
            foreach (self::PLAN_KEYS as $k) {
                $payload = [
                    'default_price' => (string)$byKey[$k]['default_price'],
                    'details'       => $k === 'gig' ? '' : (string)$byKey[$k]['details'],
                ];
                if ($k !== 'gig') {
                    $payload['v2_plan_id'] = (int)$byKey[$k]['v2_plan_id'];
                }

                $row = DB::table('panel_plan')->where('plan_key', $k)->first();
                if ($row) {
                    // فقط اگر واقعاً تغییری هست
                    $delta = [];
                    foreach ($payload as $col => $val) {
                        if ($row->$col !== $val) $delta[$col] = $val;
                    }
                    if (!empty($delta)) {
                        $changed += DB::table('panel_plan')->where('id', $row->id)->update($delta);
                    }
                } else {
                    $insert = $payload;
                    $insert['plan_key'] = $k;
                    $insert['enable']   = 0;
                    // اگر برای gig ست نشده بود، صفر کن تا NOT NULL رعایت شود
                    if (!array_key_exists('v2_plan_id', $insert)) {
                        $insert['v2_plan_id'] = 0;
                    }
                    DB::table('panel_plan')->insert($insert);
                    $changed++;
                }
            }
        });

        return response()->json([
            'status'  => 'ok',
            'changed' => $changed > 0,
            'message' => $changed > 0 ? 'تغییرات با موفقیت ذخیره شد' : 'تغییری اعمال نشد',
        ]);
    }

    // PATCH /api/admin/tariffs/{plan_key}/enable  (تاگل وضعیت)
    public function patchEnable($planKey, Request $req)
    {
        if (!in_array($planKey, self::PLAN_KEYS, true)) {
            return response()->json(['status'=>'error','message'=>'کلید نامعتبر است'], 422);
        }
        $enable = $req->input('enable', null);
        if (!in_array((int)$enable, [0,1], true)) {
            return response()->json(['status'=>'error','message'=>'مقدار وضعیت نامعتبر است'], 422);
        }

        $row = DB::table('panel_plan')->where('plan_key', $planKey)->first();
        $msg = ((int)$enable === 1) ? 'پلن با موفقیت فعال شد' : 'پلن با موفقیت غیرفعال شد';

        if ($row) {
            if ((int)$row->enable === (int)$enable) {
                return response()->json(['status'=>'ok','changed'=>false,'message'=>'تغییری اعمال نشد']);
            }
            DB::table('panel_plan')->where('id', $row->id)->update(['enable' => (int)$enable]);
            return response()->json(['status'=>'ok','changed'=>true,'message'=>$msg]);
        } else {
            DB::table('panel_plan')->insert([
                'plan_key'      => $planKey,
                'enable'        => (int)$enable,
                'default_price' => '',
                'details'       => '',
                'v2_plan_id'    => 0, // برای رعایت NOT NULL
            ]);
            return response()->json(['status'=>'ok','changed'=>true,'message'=>$msg]);
        }
    }

    // POST /api/admin/tariffs/percent-adjust  (باکس 2)
    public function percentAdjust(Request $req)
    {
        $mode = $req->input('mode');
        $percent = (float)($req->input('percent', 0));

        if (!in_array($mode, ['increase','decrease'], true)) {
            return response()->json(['status'=>'error','message'=>'نوع تغییر نامعتبر است'], 422);
        }
        if ($percent <= 0) {
            return response()->json(['status'=>'error','message'=>'درصد تغییر را وارد کنید'], 422);
        }

        $factor = $mode === 'increase' ? (1 + $percent/100.0) : (1 - $percent/100.0);

        $aff_plan = 0;
        $aff_users = [
            'traffic_price'            => 0,
            'personalized_price_test'  => 0,
            'personalized_price_1'     => 0,
            'personalized_price_3'     => 0,
            'personalized_price_6'     => 0,
            'personalized_price_12'    => 0,
        ];

        DB::transaction(function() use ($factor, &$aff_plan, &$aff_users) {
            // panel_plan.default_price (TEXT) → تبدیل به عدد، ضرب، ذخیره متن
            $aff_plan += DB::update(
                "UPDATE panel_plan
                SET default_price = CAST(
                    TRUNCATE(
                    CAST(default_price AS DECIMAL(20,6)) * CAST(? AS DECIMAL(10,6)),
                    0
                    ) AS CHAR
                )
                WHERE plan_key IN ('gig','test','1m','3m','6m','12m')
                AND default_price IS NOT NULL
                AND TRIM(default_price) <> ''",
                [$factor]
            );

            // panel_users.* (INT) → ضرب؛ اعشاری‌ها خود DB به INT تبدیل می‌کند (بدون رُند)
            $cols = array_keys($aff_users);
            foreach ($cols as $col) {
                $aff_users[$col] += DB::update(
                    "UPDATE panel_users
                    SET {$col} = CAST(
                        TRUNCATE( CAST({$col} AS DECIMAL(20,6)) * CAST(? AS DECIMAL(10,6)), 0
                    ) AS SIGNED)
                    WHERE {$col} IS NOT NULL",
                    [$factor]
                );
            }
        });

        $changed = ($aff_plan > 0) || array_sum($aff_users) > 0;

        return response()->json([
            'status'   => 'ok',
            'factor'   => $factor,
            'changed'  => $changed,
            'affected' => [
                'panel_plan'  => $aff_plan,
                'panel_users' => $aff_users,
            ],
            'message'  => $changed ? 'تغییرات با موفقیت ذخیره شد' : 'تغییری اعمال نشد',
        ]);
    }
}
