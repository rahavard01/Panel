<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PanelAnnouncement extends Model
{
    protected $table = 'panel_announcements';

    protected $fillable = [
        'title','body','audience','is_published','publish_at','expires_at','created_by',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'publish_at'   => 'datetime',
        'expires_at'   => 'datetime',
    ];

    public function reads(): HasMany
    {
        return $this->hasMany(PanelAnnouncementRead::class, 'announcement_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** فقط اعلان‌هایی که الان باید دیده شوند (منتشر و زمانش رسیده و منقضی نشده) */
    public function scopePublishedNow($q)
    {
        return $q
            // باید رسماً منتشر باشد
            ->where('is_published', true)
            // و اگر زمان انتشار دارد، باید رسیده باشد (یا زمان ندارد = فوری)
            ->where(function($qq){
                $qq->whereNull('publish_at')
                ->orWhere('publish_at', '<=', now());
            })
            // و هنوز منقضی نشده باشد
            ->where(function($qq){
                $qq->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
            });
    }


}
