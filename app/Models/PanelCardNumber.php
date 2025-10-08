<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PanelCardNumber extends Model
{
    protected $table = 'panel_card_number'; // همان جدولی که گفتی
    public $timestamps = false;             // اگر ستون‌های زمانی نداری

    protected $fillable = [
        'card',   // شماره کارت 16 رقمی (بدون فاصله/خط تیره)
        'name',   // نام صاحب کارت
        // 'is_active', // اگر داشتی، بعداً استفاده می‌کنیم
    ];
}
