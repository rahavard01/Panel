<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PanelAnnouncementRead extends Model
{
    protected $table = 'panel_announcement_reads';

    protected $fillable = [
        'announcement_id','user_id','ack_at','read_at','tg_chat_id','tg_message_id',
    ];

    protected $casts = [
        'ack_at'  => 'datetime',
        'read_at' => 'datetime',
    ];

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(PanelAnnouncement::class, 'announcement_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
