<?php

return [
    // جداول
    'tables' => [
        'users'              => 'v2_user',              // کاربران نهایی ایکس‌برد
        'panel_users'        => 'panel_users',          // کاربران پنل (همکاران با role=2)
        'transactions'       => 'panel_transactions',   // تراکنش‌ها (خرید/تمدید/واریزی کارت)
        'wallet_receipts'    => 'panel_wallet_receipts',// رسیدهای واریزی کیف پول (pending)
        'referrals'          => 'panel_referrals',      // درخواست نمایندگی (pending)
    ],

    // Type های تراکنش مطابق دیتابیس شما
    'transaction_types' => [
        'purchase' => 'account_purchase', // خرید اکانت
        'renewal'  => 'account_extend',   // تمدید اکانت  ← نکته مهم همین است
        'topup'    => 'wallet_topup_card' // شارژ کیف پولِ کارت
    ],

    // وضعیت‌های رایج
    'statuses' => [
        'success'          => 'success',     // برای تراکنش‌های موفق
        'pending_receipt'  => 'submitted',   // رسیدهای در انتظار بررسی
        'pending_referral' => 'pending',     // درخواست نمایندگی در انتظار
    ],

    // تنظیمات آنلاین بودن (ثانیه)
    'presence_window_sec' => 60,

    // ناحیه زمانی
    'tz' => 'Asia/Tehran',
];
