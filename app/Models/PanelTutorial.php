<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class PanelTutorial extends Model
{
    protected $table = 'panel_tutorials';

    protected $fillable = [
        'category','app_name','icon_path','download_url','video_path','desc','published_at','created_by',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    protected $appends = ['icon_url','video_url'];

    public function getIconUrlAttribute(): ?string
    {
        $p = $this->icon_path ?: null;
        if (!$p) return null;

        // --- نرمال‌سازی دفاعی: هر ورودی بد را تمیز کن ---
        $p = ltrim($p);
        $p = preg_replace('#^\?+#', '', $p);                 // حذف ? ابتدای رشته (منشأ /storage/?https://...)
        $p = preg_replace('#^https?://[^/]+/#i', '', $p);    // حذف دامنه اگر اشتباهاً ذخیره شده
        $p = preg_replace('#^/?storage/?#i', '', $p);        // حذف پیشوند storage/
        $p = preg_replace('#^public/#i', '', $p);            // حذف public/
        $p = ltrim($p, '/');                                 // حذف / اضافه ابتدای مسیر

        return Storage::disk('public')->url($p);             // خروجی نهایی: /storage/...
    }

    public function getVideoUrlAttribute(): ?string
    {
        $p = $this->video_path ?: null;
        if (!$p) return null;

        // --- همان نرمال‌سازی ---
        $p = ltrim($p);
        $p = preg_replace('#^\?+#', '', $p);
        $p = preg_replace('#^https?://[^/]+/#i', '', $p);
        $p = preg_replace('#^/?storage/?#i', '', $p);
        $p = preg_replace('#^public/#i', '', $p);
        $p = ltrim($p, '/');

        return Storage::disk('public')->url($p);
    }

    protected static function booted()
    {
        static::created(function (self $t) {
            try {
                app(\App\Services\TelegramNotifier::class)->broadcastTutorialCreated($t);
            } catch (\Throwable $e) {
                \Log::warning('TG broadcast on tutorial created failed', ['id'=>$t->id, 'err'=>$e->getMessage()]);
            }
        });

        static::updated(function (self $t) {
            // فقط وقتی واقعاً چیزی مهم تغییر کرد که ارزش نوتیف داشته باشد:
            $important = ['app_name','category','desc','download_url','icon_path','video_path','published_at'];
            if (! array_intersect($important, array_keys($t->getChanges()))) {
                return;
            }
            // === Track last important update timestamp (for panel toasts) ===
            try {
                $t->last_important_updated_at = now();
                // جلوگیری از تریگر مجدد رویدادها:
                $t->saveQuietly();
            } catch (\Throwable $e) {
                \Log::warning('set last_important_updated_at failed', ['id'=>$t->id, 'err'=>$e->getMessage()]);
            }
            try {
                app(\App\Services\TelegramNotifier::class)->broadcastTutorialUpdated($t);
            } catch (\Throwable $e) {
                \Log::warning('TG broadcast on tutorial updated failed', ['id'=>$t->id, 'err'=>$e->getMessage()]);
            }
        });
    }
}
