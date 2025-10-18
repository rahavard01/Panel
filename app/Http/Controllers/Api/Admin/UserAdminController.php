<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use App\Models\PanelPlan;
use App\Models\V2User;
use App\Services\V2UserCreator;

class UserAdminController extends Controller
{
    /**
     * GET /api/admin/users
     * لیست همه کاربران (بدون فیلتر کُد/tenant)
     */
    public function index(Request $request)
    {
        $q = V2User::query()->orderByDesc('id');
        return response()->json(['data' => $q->get()]);
    }

    /**
     * POST /api/admin/users/create
     * منطق دقیقاً مثل V2UserController@create
     * تفاوت: هیچ برداشت اعتباری/قیمتی انجام نمی‌شود.
     */
    public function create(Request $request, V2UserCreator $creator)
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

        // پیدا کردن v2_plan_id از panel_plan (مثل فایل کاربر؛ فقط پلن‌های enable=1)
        $pp = PanelPlan::where('enable', 1)
            ->where('plan_key', $lookupPlanKey)
            ->first();

        if (!$pp || !$pp->v2_plan_id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'پلن انتخابی با پلن ثبت شده مطابقت ندارد'
            ], 422);
        }

        // --- ساخت کاربران (بدون قیمت/کیف‌پول) ---
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

            DB::commit();
            // === [ADD] لاگ تراکنش برای «خرید اکانت» توسط ادمین ===
            try {
                $me = $request->user();
                $panelUserId = (int) ($me->id ?? 0);
                $creditNow = (int) DB::table('panel_users')->where('id', $panelUserId)->value('credit');

                // استخراج آیدی‌ها از $created
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

                /** @var \App\Services\TransactionLogger $logger */
                $logger = app(\App\Services\TransactionLogger::class);
                $trxId = $logger->start([
                    'panel_user_id'   => $panelUserId,
                    'type'            => 'account_purchase',
                    'direction'       => 'debit',
                    'amount'          => 0, // ساخت ادمینی؛ بدون کسر کیف‌پول
                    'balance_before'  => $creditNow,
                    'balance_after'   => $creditNow,
                    'quantity'        => max(count($userIds), 1),
                    'meta'            => ['panel_code' => $data['panel_code'] ?? null],
                ]);
                if ($trxId) {
                    $logger->finalize($trxId, [
                        'reference_type' => 'v2_user',
                        'reference_id'   => (count($userIds) === 1) ? $userIds[0] : null,
                        'user_ids'       => $userIds,
                    ]);
                }
            } catch (\Throwable $e) {
                // لاگ اختیاری است؛ خطا روی پاسخ اصلی اثر نداشته باشد
            }

        } catch (\Throwable $e) {
            DB::rollBack();
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

        // ===== توکن‌یاب (مثل فایل کاربر) =====
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

        // پاسخ JSON دقیقاً مثل فایل کاربر
        return response()->json([
            'status'        => 'success',
            'created'       => $created,
            'errors'        => $errors,
            'transactionId' => null, // ادمین: تراکنش مالی نداریم
        ]);
    }

    /**
     * POST /api/admin/users/extend
     * همان منطق V2UserController@extend (سه‌حالته)، بدون کسر اعتبار.
     */
    public function extend(Request $request)
    {
        $validated = $request->validate([
            'email'  => 'required|email',
            'period' => 'required|in:1m,3m,6m,12m',
        ]);

        $email  = strtolower($validated['email']);
        $period = $validated['period'];

        $user = DB::table('v2_user')->where('email', $email)->first();
        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'کاربر یافت نشد'], 404);
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
            $nowTs       = time();
            $hasTimeLeft = $oldExpTs > $nowTs;

            $usedBytes  = (int)($user->u ?? 0) + (int)($user->d ?? 0);
            $quotaBytes = (int)($user->transfer_enable ?? 0);
            $quotaExhausted = ($quotaBytes > 0) && ($usedBytes >= $quotaBytes);

            // plan_key فعلی کاربر (برای گزارش/سازگاری)
            $beforePlanKey = null;
            if (!empty($user->plan_id)) {
                $beforePlanKey = DB::table('panel_plan')
                    ->where('v2_plan_id', $user->plan_id)
                    ->value('plan_key');
            }
            $currentPlanIs1m = ($beforePlanKey === '1m');

            // سه‌حالته:
            // - منقضی: از الان + ریست مصرف
            // - هنوز زمان دارد ولی سهمیه تمام شده و پلن 1m: از الان + ریست مصرف
            // - بقیه: از expiry فعلی + بدون ریست مصرف
            $shouldResetUsage =
                (!$hasTimeLeft) ||
                ($hasTimeLeft && $quotaExhausted && $currentPlanIs1m);

            $baseTs   = $shouldResetUsage ? $nowTs : $oldExpTs;
            $newExpTs = Carbon::createFromTimestamp($baseTs)->addDays($addDays)->timestamp;

            // transfer_enable مثل create (GB → Bytes)
            $transferEnableBytes = null;
            if ($v2Plan && isset($v2Plan->transfer_enable)) {
                $gb = (int)preg_replace('/\D+/', '', (string)$v2Plan->transfer_enable);
                $transferEnableBytes = $gb * 1024 * 1024 * 1024;
            }

            $update = [
                'expired_at' => $newExpTs,
                'updated_at' => $nowTs,
                'plan_id'    => $v2PlanId,
                'u'          => 0,   // همیشه ریست شود
                'd'          => 0,   // همیشه ریست شود
            ];

            if ($transferEnableBytes !== null) $update['transfer_enable'] = $transferEnableBytes;
            if ($v2Plan && isset($v2Plan->device_limit)) $update['device_limit'] = $v2Plan->device_limit;
            if ($v2Plan && isset($v2Plan->speed_limit))  $update['speed_limit']  = $v2Plan->speed_limit;
            if ($v2Plan && isset($v2Plan->group_id))     $update['group_id']     = $v2Plan->group_id;

            DB::table('v2_user')->where('email', $email)->update($update);

            DB::commit();
            // === [ADD] لاگ تراکنش برای «تمدید اکانت» توسط ادمین ===
            try {
                $me = $request->user();
                $panelUserId = (int) ($me->id ?? 0);
                $creditNow   = (int) DB::table('panel_users')->where('id', $panelUserId)->value('credit');

                /** @var \App\Services\TransactionLogger $logger */
                $logger = app(\App\Services\TransactionLogger::class);
                $trxId = $logger->start([
                    'panel_user_id'   => $panelUserId,
                    'type'            => 'account_extend',
                    'direction'       => 'debit',
                    'amount'          => 0,
                    'balance_before'  => $creditNow,
                    'balance_after'   => $creditNow,
                    'quantity'        => 1,
                    'meta'            => [
                        'panel_code'      => $me->code ?? null,
                    ],
                ]);
                if ($trxId) {
                    $logger->finalize($trxId, [
                        'reference_type'  => 'v2_user',
                        'reference_id'    => (int)$user->id,
                        'user_ids'        => [(int)$user->id],
                        'extra_meta'      => [
                            'plan_key_before' => $beforePlanKey ?? null,
                            'plan_key_after'  => $period,
                        ],
                    ]);
                }
            } catch (\Throwable $e) {}

            return response()->json([
                'status'     => 'ok',
                'expired_at' => $newExpTs,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'تمدید انجام نشد'], 500);
        }
    }

    /**
     * POST /api/admin/users/topup
     * منطق دقیقاً مثل V2UserController@topup: از D کم می‌کند.
     * تفاوت: بدون کسر اعتبار.
     */
    public function topup(Request $request)
    {
        $email = trim((string)$request->input('email', ''));
        $gb    = (int) $request->input('gb', 0);

        if (!$email || $gb < 1) {
            return response()->json(['status'=>'error','message'=>'ورودی نامعتبر است'], 422);
        }

        $user = DB::table('v2_user')->where('email', $email)->first();
        if (!$user) {
            return response()->json(['status'=>'error','message'=>'کاربر یافت نشد'], 404);
        }

        $bytes = $gb * 1024 * 1024 * 1024;

        return DB::transaction(function () use ($user, $bytes) {
            $dBefore = (int)($user->d ?? 0);
            $dAfter  = $dBefore - $bytes; // همان منطق فایل کاربر؛ اگر می‌خواهی کف صفر بگذاری، اینجا max(0, ...) کن.

            DB::table('v2_user')->where('id', $user->id)->update([
                'd'          => $dAfter,
                'updated_at' => time(),
            ]);

            return response()->json(['status' => 'ok', 'd_after' => $dAfter], 200);
        });
    }
}
