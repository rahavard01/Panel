<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Schema;
use App\Services\TelegramNotifier;
use App\Services\TransactionLogger;
use App\Models\PanelWalletReceipt;
use App\Services\WalletReceiptService;

class PartnersAdminController extends Controller
{
    /**
     * GET /api/admin/partners
     * params:
     *   - q: optional (search by name/email/code)
     *   - per_page: optional (default 500; فرانت پیجینیشن خودش رو دارد)
     */
    public function index(Request $request)
    {
        $q        = trim((string) $request->get('q', ''));
        $perPage  = max(1, min((int) $request->get('per_page', 500), 2000));

        $rows = DB::table('panel_users as u')
            ->leftJoin('panel_users as r', 'r.id', '=', 'u.referred_by_id') // referrer
            ->select([
                'u.id',
                DB::raw('COALESCE(NULLIF(TRIM(u.name), ""), u.email) as name'),
                'u.email',
                DB::raw('u.code as panel_code'),
                'u.role',
                'u.banned',
                'u.credit',
                'u.enable_personalized_price',
                DB::raw('u.referred_by_id as referrer_id'),
                'u.created_at',
                DB::raw('r.name as referrer_name'),
                DB::raw('r.code as referrer_code'),
                DB::raw("u.ref_commission_rate as commission_percent"),
                // استراتژی قیمت: شخصی ← پیش‌فرض
                DB::raw("
                CASE
                    WHEN u.enable_personalized_price = 1 THEN 'custom'
                    ELSE 'default'
                END as strategy
                "),
                // دسترسی: 1=admin | 2=user  (در پروژه شما 3=staff هم بود؛ اما طبق خواسته‌ی فعلی: 1/2)
                DB::raw("
                    CASE
                        WHEN u.role = 1 THEN 'admin'
                        ELSE 'user'
                    END as access
                "),
                // وضعیت: 0=normal | 1=blocked
                DB::raw("
                    CASE
                        WHEN u.banned = 1 THEN 'blocked'
                        ELSE 'normal'
                    END as status
                "),
            ])
            ->when($q !== '', function($qq) use ($q) {
                $like = '%'.str_replace(['%','_'], ['\\%','\\_'], $q).'%';
                $qq->where(function($w) use ($like) {
                    $w->where('u.name', 'like', $like)
                      ->orWhere('u.email', 'like', $like)
                      ->orWhere('u.code', 'like', $like);
                });
            })
            ->orderByDesc('u.created_at')
            ->limit($perPage)
            ->get();

        // خروجی هم‌خوان با الگوی بقیه‌ی Adminها
        return response()->json([
            'ok'   => true,
            'data' => $rows,
        ]);
    }

    public function destroy($id)
    {
        // اگر SoftDelete داری از مدل استفاده کن؛ در غیر اینصورت حذف سخت با DB:
        // use App\Models\User;
        // $user = User::findOrFail($id);
        // $user->delete();

        $deleted = \DB::table('panel_users')->where('id', $id)->delete();
        if ($deleted) {
            return response()->json(['ok' => true]);
        }
        return response()->json(['ok' => false], 404);
    }

    public function updateProfile(Request $request, $id)
    {
        // رکورد فعلی
        $u = DB::table('panel_users')
            ->select('id','name','email','code','referred_by_id','ref_commission_rate')
            ->where('id', $id)
            ->first();

        if (!$u) {
            return response()->json(['ok' => false, 'msg' => 'Not found'], 404);
        }

        // فقط چیزهایی که ممکن است از تب "مشخصات" بیایند
        $request->validate([
            'name'               => ['sometimes','nullable','string','max:190'],
            'email'              => ['sometimes','nullable','email','max:190', Rule::unique('panel_users','email')->ignore($id)],
            'panel_code'         => ['sometimes','nullable','string','max:50',  Rule::unique('panel_users','code')->ignore($id)],
            'referrer_id'        => ['sometimes','nullable','integer','exists:panel_users,id'],
            'commission_percent' => ['sometimes','nullable','numeric','min:0','max:100'],
            'password'           => ['sometimes','nullable','string','min:4','max:190'],
        ]);

        $updates = [];

        // name
        if ($request->has('name')) {
            $val = $request->input('name');
            if ($val !== $u->name) $updates['name'] = $val;
        }

        // email
        if ($request->has('email')) {
            $val = $request->input('email');
            if ($val !== $u->email) $updates['email'] = $val;
        }

        // panel_code -> code
        if ($request->has('panel_code')) {
            $val = $request->input('panel_code');
            if ($val !== $u->code) $updates['code'] = $val;
        }

        // referrer_id -> referred_by_id (قابل null)
        if ($request->has('referrer_id')) {
            $raw = $request->input('referrer_id');
            $val = ($raw === '' || is_null($raw)) ? null : (int)$raw;
            if ($val === (int)$id) {
                return response()->json(['ok'=>false,'msg'=>'Referrer cannot be the same user'], 422);
            }
            if ($val != $u->referred_by_id) $updates['referred_by_id'] = $val;
        }

        // commission_percent -> ref_commission_rate (قابل null)
        if ($request->has('commission_percent')) {
            $raw = $request->input('commission_percent');
            $val = ($raw === '' || is_null($raw)) ? null : (float)$raw;
            $cur = $u->ref_commission_rate;

            $changed = (is_null($val) && !is_null($cur)) ||
                    (!is_null($val) && (float)$cur !== (float)$val);

            if ($changed) $updates['ref_commission_rate'] = $val;
        }

        // password (فقط اگر مقدار داده شده و خالی نیست) — هرگز نمایش داده نمی‌شود
        if ($request->has('password')) {
            $pass = $request->input('password');
            if (is_string($pass) && $pass !== '') {
                $updates['password'] = Hash::make($pass);
            }
            // اگر خالی بود، اصلاً دست نمی‌زنیم
        }

        if (empty($updates)) {
            // هیچ تغییری لازم نیست
            return response()->json(['ok' => true, 'changed' => []]);
        }

        $updates['updated_at'] = now();
        DB::table('panel_users')->where('id', $id)->update($updates);

        return response()->json(['ok' => true, 'changed' => array_keys($updates)]);
    }

        public function pricingPreview($id)
    {
        // شخصی‌سازی‌ها از panel_users
        $u = DB::table('panel_users')->select([
            'traffic_price',
            'personalized_price_test',
            'personalized_price_1',
            'personalized_price_3',
            'personalized_price_6',
            'personalized_price_12',
        ])->where('id', $id)->first();

        // ستون‌های جدول panel_plan را چک می‌کنیم تا بدانیم کدام ستون، کلید پلن است
        try {
            $cols = Schema::getColumnListing('panel_plan');
        } catch (\Throwable $e) {
            $cols = [];
        }

        $keyCol   = in_array('plan_key', $cols) ? 'plan_key'
                : (in_array('key', $cols) ? 'key'
                : (in_array('slug', $cols) ? 'slug'
                : (in_array('name', $cols) ? 'name' : null)));
        $priceCol = in_array('default_price', $cols) ? 'default_price'
                : (in_array('price', $cols) ? 'price' : null);

        $defaults = [
            'overage_per_gb' => null,
            'test' => null, 'm1' => null, 'm3' => null, 'm6' => null, 'm12' => null,
        ];

        if ($keyCol && $priceCol) {
            // کلیدها/نام‌های محتمل برای هر آیتم (هم plan_keyهای انگلیسی، هم nameهای فارسی)
            $map = [
                'test' => ['test', 'اکانت تست'],
                '1m'   => ['1m', 'اکانت یک‌ماهه', 'اکانت یک ماهه'],
                '3m'   => ['3m', 'اکانت سه‌ماهه', 'اکانت سه ماهه'],
                '6m'   => ['6m', 'اکانت شش‌ماهه', 'اکانت شش ماهه'],
                '12m'  => ['12m', 'اکانت یک‌ساله', 'اکانت یک ساله'],
                'gig'  => ['gig', 'هرگیگ ترافیک اضافه', 'هر گیگ ترافیک اضافه'],
            ];

            foreach ($map as $k => $cands) {
                $row = DB::table('panel_plan')
                    ->where(function ($qq) use ($keyCol, $cands) {
                        foreach ($cands as $c) {
                            $qq->orWhere($keyCol, $c);
                        }
                    })
                    ->select([$priceCol.' as price'])
                    ->first();

                if ($row && isset($row->price)) {
                    $val = (int)$row->price;
                    if ($k === 'gig')     $defaults['overage_per_gb'] = $val;
                    if ($k === 'test')    $defaults['test']           = $val;
                    if ($k === '1m')      $defaults['m1']             = $val;
                    if ($k === '3m')      $defaults['m3']             = $val;
                    if ($k === '6m')      $defaults['m6']             = $val;
                    if ($k === '12m')     $defaults['m12']            = $val;
                }
            }
        }

        return response()->json([
            'default' => $defaults,
            'personalized' => [
                'overage_per_gb' => $u ? (int)($u->traffic_price ?? 0)               : null,
                'test'           => $u ? (int)($u->personalized_price_test ?? 0)     : null,
                'm1'             => $u ? (int)($u->personalized_price_1 ?? 0)        : null,
                'm3'             => $u ? (int)($u->personalized_price_3 ?? 0)        : null,
                'm6'             => $u ? (int)($u->personalized_price_6 ?? 0)        : null,
                'm12'            => $u ? (int)($u->personalized_price_12 ?? 0)       : null,
            ],
        ]);
    }

    public function updatePricing(Request $request, $id)
    {
        // پیدا کردن همکار
        $u = DB::table('panel_users')->where('id', $id)->first();
        if (!$u) {
            return response()->json(['ok' => false, 'message' => 'Panel user not found'], 404);
        }

        $strategy = trim((string)$request->input('price_strategy', ''));
        if (!in_array($strategy, ['default', 'custom'], true)) {
            return response()->json(['ok'=>false, 'message'=>'Invalid price_strategy'], 422);
        }

        $wasCustom  = (int)($u->enable_personalized_price ?? 0) === 1;
        $wantCustom = $strategy === 'custom';

        // === حالت: default → default (بدون تغییر)
        if (!$wasCustom && !$wantCustom) {
            return response()->json(['ok' => true, 'changed' => []]);
        }

        // Helper: گرفتن قیمت "هر گیگ ترافیک اضافه" از panel_plan
        $getGigDefault = function () {
            try {
                $cols = Schema::getColumnListing('panel_plan');
            } catch (\Throwable $e) {
                $cols = [];
            }
            $keyCol   = in_array('plan_key', $cols) ? 'plan_key'
                    : (in_array('key', $cols) ? 'key'
                    : (in_array('slug', $cols) ? 'slug'
                    : (in_array('name', $cols) ? 'name' : null)));
            $priceCol = in_array('default_price', $cols) ? 'default_price'
                    : (in_array('price', $cols) ? 'price' : null);

            if (!$keyCol || !$priceCol) return null;

            $cands = ['gig', 'هرگیگ ترافیک اضافه', 'هر گیگ ترافیک اضافه'];
            $row = DB::table('panel_plan')
                ->where(function($q) use ($keyCol, $cands){
                    foreach ($cands as $c) $q->orWhere($keyCol, $c);
                })
                ->select([$priceCol.' as price'])
                ->first();

            return $row ? (int)$row->price : null;
        };

        // === حالت: custom → default
        if ($wasCustom && !$wantCustom) {
            $gig = $getGigDefault();
            if ($gig === null) {
                return response()->json(['ok'=>false, 'message'=>'GIG_DEFAULT_NOT_FOUND'], 422);
            }
            $updates = [
                'enable_personalized_price' => 0,
                'traffic_price'             => $gig,
                'updated_at'                => now(),
            ];
            DB::table('panel_users')->where('id', $id)->update($updates);
            return response()->json(['ok'=>true, 'changed'=>array_keys($updates)]);
        }

        // === حالت: default → custom  (همه فیلدها اجباری)
        if (!$wasCustom && $wantCustom) {
            $rules = [
                'traffic_price'             => ['required','integer','min:0'],
                'personalized_price_test'   => ['required','integer','min:0'],
                'personalized_price_1'      => ['required','integer','min:0'],
                'personalized_price_3'      => ['required','integer','min:0'],
                'personalized_price_6'      => ['required','integer','min:0'],
                'personalized_price_12'     => ['required','integer','min:0'],
            ];
            $messages = [
                'traffic_price.required'            => 'MISSING_TRAFFIC_PRICE',
                'personalized_price_test.required'  => 'MISSING_P_TEST',
                'personalized_price_1.required'     => 'MISSING_P_1',
                'personalized_price_3.required'     => 'MISSING_P_3',
                'personalized_price_6.required'     => 'MISSING_P_6',
                'personalized_price_12.required'    => 'MISSING_P_12',
            ];
            $data = $request->validate($rules, $messages);

            $updates = [
                'enable_personalized_price' => 1,
                'traffic_price'             => (int)$data['traffic_price'],
                'personalized_price_test'   => (int)$data['personalized_price_test'],
                'personalized_price_1'      => (int)$data['personalized_price_1'],
                'personalized_price_3'      => (int)$data['personalized_price_3'],
                'personalized_price_6'      => (int)$data['personalized_price_6'],
                'personalized_price_12'     => (int)$data['personalized_price_12'],
                'updated_at'                => now(),
            ];
            DB::table('panel_users')->where('id', $id)->update($updates);
            return response()->json(['ok'=>true, 'changed'=>array_keys($updates)]);
        }

        // === حالت: custom → custom  (partial update)
        // هر کدام از فیلدها که ارسال شد، همان را آپدیت می‌کنیم
        $fields = [
            'traffic_price'            => 'integer|min:0',
            'personalized_price_test'  => 'integer|min:0',
            'personalized_price_1'     => 'integer|min:0',
            'personalized_price_3'     => 'integer|min:0',
            'personalized_price_6'     => 'integer|min:0',
            'personalized_price_12'    => 'integer|min:0',
        ];
        $rules = [];
        foreach ($fields as $k => $rule) {
            if ($request->has($k)) {
                $rules[$k] = $rule;
            }
        }
        if (!empty($rules)) {
            $data = $request->validate($rules, []); // پیام سفارشی لازم نیست
            $updates = [];
            foreach ($data as $k => $v) {
                $updates[$k] = (int)$v;
            }
            // استراتژی را روی custom نگه می‌داریم
            $updates['enable_personalized_price'] = 1;
            $updates['updated_at'] = now();

            DB::table('panel_users')->where('id', $id)->update($updates);
            return response()->json(['ok'=>true, 'changed'=>array_keys($updates)]);
        }

        // اگر هیچ فیلدی برای آپدیت نیامده باشد
        return response()->json(['ok'=>true, 'changed'=>[]]);
    }

    public function statusFlags($id)
    {
        $partner = DB::table('panel_users')->select(['id','code','banned'])->where('id', $id)->first();
        if (!$partner) {
            return response()->json(['ok'=>false, 'message'=>'Panel user not found'], 404);
        }

        $code = trim((string)$partner->code);

        // اگر کد خالی بود، هیچ کاربری منتسب نمی‌شود
        if ($code === '') {
            return response()->json([
                'ok'               => true,
                'partner_banned'   => ((int)$partner->banned === 1),
                'all_users_banned' => false,
                'counts'           => ['total'=>0, 'banned'=>0],
            ]);
        }

        // کوئری کاربران منتسب به این همکار:
        // - ایمیل: هرجا code در ایمیل باشد (case-insensitive)
        // - remarks: هرجا code باشد
        $codeLower = strtolower($code);
        $base = DB::table('v2_user')->where(function($q) use ($code, $codeLower){
            $q->whereRaw('LOWER(email) LIKE ?', ['%'.$codeLower.'%'])
            ->orWhere('remarks', 'LIKE', '%'.$code.'%');
        });

        $total  = (clone $base)->count();
        $banned = (clone $base)->where('banned', 1)->count();

        return response()->json([
            'ok'                => true,
            'partner_banned'    => ((int)$partner->banned === 1),
            'all_users_banned'  => ($total > 0 && $banned === $total),
            'counts'            => ['total'=>$total, 'banned'=>$banned],
        ]);
    }

    public function banPartner(Request $request, $id)
    {
        // telegram_user_id را هم بگیر
        $partner = DB::table('panel_users')
            ->select(['id','telegram_user_id'])
            ->where('id', $id)
            ->first();

        if (!$partner) return response()->json(['ok'=>false, 'message'=>'Panel user not found'], 404);

        $banned = filter_var($request->input('banned'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($banned === null) return response()->json(['ok'=>false, 'message'=>'Invalid banned'], 422);

        DB::table('panel_users')->where('id', $id)->update([
            'banned'    => $banned ? 1 : 0,
            'updated_at'=> now(),
        ]);

        // 🔔 پیام تلگرام به خودِ همکار
        try {
            if (!empty($partner->telegram_user_id)) {
                $tg = new TelegramNotifier();
                $text = $banned
                    ? "❌ دسترسی شما به پنل کاربری مسدود گردید."
                    : "✅ محدودیت دسترسی شما به پنل کاربری برطرف گردید.";
                $tg->sendTextTo((string)$partner->telegram_user_id, $text);
            }
        } catch (\Throwable $e) {
            // ساکت: اگر تلگرام قطع بود، API نباید خطا بده
        }

        return response()->json(['ok'=>true, 'banned'=> (bool)$banned]);
    }

    public function banPartnerUsers(Request $request, $id)
    {
        // telegram_user_id را هم بگیر
        $partner = DB::table('panel_users')
            ->select(['id','code','telegram_user_id'])
            ->where('id', $id)
            ->first();
        if (!$partner) return response()->json(['ok'=>false, 'message'=>'Panel user not found'], 404);

        $banned = filter_var($request->input('banned'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($banned === null) return response()->json(['ok'=>false, 'message'=>'Invalid banned'], 422);

        $code = trim((string)$partner->code);
        if ($code === '') {
            // اگر کد پنل ندارد، عملاً کاربری برای این تاگل شناسایی نمی‌شود
            return response()->json(['ok'=>true, 'affected'=>['set_banned'=>0, 'set_unbanned'=>0]]);
        }

        $codeLower = strtolower($code);

        // نتیجهٔ ترنزاکشن را به صورت آرایه برگردان، نه Response
        $result = DB::transaction(function () use ($id, $code, $codeLower, $banned) {
            // همه کاربران منتسب به این همکار:
            $userRows = DB::table('v2_user')
                ->select('id','banned')
                ->where(function($q) use ($code, $codeLower){
                    $q->whereRaw('LOWER(email) LIKE ?', ['%'.$codeLower.'%'])
                    ->orWhere('remarks', 'LIKE', '%'.$code.'%');
                })
                ->get();

            if ($userRows->isEmpty()) {
                return ['ok'=>true, 'affected'=>['set_banned'=>0, 'set_unbanned'=>0]];
            }

            $affected = ['set_banned'=>0, 'set_unbanned'=>0];

            if ($banned) {
                // مسدود کردن گروهی: فقط آزادها را ببند
                $toBan = $userRows->where('banned', 0)->pluck('id')->all();
                if (!empty($toBan)) {
                    DB::table('v2_user')->whereIn('id', $toBan)->update(['banned'=>1]);
                    $affected['set_banned'] = count($toBan);

                    // ثبت/به‌روزرسانی رکوردهای فعال
                    $rows = array_map(fn($uid)=>[
                        'partner_id' => $id,
                        'v2_user_id' => $uid,
                        'active'     => 1,
                        'created_at' => now(),
                        'cleared_at' => null,
                    ], $toBan);

                    DB::table('panel_partner_user_bulk_bans')->upsert(
                        $rows,
                        ['partner_id','v2_user_id','active'],
                        ['active','cleared_at']
                    );
                }
            } else {
                // آزادسازی گروهی: فقط آنهایی که با تاگل گروهی قبلاً بسته شده‌اند
                $bulkIds = DB::table('panel_partner_user_bulk_bans')
                    ->where('partner_id', $id)
                    ->where('active', 1)
                    ->pluck('v2_user_id')
                    ->all();

                if (!empty($bulkIds)) {
                    DB::table('v2_user')->whereIn('id', $bulkIds)->update(['banned'=>0]);
                    $affected['set_unbanned'] = count($bulkIds);

                    DB::table('panel_partner_user_bulk_bans')
                        ->where('partner_id', $id)
                        ->whereIn('v2_user_id', $bulkIds)
                        ->where('active', 1)
                        ->delete();
                }
            }

            return ['ok'=>true, 'affected'=>$affected];
        });

        // 🔔 ارسال پیام بعد از کامیت
        DB::afterCommit(function () use ($partner, $banned) {
            try {
                if (!empty($partner->telegram_user_id)) {
                    $tg = new TelegramNotifier();
                    $text = $banned
                        ? "❌ کلیه کاربران شما مسدود گردیدند."
                        : "✅ کلیه کاربران شما از حالت مسدود خارج شدند.";
                    $tg->sendTextTo((string)$partner->telegram_user_id, $text);
                }
            } catch (\Throwable $e) {
                // ساکت
            }
        });

        return response()->json($result);
    }

    public function walletAdjust(Request $request, $id)
    {
        // partner + chat id
        $partner = DB::table('panel_users')
            ->select(['id','credit','telegram_user_id'])
            ->where('id', $id)
            ->first();
        if (!$partner) return response()->json(['ok'=>false, 'message'=>'Panel user not found'], 404);

        $op     = trim((string)$request->input('op', ''));
        $amount = (int)$request->input('amount', 0);
        if (!in_array($op, ['increase','decrease'], true) || $amount <= 0) {
            return response()->json(['ok'=>false, 'message'=>'Invalid op/amount'], 422);
        }

        // =========================
        // ↑ افزایش اعتبار (شارژ)  |
        // =========================
        if ($op === 'increase') {
            $adminId = (int) (optional($request->user())->id ?? 0);

            // before
            $before = (int) DB::table('panel_users')->where('id', $id)->value('credit');

            // 1) رسید دستیِ "در انتظار بررسی" بساز
            //    (verify همین را مثل واریزی کارتی پردازش می‌کند)
            $wrId = DB::table('panel_wallet_receipts')->insertGetId([
                'user_id'        => (int)$id,
                'amount'         => (int)$amount,
                'method'         => 'manual',
                'disk'           => 'public',
                'path'           => 'manual://admin',
                'original_name'  => 'ADMIN-MANUAL',
                'mime'           => 'text/plain',
                'size'           => 0,
                'status'         => 'submitted',          // مهم
                'commission_paid'      => 0,
                'commission_tx_id'     => null,
                'commission_notified_at' => null,
                'notified_at'          => null,           // تا توست شارژ بیاید
                'meta'                 => json_encode(['via' => 'admin_manual']),
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);

            // 2) دقیقاً همان جریان "تأیید واریزی" را اجرا کن
            app(WalletReceiptService::class)->approve((int)$wrId, $adminId, (int)$amount);

            // after
            $after = (int) DB::table('panel_users')->where('id', $id)->value('credit');

            // خروجی
            return response()->json(['ok'=>true, 'before'=>$before, 'after'=>$after, 'op'=>'increase']);
        }

        // =========================
        // ↓ کاهش اعتبار (اصلاحیه) |
        // =========================
        $logger = app(TransactionLogger::class);

        $result = DB::transaction(function () use ($id, $amount, $logger) {
            // قفل و محاسبه
            $before = (int) DB::table('panel_users')->where('id', $id)->lockForUpdate()->value('credit');
            $after  = $before - $amount;

            // بروزرسانی موجودی
            DB::table('panel_users')->where('id', $id)->update([
                'credit'     => $after,
                'updated_at' => now(),
            ]);

            // تراکنش کاهشی (اصلاحیه)
            $trxId = $logger->start([
                'panel_user_id'  => (int)$id,
                'type'           => 'wallet_adjust',
                'direction'      => 'debit',
                'amount'         => (int)$amount,
                'balance_before' => (int)$before,
                'balance_after'  => (int)$after,
                'reference_type' => 'admin_manual',
                'reference_id'   => (int) (optional(request()->user())->id ?? 0),
                'currency'       => 'IRT',
            ]);
            $logger->finalize($trxId, [
                'status'     => 'success',
                'extra_meta' => ['title' => 'اصلاحیه', 'source' => 'admin'],
            ]);

            // رسید برای توست «اصلاحیه» در پنل کاربر
            DB::table('panel_wallet_receipts')->insert([
                'user_id'        => (int)$id,
                'amount'         => (int)$amount,     // مثبت ذخیره می‌شود
                'method'         => 'adjust',
                'disk'           => 'public',
                'path'           => 'adjust://admin',
                'original_name'  => 'ADMIN-ADJUST',
                'mime'           => 'text/plain',
                'size'           => 0,
                'status'         => 'verified',
                'commission_paid'      => 1,
                'commission_tx_id'     => null,
                'commission_notified_at' => null,
                'notified_at'    => null,             // تا توست اصلاحیه بیاید
                'meta'           => json_encode(['via' => 'admin_manual']),
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            return ['ok'=>true, 'before'=>$before, 'after'=>$after, 'op'=>'decrease'];
        });

        // تلگرام اصلاحیه برای خود همکار (در افزایش نیازی نیست؛ verify خودش پیام می‌دهد)
        DB::afterCommit(function () use ($partner, $result) {
            if (($result['ok'] ?? false) && ($result['op'] ?? '') === 'decrease') {
                try {
                    if (!empty($partner->telegram_user_id)) {
                        $tg = new TelegramNotifier();
                        $amount = (int)(($result['before'] ?? 0) - ($result['after'] ?? 0));
                        $fmtAmount = number_format($amount);
                        $fmtAfter  = number_format((int)($result['after'] ?? 0));
                        $tg->sendTextTo((string)$partner->telegram_user_id,
                            "❌ اصلاحیه: مبلغ {$fmtAmount} تومان از کیف پول شما کسر گردید.\n💰 موجودی فعلی: {$fmtAfter} تومان"
                        );
                    }
                } catch (\Throwable $e) {}
            }
        });

        return response()->json($result);
    }

    public function store(Request $request)
    {
        // === اعتبارسنجی ورودی‌ها
        $data = $request->validate([
            'name'               => ['required','string','max:190'],
            'email'              => ['required','email','max:190','unique:panel_users,email'],
            'panel_code'         => ['required','string','max:50','unique:panel_users,code'],
            'password'           => ['required','string','min:4','max:190'],
            'role'               => ['required','in:1,2'], // 1=admin, 2=user
            'referrer_id'        => ['nullable','integer','exists:panel_users,id'],
            'commission_percent' => ['nullable','numeric','min:0','max:100'],

            // استراتژی قیمت
            'pricing_strategy'                      => ['required','in:default,referrer,like_referrer,custom'],
            'pricing_payload.personalized_price_test'   => ['required_if:pricing_strategy,custom','integer','min:0'],
            'pricing_payload.personalized_price_1'      => ['required_if:pricing_strategy,custom','integer','min:0'],
            'pricing_payload.personalized_price_3'      => ['required_if:pricing_strategy,custom','integer','min:0'],
            'pricing_payload.personalized_price_6'      => ['required_if:pricing_strategy,custom','integer','min:0'],
            'pricing_payload.personalized_price_12'     => ['required_if:pricing_strategy,custom','integer','min:0'],
            'pricing_payload.traffic_price'             => ['required_if:pricing_strategy,custom','integer','min:0'],
        ], [
            'email.unique'         => 'ایمیل تکراری است',
            'panel_code.unique'    => 'کد پنل تکراری است',
            'pricing_strategy.required' => 'انتخاب استراتژی قیمت الزامی است',
        ]);

        // اگر کمیسیون پر شده ولی معرف انتخاب نشده → خطا
        if (!is_null($data['commission_percent'] ?? null) && empty($data['referrer_id'])) {
            return response()->json(['ok'=>false,'message'=>'برای ثبت کمیسیون، معرف را انتخاب کنید'], 422);
        }

        // اگر مشابه معرف انتخاب شده ولی معرف نداریم → خطا
        $ps = $data['pricing_strategy'];
        if (($ps === 'referrer' || $ps === 'like_referrer') && empty($data['referrer_id'])) {
            return response()->json(['ok'=>false,'message'=>'برای «مشابه معرف»، انتخاب معرف الزامی است'], 422);
        }
        // یکنواخت‌سازی نام استراتژی
        if ($ps === 'referrer') $ps = 'like_referrer';

        // Helper: گرفتن قیمت «هرگیگ» پیش‌فرض از panel_plan
        $getGigDefault = function () {
            try { $cols = \Schema::getColumnListing('panel_plan'); } catch (\Throwable $e) { $cols = []; }
            $keyCol   = in_array('plan_key',$cols) ? 'plan_key' : (in_array('key',$cols)?'key':(in_array('slug',$cols)?'slug':(in_array('name',$cols)?'name':null)));
            $priceCol = in_array('default_price',$cols) ? 'default_price' : (in_array('price',$cols)?'price':null);
            if (!$keyCol || !$priceCol) return null;
            $cands = ['gig','هرگیگ ترافیک اضافه','هر گیگ ترافیک اضافه'];
            $row = \DB::table('panel_plan')->where(function($q) use($keyCol,$cands){ foreach($cands as $c) $q->orWhere($keyCol,$c); })->select([$priceCol.' as price'])->first();
            return $row ? (int)$row->price : null;
        };

        // آماده‌سازی مقادیر قیمت
        $enablePersonal = 0;
        $pt=null; $p1=$p3=$p6=$p12=$tgb = null;

        if ($ps === 'default') {
            $enablePersonal = 0;
            // مثل رفتار updatePricing: اگر لازم داری ترافیک را هم از دیفالت پر کن:
            $tgb = $getGigDefault();

        } elseif ($ps === 'like_referrer') {
            $ref = \DB::table('panel_users')->where('id', (int)$data['referrer_id'])->first();
            if ($ref) {
                $enablePersonal = (int)($ref->enable_personalized_price ?? 0);
                if ($enablePersonal === 1) {
                    $pt  = (int)($ref->personalized_price_test ?? 0);
                    $p1  = (int)($ref->personalized_price_1  ?? 0);
                    $p3  = (int)($ref->personalized_price_3  ?? 0);
                    $p6  = (int)($ref->personalized_price_6  ?? 0);
                    $p12 = (int)($ref->personalized_price_12 ?? 0);
                    $tgb = (int)($ref->traffic_price        ?? 0);
                } else {
                    // شخصی‌سازی فعال نبود → پلن‌ها خالی بماند؛ اما قیمت ترافیک را اگر معرف دارد کپی کن
                    $enablePersonal = 0;
                    $tgb = ($ref->traffic_price !== null) ? (int)$ref->traffic_price : null;
                }
            }

        } else { // custom
            $pp = $data['pricing_payload'] ?? [];
            $enablePersonal = 1;
            $pt  = (int)($pp['personalized_price_test'] ?? 0);
            $p1  = (int)($pp['personalized_price_1']    ?? 0);
            $p3  = (int)($pp['personalized_price_3']    ?? 0);
            $p6  = (int)($pp['personalized_price_6']    ?? 0);
            $p12 = (int)($pp['personalized_price_12']   ?? 0);
            $tgb = (int)($pp['traffic_price']           ?? 0);
        }

        // ساخت رکورد
        $now = now();
        $insert = [
            'name'                     => $data['name'],
            'email'                    => $data['email'],
            'code'                     => $data['panel_code'],
            'role'                     => (int)$data['role'],
            'password'                 => \Hash::make($data['password']),
            'referred_by_id'           => $data['referrer_id'] === '' ? null : ($data['referrer_id'] ?? null),
            'ref_commission_rate'      => $data['commission_percent'] ?? null,

            'enable_personalized_price'=> $enablePersonal,
            'personalized_price_test'  => $pt,
            'personalized_price_1'     => $p1,
            'personalized_price_3'     => $p3,
            'personalized_price_6'     => $p6,
            'personalized_price_12'    => $p12,
            'traffic_price'            => $tgb,

            'banned'                   => 0,
            'credit'                   => 0,
            'created_at'               => $now,
            'updated_at'               => $now,
        ];

        $newId = \DB::table('panel_users')->insertGetId($insert);

        return response()->json(['ok'=>true, 'id'=>$newId], 201);
    }

}
