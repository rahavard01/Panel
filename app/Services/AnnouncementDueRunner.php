<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AnnouncementDueRunner
{
    /** هر چند ثانیه یک‌بار اجازه اجرا بدهیم (برای سبک نگه داشتن اجراها) */
    protected int $throttleSeconds = 30;

    /** حداکثر تعداد اعلانی که در هر تیک بررسی/ارسال می‌کنیم */
    protected int $batchLimit = 20;

    /** کلیدهای کمکی */
    protected string $throttleKey = 'announcements:due:last_run';
    protected string $lockName    = 'announcements_broadcast_due_lock';

    public function tick(): void
    {
        // لاک سراسری (DB-level) تا اجراهای همزمان با هم تداخل نکنند
        if (!$this->acquireDbLock($this->lockName)) {
            return;
        }

        try {
            // Throttle سبک؛ برای Scheduler هم بی‌ضرر است
            $now = now();
            $lastRun = cache()->get($this->throttleKey);
            if ($lastRun && $now->diffInSeconds(Carbon::parse($lastRun)) < $this->throttleSeconds) {
                return;
            }

            // اعلان‌های موعدرسیده و ارسال‌نشده را بگیر
            $dueIds = $this->findDueAnnouncementIds($this->batchLimit);

            foreach ($dueIds as $aid) {
                $this->broadcastOne((int) $aid);
            }

            cache()->put($this->throttleKey, $now->toDateTimeString(), $this->throttleSeconds);
        } finally {
            $this->releaseDbLock($this->lockName);
        }
    }

    /** شناسهٔ اعلان‌های موعدرسیده و آمادهٔ ارسال */
    protected function findDueAnnouncementIds(int $limit): array
    {
        $now = now();

        return DB::table('panel_announcements as a')
            // باید منتشر باشد
            ->where('a.is_published', true)
            // اگر زمان انتشار دارد باید رسیده باشد، یا اصلاً زمان انتشار ندارد (منتشر فوری)
            ->where(function ($q) use ($now) {
                $q->whereNull('a.publish_at')
                  ->orWhere('a.publish_at', '<=', $now);
            })
            // هنوز منقضی نشده
            ->where(function ($q) use ($now) {
                $q->whereNull('a.expires_at')
                  ->orWhere('a.expires_at', '>', $now);
            })
            // هنوز به تلگرام ارسال نشده
            ->whereNull('a.tg_broadcasted_at')
            ->orderBy('a.id')
            ->limit($limit)
            ->pluck('a.id')
            ->all();
    }

    /** ارسال امن یک اعلان (اتمیک مارک، بعد ارسال) */
    protected function broadcastOne(int $announcementId): void
    {
        // 1) اتمیک: اگر هنوز ارسال‌نشده، همین الان علامت بزن و سریع کامیت کن
        try {
            DB::beginTransaction();

            $affected = DB::table('panel_announcements')
                ->where('id', $announcementId)
                ->whereNull('tg_broadcasted_at')
                ->update(['tg_broadcasted_at' => now()]);

            if ($affected === 0) {
                // یا قبلاً ارسال شده، یا همزمانی باعث شده دیگری بگیرد
                DB::rollBack();
                return;
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('broadcastOne: failed to mark tg_broadcasted_at', [
                'announcement_id' => $announcementId,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        // 2) ارسال تلگرام (خارج از تراکنش تا لاک دیتابیس را طولانی نگه نداریم)
        try {
            app(\App\Services\TelegramNotifier::class)->broadcastAnnouncement($announcementId);
        } catch (\Throwable $e) {
            // اگر ارسال شکست خورد، علامت را برگردان تا تیک بعدی دوباره تلاش کند
            try {
                DB::table('panel_announcements')
                    ->where('id', $announcementId)
                    ->update(['tg_broadcasted_at' => null]);
            } catch (\Throwable $e2) {
                Log::error('broadcastOne: failed to rollback tg_broadcasted_at after send failure', [
                    'announcement_id' => $announcementId,
                    'error' => $e2->getMessage(),
                ]);
            }

            Log::warning("broadcastAnnouncement failed for #{$announcementId}: ".$e->getMessage());
        }
    }

    /** گرفتن لاک سراسری با GET_LOCK (بدون نیاز به Redis/Memcached) */
    protected function acquireDbLock(string $name): bool
    {
        try {
            $row = DB::select("SELECT GET_LOCK(?, 0) AS l", [$name]);
            $got = $row[0]->l ?? 0;
            return (int) $got === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** آزاد کردن لاک */
    protected function releaseDbLock(string $name): void
    {
        try {
            DB::select("SELECT RELEASE_LOCK(?)", [$name]);
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
