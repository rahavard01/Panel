<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class V2UserExpiryRunner
{
    /**
     * برای رسیدن به ~100 اجرای روزانه: هر 14 دقیقه یکبار
     * (کران هر دقیقه صدا می‌زند؛ این throttle اجازه‌ی اجرای واقعی را هر 14 دقیقه می‌دهد)
     */
    protected int $throttleSeconds = 14 * 60; // 840s ~= 103/day

    /** کلید کش برای ضد‌تکرار */
    protected string $lastRunCacheKey = 'v2_user:expiry:last_run';

    /** اسم لاک دیتابیس جهت جلوگیری از همپوشانی اجراها */
    protected string $lockName = 'v2_user_expiry_runner';

    /**
     * این متد را در Scheduler صدا می‌زنیم (مثل tick() در AnnouncementDueRunner)
     * منطق: هر یوزری که expired_at هنوز NULL است و t > 0 شده،
     * expired_at = t + 30 روز می‌شود.
     */
    public function tick(): int
    {
        // throttle
        $now  = time();
        $last = cache()->get($this->lastRunCacheKey, 0);
        if ($last && ($now - (int)$last) < $this->throttleSeconds) {
            return 0; // هنوز زود است
        }

        // لاک دیتابیس (MySQL GET_LOCK) تا اجرای موازی نداشته باشیم
        if (!$this->acquireDbLock($this->lockName)) {
            return 0;
        }

        try {
            // UPDATE اتمی — فقط ردیف‌هایی که تاریخ انقضا ندارند و t>0
            $affected = DB::update("
                UPDATE v2_user
                SET expired_at = (t + 30*86400),
                    updated_at = ?
                WHERE expired_at IS NULL
                  AND t IS NOT NULL
                  AND t > 0
            ", [$now]);

            if ($affected > 0) {
                Log::info("V2UserExpiryRunner: updated {$affected} rows (expired_at = t + 30 days).");
            }

            // set throttle
            cache()->put($this->lastRunCacheKey, $now, $this->throttleSeconds);

            return (int)$affected;
        } finally {
            $this->releaseDbLock($this->lockName);
        }
    }

    /** گرفتن لاک دیتابیس */
    protected function acquireDbLock(string $name): bool
    {
        try {
            $row = DB::selectOne("SELECT GET_LOCK(?, 0) AS got", [$name]);
            return (int)($row->got ?? 0) === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** آزاد کردن لاک دیتابیس */
    protected function releaseDbLock(string $name): void
    {
        try {
            DB::select("SELECT RELEASE_LOCK(?)", [$name]);
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
