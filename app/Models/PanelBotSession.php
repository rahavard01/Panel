<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class PanelBotSession extends Model
{
    protected $table = 'panel_bot_sessions';

    protected $fillable = [
        'chat_id','state','panel_user_id','temp_username','last_activity',
    ];

    protected $casts = [
        'chat_id' => 'integer',
        'panel_user_id' => 'integer',
        'last_activity' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'panel_user_id');
    }
}
