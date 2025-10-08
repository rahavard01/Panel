<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class V2User extends Model
{
    protected $table = 'v2_user';
    public $timestamps = true; // اگر جدول واقعاً created_at/updated_at دارد، می‌توانی true کنی

    protected $casts = [
        'expired_at'       => 'integer',
        'u'                => 'integer',
        'd'                => 'integer',
        'transfer_enable'  => 'integer',
        'banned'           => 'integer',
        'created_at'       => 'integer',
        // اگر created_at از نوع datetime است و ستون واقعاً وجود دارد، می‌توانی این را هم اضافه کنی:
        // 'created_at'     => 'datetime',
    ];
}
