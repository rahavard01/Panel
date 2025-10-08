<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Services\AnnouncementDueRunner;
use App\Services\V2UserExpiryRunner;  // ← ایمپورت درست است

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // هر دقیقه اعلان‌هایی که موعدشان رسیده را چک و ارسال کن
        $schedule->call(function () {
            app(AnnouncementDueRunner::class)->tick();
        })
        ->everyMinute()
        ->name('announcements:due-tick')
        ->evenInMaintenanceMode();

        // ←← جاب جدید: ست‌کردن expired_at برای v2_user وقتی t مقدار گرفت
        $schedule->call(function () {
            app(V2UserExpiryRunner::class)->tick();
        })
        ->everyMinute()                 // هم‌راستا با بالا؛ throttle داخل سرویس فعال است
        ->name('v2user:expiry-tick')
        ->evenInMaintenanceMode();
        // ->onOneServer();  // اگر چند سرور داری و cache/lock توزیع‌شده داری، می‌تونی این را هم اضافه کنی
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
    }
}
