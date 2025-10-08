<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use App\Http\Controllers\Controller;
use App\Models\PanelPlan;
use App\Services\V2UserCreator;
use App\Services\PanelPricingService;
use App\Services\TransactionLogger;

class V2UserController extends Controller
{
    public function create(Request $request, V2UserCreator $creator, PanelPricingService $pricing)
    {
        $data = $request->validate([
            'panel_code'     => ['required','string'],
            'plan_key'       => ['required','string'],   // 'test'|'1m'|'3m'|'6m'|'12m'|'1m_noexp'
            'count'          => ['nullable','integer','min:1'],
            'no_expire'      => ['sometimes','boolean'],
            'download_excel' => ['sometimes','boolean'],
        ]);

        $count = (int)($data['count'] ?? 1);

        // 1m_noexp → برای lookup معادل 1m
        $lookupPlanKey = ($data['plan_key'] === '1m_noexp') ? '1m' : $data['plan_key'];
        // فلگ «بدون تاریخ»
        $noExpire = $request->boolean('no_expire') || $data['plan_key'] === '1m_noexp';
        // فقط وقتی گروهی است، فایل اکسل بده
        $wantExcel = $request->boolean('download_excel') && $count > 1;

        // پیدا کردن v2_plan_id از panel_plan
        $pp = PanelPlan::where('enable', 1)
            ->where('plan_key', $lookupPlanKey)
            ->first();

        if (!$pp || !$pp->v2_plan_id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'پلن انتخابی با پلن ثبت شده مطابقت ندارد'
            ], 422);
        }

        // === تعیین صاحب کیف‌پول و کسر اعتبار ===
        $me = $request->user();
        if (!$me) {
            return response()->json(['status'=>'error','message'=>'Unauthorized'], 401);
        }

        // panel_user مربوط به کاربر لاگین‌شده
        $panelUserId = DB::table('panel_users')->where('email', $me->email)->value('id');
        if (!$panelUserId) {
            return response()->json(['status'=>'error','message'=>'Panel user not found'], 404);
        }

        // plan_key برای قیمت‌گذاری همان ورودی است (1m_noexp → در سرویس به 1m نگاشت می‌شود)
        $planKeyForPricing = (string) $data['plan_key'];
        $quantity          = max(1, (int)$count);

        // کسر اعتبار (اتمیک) + ایجاد لاگ PENDING و دریافت transaction_id
        $charge = $pricing->ensureBalanceAndCharge($panelUserId, $planKeyForPricing, $quantity);
        if (!$charge['ok']) {
            if ($charge['reason'] === 'INSUFFICIENT_CREDIT') {
                return response()->json(['status'=>'error','message'=>'اعتبار شما کافی نیست'], 422);
            }
            return response()->json(['status'=>'error','message'=>$charge['reason'] ?? 'Price error'], 422);
        }
        $trxId = $charge['transaction_id'] ?? null;
        if ($trxId) {
            DB::table('panel_transactions')->where('id', $trxId)->update([
                'type' => 'account_purchase',
                // (اختیاری اما پیشنهادی) اگر ستون plan_key_after در DB داری، همین‌جا پر کن:
                'plan_key_after' => (string)$data['plan_key'],
            ]);
        }
        // --- ساخت کاربران ---
        $logger = new TransactionLogger();
        $created = [];
        $errors  = [];

        DB::beginTransaction();
        try {
            $result = $creator->createUsers(
                panelCode : $data['panel_code'],
                v2PlanId  : (int) $pp->v2_plan_id,
                count     : (int) $count,
                planKey   : $lookupPlanKey,
                noExpire  : $noExpire
            );

            $created = $result['created'] ?? [];
            $errors  = $result['errors']  ?? [];

            // استخراج شناسه‌ها از created
            $extractId = function ($item) {
                if (is_array($item)) {
                    if (!empty($item['id'])) return (int)$item['id'];
                    if (!empty($item['user']['id'])) return (int)$item['user']['id'];
                }
                return null;
            };
            $userIds = [];
            foreach ((array)$created as $it) {
                $id = $extractId($it);
                if ($id) $userIds[] = $id;
            }

            // finalize تراکنش (success) با user_ids و مرجع (در حالت تکی)
            if ($trxId) {
                $refId = count($userIds) === 1 ? $userIds[0] : null;
                $logger->finalize($trxId, [
                    'reference_type' => 'v2_user',
                    'reference_id'   => $refId,
                    'user_ids'       => $userIds,
                    'extra_meta'     => [
                        // پلنی که کاربر انتخاب کرده؛ اگر '1m_noexp' بوده همان را ذخیره کن
                        'plan_key_after' => (string) $data['plan_key'],

                        // برای گزارش مالی مفید است:
                        'unit_price'     => $charge['unit_price']  ?? null,
                        'total_price'    => $charge['total_price'] ?? null,

                        'panel_code'     => $data['panel_code'],
                        'no_expire'      => $noExpire,
                    ],
                ]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            if (!empty($trxId)) {
                $logger->fail($trxId, 'user creation failed: '.$e->getMessage());
            }
            return response()->json(['status'=>'error','message'=>'ساخت کاربر ناموفق بود'], 500);
        }

        // ===== base لینک دعوت: گرفتن از v2_settings بر اساس name (بدون fallback) =====
        $rows = DB::table('v2_settings')
            ->whereIn('name', ['subscribe_url', 'subscribe_path'])
            ->pluck('value', 'name');

        $part1 = trim((string)($rows['subscribe_url']  ?? '')); // مثلا: https://your-domain.com
        $part2 = trim((string)($rows['subscribe_path'] ?? '')); // مثلا: s

        if ($part1 === '') {
            return response()->json([
                'status'  => 'error',
                'message' => 'مقدار subscribe_url را در تنظیمات xboard وارد کنید',
            ], 422);
        }
        if ($part2 === '') {
            return response()->json([
                'status'  => 'error',
                'message' => 'مقدار subscribe_path را در تنظیمات xboard وارد کنید',
            ], 422);
        }

        $base = rtrim($part1, '/') . '/' . ltrim($part2, '/');

        // ===== توکن‌یاب =====
        $extractToken = function(array $item) {
            foreach (['token','sub_token','subscribe_token'] as $k) {
                if (!empty($item[$k]) && is_string($item[$k])) return $item[$k];
            }
            if (!empty($item['user']['token']) && is_string($item['user']['token'])) {
                return $item['user']['token'];
            }
            $id = $item['id'] ?? null;
            if ($id) {
                try {
                    $row = DB::table('v2_user')->where('id', $id)->first();
                    if ($row) {
                        foreach (['token','sub_token','subscribe_token'] as $k) {
                            if (isset($row->$k) && is_string($row->$k) && $row->$k !== '') {
                                return $row->$k;
                            }
                        }
                    }
                } catch (\Throwable $e) {}
                try {
                    $row2 = DB::table('users')->where('id', $id)->first();
                    if ($row2) {
                        foreach (['token','sub_token','subscribe_token'] as $k) {
                            if (isset($row2->$k) && is_string($row2->$k) && $row2->$k !== '') {
                                return $row2->$k;
                            }
                        }
                    }
                } catch (\Throwable $e) {}
            }
            foreach ($item as $v) {
                if (is_string($v) && (preg_match('/^[a-f0-9]{32}$/i', $v) || preg_match('/^[A-Za-z0-9_-]{16,}$/', $v))) {
                    return $v;
                }
            }
            return '';
        };

        // افزودن subscribe_url به created
        $created = array_map(function ($item) use ($extractToken, $base) {
            if (!is_array($item)) return $item;
            $token = $extractToken($item);
            if ($token) $item['subscribe_url'] = $base . '/' . $token;
            return $item;
        }, is_array($created) ? $created : []);

        // ===== حالت دانلود اکسل (فقط User و Link) =====
        if ($wantExcel) {
            if (empty($created)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'هیچ کاربری ساخته نشد'
                ], 422);
            }

            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Users');

            // هدرها
            $sheet->setCellValue('A1', 'User');
            $sheet->setCellValue('B1', 'Link');
            $sheet->getStyle('A1:B1')->getFont()->setBold(true);
            $sheet->getColumnDimension('A')->setWidth(28);
            $sheet->getColumnDimension('B')->setWidth(66);

            // ردیف‌ها
            $row = 2;
            foreach ($created as $item) {
                $rawEmail = is_array($item) ? ($item['email'] ?? '') : '';
                $displayUser = preg_replace('/\.com$/i', '', str_replace('@', '-', $rawEmail));
                $sheet->setCellValue("A{$row}", $displayUser);

                $link  = is_array($item) ? ($item['subscribe_url'] ?? '') : '';
                $sheet->setCellValue("B{$row}", $link);
                if ($link) {
                    $sheet->getCell("B{$row}")->getHyperlink()->setUrl($link);
                }

                $row++;
            }

            $writer = new Xlsx($spreadsheet);
            $filename = 'users_'.date('Ymd_His').'.xlsx';

            return response()->streamDownload(function () use ($writer) {
                $writer->save('php://output');
            }, $filename, [
                'Content-Type'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma'        => 'no-cache',
            ]);
        }

        // پاسخ پیش‌فرض JSON
        return response()->json([
            'status'        => 'success',
            'created'       => $created,
            'errors'        => $errors,
            'transactionId' => $trxId,
        ]);
    }

    public function plans()
    {
        // plan_key و enable از panel_plan
        $rows = \App\Models\PanelPlan::select('plan_key','enable')
            ->get();

        // نقشهٔ key → enabled(bool)
        $map = [];
        foreach ($rows as $r) {
            $map[$r->plan_key] = (bool) $r->enable;
        }

        // تضمین وجود کلیدهای شناخته‌شده (اگه رکوردشون نباشه، false)
        foreach (['test','1m','3m','6m','12m','1m_noexp'] as $k) {
            if (!array_key_exists($k, $map)) {
                $map[$k] = ($k === '1m_noexp') ? (bool)($map['1m'] ?? false) : false;
            }
        }

        // «1m_noexp» مشتق از 1m
        if (isset($map['1m'])) {
            $map['1m_noexp'] = (bool) $map['1m'];
        }

        return response()->json([
            'status'       => 'success',
            'plan_enabled' => $map,
        ]);
    }

    public function extend(Request $request)
    {
        $me = $request->user();

        $validated = $request->validate([
            'email'  => 'required|email',
            'period' => 'required|in:1m,3m,6m,12m',
        ]);

        $email  = strtolower($validated['email']);
        $period = $validated['period'];

        // امنیت tenant: اگر ادمین نیست باید email شامل code باشد (هماهنگ با listUsers)
        if ((int)($me->role ?? 2) !== 1) {
            $panelCode = $me->code ?? '';
            if ($panelCode !== '' && !\Illuminate\Support\Str::contains(strtolower($email), strtolower($panelCode))) {
                return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
            }
        }

        // کاربر هدف
        $user = DB::table('v2_user')->where('email', $email)->first();
        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'کاربر یافت نشد'], 404);
        }

        // قانون ۲ روز
        $nowTs  = Carbon::now()->timestamp;
        //$remain = ((int)($user->expired_at ?? 0)) - $nowTs;
        //if ($remain > 2 * 86400) {
        //    return response()->json([
        //        'status'  => 'error',
        //        'message' => "عملیات غیر مجاز است.\nعملیات تمدید تنها دو روز پایانی اکانت قابل اجراست."
        //    ], 422);
        // در حال حضر برای تمدید هیچ محدودیت زمانی نیستش در سمت بک اند
        //}

        // صاحب کیف‌پول
        $panelUser = DB::table('panel_users')->where('email', strtolower($me->email))->first();
        if (!$panelUser) {
            return response()->json(['status' => 'error', 'message' => 'Panel user not found'], 404);
        }

        /** @var \App\Services\PanelPricingService $pricing */
        $pricing = app(PanelPricingService::class);
        $start   = $pricing->ensureBalanceAndCharge($panelUser->id, $period, 1);
        if (!$start || empty($start['ok'])) {
            return response()->json([
                'status'  => 'error',
                'message' => $start['message'] ?? 'اعتبار شما کافی نیست '
            ], 422);
        }
        $trxId  = $start['transaction_id'] ?? null;
        /** @var \App\Services\TransactionLogger $logger */
        $logger = app(TransactionLogger::class);

        // نوع تراکنش = extend
        if ($trxId) {
            DB::table('panel_transactions')->where('id', $trxId)->update(['type' => 'account_extend']);
        }

        try {
            DB::beginTransaction();

            // پلن پنل و v2_plan (مثل create)
            $panelPlan = DB::table('panel_plan')->where('plan_key', $period)->first();
            if (!$panelPlan || empty($panelPlan->v2_plan_id)) {
                throw new \RuntimeException('Plan not found');
            }
            $v2PlanId = $panelPlan->v2_plan_id;
            $v2Plan   = DB::table('v2_plan')->where('id', $v2PlanId)->first();

            // مدت
            $daysMap = ['1m' => 30, '3m' => 90, '6m' => 180, '12m' => 365];
            $addDays = $daysMap[$period];

            // وضعیت فعلی
            $oldExpTs    = (int)($user->expired_at ?? 0);
            $nowTs       = time();                 // اگر قبلاً داری، همین را نگه دار
            $hasTimeLeft = $oldExpTs > $nowTs;     // هنوز منقضی نشده؟

            $usedBytes  = (int)($user->u ?? 0) + (int)($user->d ?? 0);
            $quotaBytes = (int)($user->transfer_enable ?? 0);   // بایت
            $quotaExhausted = ($quotaBytes > 0) && ($usedBytes >= $quotaBytes);

            // plan_key فعلی کاربر (قبل از آپدیت)
            $beforePlanKey = null;
            if (!empty($user->plan_id)) {
                $beforePlanKey = \Illuminate\Support\Facades\DB::table('panel_plan')
                    ->where('v2_plan_id', $user->plan_id)
                    ->value('plan_key');  // '1m' | '3m' | '6m' | '12m' | ...
            }
            $currentPlanIs1m = ($beforePlanKey === '1m');

            // سه‌حالته با شرط جدید:
            // حالت ۲: اگر منقضی شده ⇒ از «الان» + ریست مصرف
            // حالت ۱: اگر منقضی نشده + سهمیه تمام شده + پلن فعلی 1m ⇒ از «الان» + ریست مصرف
            // حالت ۳: بقیه موارد ⇒ از expiry فعلی + بدون ریست مصرف
            $shouldResetUsage =
                (!$hasTimeLeft) ||
                ($hasTimeLeft && $quotaExhausted && $currentPlanIs1m);

            $baseTs   = $shouldResetUsage ? $nowTs : $oldExpTs;
            $newExpTs = \Carbon\Carbon::createFromTimestamp($baseTs)->addDays($addDays)->timestamp;

            // transfer_enable مثل create (GB → Bytes)
            $transferEnableBytes = null;
            if ($v2Plan && isset($v2Plan->transfer_enable)) {
                $gb = (int)preg_replace('/\D+/', '', (string)$v2Plan->transfer_enable);
                $transferEnableBytes = $gb * 1024 * 1024 * 1024;
            }

            // آپدیت مثل ساخت، با تفاوت تمدید:
            // - expired_at = old + duration
            // - u/d = 0
            $update = [
                'expired_at' => $newExpTs,
                'updated_at' => $nowTs,
                'plan_id'    => $v2PlanId,
            ];
            if ($shouldResetUsage) {
                $update['u'] = 0;
                $update['d'] = 0;
            }
            if ($transferEnableBytes !== null) $update['transfer_enable'] = $transferEnableBytes;
            if ($v2Plan && isset($v2Plan->device_limit)) $update['device_limit'] = $v2Plan->device_limit;
            if ($v2Plan && isset($v2Plan->speed_limit))  $update['speed_limit']  = $v2Plan->speed_limit;
            if ($v2Plan && isset($v2Plan->group_id))     $update['group_id']     = $v2Plan->group_id;

            DB::table('v2_user')->where('email', $email)->update($update);

            // پر کردن ستون‌های DB برای before/after
            if ($trxId) {
                DB::table('panel_transactions')->where('id', $trxId)->update([
                    'plan_key_before' => $beforePlanKey,
                    'plan_key_after'  => $period,
                ]);
            }

            // finalize با meta کامل
            $logger->finalize($trxId, [
                'reference_type' => 'v2_user',
                'reference_id'   => $user->id ?? null,
                'user_ids'       => [$user->id ?? null],
                'extra_meta'     => [
                    'unit_price'      => $start['unit_price']  ?? null,
                    'expired_before'  => $oldExpTs,
                    'expired_after'   => $newExpTs,
                    'plan_key_after'  => $period,
                ],
            ]);

            DB::commit();

            return response()->json([
                'status'     => 'ok',
                'expired_at' => $newExpTs,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            if (!empty($trxId)) {
                $logger->fail($trxId, [
                    'reason' => 'extend_failed',
                    'error'  => $e->getMessage(),
                    'email'  => $email,
                    'plan'   => $period,
                ]);
            }
            return response()->json(['status' => 'error', 'message' => 'تمدید انجام نشد'], 500);
        }
    }

    public function topup(Request $request)
    {
        $me    = $request->user();
        $email = trim((string)$request->input('email', ''));
        $gb    = (int) $request->input('gb', 0);

        if (!$email || $gb < 1) {
            return response()->json(['status'=>'error','message'=>'ورودی نامعتبر است'], 422);
        }

        // محدودیت tenant: فقط وقتی ادمین نیست
        if ((int)($me->role ?? 2) !== 1) {
            $panelCode = $me->code ?? '';
            if ($panelCode !== '' && !Str::contains(strtolower($email), strtolower($panelCode))) {
                return response()->json(['status'=>'error','message'=>'Forbidden'], 403);
            }
        }

        $user = DB::table('v2_user')->where('email', $email)->first();
        if (!$user) {
            return response()->json(['status'=>'error','message'=>'کاربر یافت نشد'], 404);
        }

        // مقدار گیگ → بایت
        $bytes = $gb * 1024 * 1024 * 1024;

        return DB::transaction(function () use ($me, $user, $email, $gb, $bytes) {
            // قیمت هر گیگ و کسر اعتبار از panel_users
            $panel = DB::table('panel_users')->where('id', $me->id)->lockForUpdate()->first();
            $unitPrice = (float)($panel->traffic_price ?? 0);   // قیمت به ازای هر گیگ
            if ($unitPrice <= 0) {
                return response()->json(['status'=>'error','message'=>'قیمت ترافیک تعریف نشده است'], 422);
            }
            $total = $unitPrice * $gb;

            $creditBefore = (float)($panel->credit ?? 0);
            if ($creditBefore < $total) {
                return response()->json(['status'=>'error','message'=>'اعتبار شما کافی نیست'], 400);
            }
            $creditAfter = $creditBefore - $total;

            // ثبت تراکنش PENDING
            $logger = new TransactionLogger();
            $trxId  = $logger->start([
                'panel_user_id'  => $me->id,
                'type'           => 'traffic_purchase',
                'direction'      => 'debit',       // برداشت از کیف پول
                'amount'         => $total,
                'balance_before' => $creditBefore,
                'balance_after'  => $creditAfter,
                'quantity'       => $gb,           // تعداد = گیگ
                'meta'           => json_encode(['unit_price' => $unitPrice], JSON_UNESCAPED_UNICODE),
            ]);

            // کسر اعتبار
            DB::table('panel_users')->where('id', $me->id)->update([
                'credit'     => $creditAfter,
                'updated_at' => now(),
            ]);

            // از ستون D کم کنیم (ولی منفی نشود)
            $dBefore = (int)($user->d ?? 0);
            $dAfter  = $dBefore - $bytes;

            DB::table('v2_user')->where('id', $user->id)->update([
                'd'          => $dAfter,
                'updated_at' => time(),
            ]);

            // نهایی کردن لاگ
            $logger->finalize($trxId, [
                'reference_type' => 'v2_user',
                'reference_id'   => $user->id,
                'user_ids'       => [$user->id],
                'extra_meta'     => [
                    'unit_price' => $unitPrice,
                    'panel_code' => $me->code ?? null,
                    'quantity'   => "{$gb}GB",
                ],
            ]);

            return response()->json(['status' => 'ok', 'd_after' => $dAfter], 200);
        });
    }

}
