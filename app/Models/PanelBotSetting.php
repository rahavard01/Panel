<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PanelBotSetting extends Model
{
    protected $table = 'panel_bot_settings';

    protected $fillable = [
        'enabled',
        'notify_on_card_submit',
        'allow_approve_via_telegram',
        'use_photo',
        'message_template',
        'bot_token',
        'webhook_secret',
    ];

    protected $casts = [
        'enabled'                     => 'boolean',
        'notify_on_card_submit'       => 'boolean',
        'allow_approve_via_telegram'  => 'boolean',
        'use_photo'                   => 'boolean',
        'bot_token'                   => 'encrypted',
        'webhook_secret'              => 'encrypted',
    ];

    public static function getSingleton(): self
    {
        return static::first() ?? static::create([
            'enabled' => false,
            'message_template' =>
                "🧾 رسید کارت‌به‌کارت\n".
                "کاربر: {user_code}\n".
                "مبلغ: {amount} تومان\n".
                "زمان: {created_at}\n".
                "شناسه رسید: {id}\n",
        ]);
    }
}
