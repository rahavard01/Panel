<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PanelWalletReceipt extends Model
{
    protected $table = 'panel_wallet_receipts';

    protected $fillable = [
        'user_id',
        'amount',
        'method',
        'disk',
        'path',
        'original_name',
        'mime',
        'size',
        'status',
        'meta',
        'notified_at',              // نوتیف خود کاربر بعد از شارژ
        'commission_paid',          // پرچم پرداخت کمیسیون معرّف (از مایگریشن قبلی تو)
        'commission_tx_id',         // شناسه تراکنش کمیسیون در panel_transactions
        'commission_notified_at',   // نوتیف پنلی معرّف بابت کمیسیون
    ];

    protected $casts = [
        'meta'                   => 'array',
        'notified_at'            => 'datetime',
        'commission_paid'        => 'boolean',
        'commission_notified_at' => 'datetime',
    ];
}
