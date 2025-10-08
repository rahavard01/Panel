<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class V2UserCreator
{
    public function createUsers(string $panelCode, int $v2PlanId, int $count, string $planKey, bool $noExpire = false ): array
    {
        $created = [];
        $errors  = [];

        // 1) پلان مرجع را از v2_plan بخوان
        $plan = DB::table('v2_plan')->where('id', $v2PlanId)->first();
        if (!$plan) {
            return ['created' => [], 'errors' => ['v2_plan not found']];
        }

        // اگر از فرانت plan_key مثل '1m_noexp' بیاد، هر دو حالت را پشتیبانی می‌کنیم:
        $noExpire = $noExpire || str_contains($planKey, 'noexp');
        $normalizedPlanKey = $planKey === '1m_noexp' ? '1m' : $planKey;

        // 2) مدت انقضا بر اساس کلید
        $nowTs = time();
        $expiredTs = $noExpire ? null : match ($normalizedPlanKey) {
            'test' => $nowTs + 3600,
            '1m'   => $nowTs + 30*86400,
            '3m'   => $nowTs + 90*86400,
            '6m'   => $nowTs + 180*86400,
            '12m'  => $nowTs + 365*86400,
            default => $nowTs + 30*86400,
        };

        DB::beginTransaction();
        try {
            for ($i=0; $i<$count; $i++) {

                // 3) تولید ایمیل یکتا در دامنه‌ی این پنل
                $email = $this->makeUniqueEmail($panelCode);

                // 4) uuid یکتا (v4)
                $uuid  = $this->makeUniqueUuid();

                // 5) token یکتا (۳۲ کاراکتر hex)
                $token = $this->makeUniqueToken();

                // 6) پسورد = هش ایمیل کامل
                $passwordHash = Hash::make($email);

                // --- GB → Bytes (مقاوم به رشته/اعداد اعشاری) ---
                // GB → Bytes (safe)
                $transferEnableBytes = 0;
                if (!is_null($plan->transfer_enable) && $plan->transfer_enable !== '') {
                    $gb = (float) preg_replace('/[^\d.]/', '', (string) $plan->transfer_enable);
                    $transferEnableBytes = (int) round($gb * 1024 * 1024 * 1024);
                }

                // 7) پر کردن ستون‌ها از v2_plan + پیش‌فرض‌ها
                //    (طبق اسکرین‌شات‌هات v2_user ستون‌هایی مثل u,d,t,transfer_enable, up/down limit و … دارد)
                $insert = [
                    'email'            => $email,
                    'password'         => $passwordHash,
                    'uuid'             => $uuid,
                    'token'            => $token,

                    'plan_id'          => $v2PlanId, // اگر اسم ستون پلن توی v2_user چیز دیگری است، همین‌جا اصلاح کن

                    'u'                => 0,
                    'd'                => 0,
                    't'                => 0,

                    // از v2_plan اگر داشت مقدار بگیر، وگرنه null/0
                    'transfer_enable'  => $transferEnableBytes,
                    'device_limit'     => $plan->device_limit          ?? null,
                    'speed_limit'      => $plan->speed_limit           ?? null,
                    'group_id'         => $plan->group_id              ?? null,

                    // وضعیت‌ها
                    'banned'           => 0,
                    'remind_traffic'   => 1,
                    'remind_expire'   => 1,

                    // تاریخ‌ها
                    'expired_at'       => $expiredTs,   // طبق دیتابیس شما: Unix timestamp
                    'created_at'       => $nowTs,
                    'updated_at'       => $nowTs,
                ];

                // هر ستونی در v2_user که nullable است اگر در plan نبود → null بگذاریم
                // (بالا رعایت شده – اگر ستونی در دیتابیس شما اسم دیگری دارد، این آرایه را دقیق با نام ستون‌هایتان مَچ کن)
                $id = DB::table('v2_user')->insertGetId($insert);
                $created[] = ['id' => $id, 'email' => $email];
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $errors[] = $e->getMessage();
        }

        return compact('created','errors');
    }

    private function makeUniqueEmail(string $panelCode): string
    {
        // لوکال‌پارت 6 کاراکتری از حروف/عدد
        do {
            $local = Str::random(6);                   // حروف/عدد
            // اگر فقط حروف/عدد می‌خواهی، همین کافیه؛ اگر فقط [a-z0-9] خواستی:
            // $local = strtolower(preg_replace('/[^a-z0-9]/i','',Str::random(8)));
            $email = "{$local}@{$panelCode}.com";
            $exists = DB::table('v2_user')->where('email', $email)->exists();
        } while ($exists);

        return $email;
    }

    private function makeUniqueUuid(): string
    {
        do {
            // UUID v4
            $uuid = (string) Str::uuid();
            $exists = DB::table('v2_user')->where('uuid', $uuid)->exists();
        } while ($exists);

        return $uuid;
    }

    private function makeUniqueToken(): string
    {
        do {
            // 32 hex
            $token = Str::lower(bin2hex(random_bytes(16)));
            $exists = DB::table('v2_user')->where('token', $token)->exists();
        } while ($exists);

        return $token;
    }
}
