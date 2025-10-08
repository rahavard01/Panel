<?php

namespace App\Services;

use App\Models\PanelBotSetting;

class TelegramConfig
{
    /** تنظیمات سراسری ربات (سطر singleton) */
    protected static function s(): PanelBotSetting
    {
        return PanelBotSetting::getSingleton();
    }

    /** 🔵 منبع واحد توکن: اول DB (encrypted)، بعد fallback به config/env */
    public static function token(): ?string
    {
        $db = (string) (self::s()->bot_token ?? '');
        if (trim($db) !== '') {
            return $db;
        }
        return config('services.telegram.bot_token') ?: env('TELEGRAM_BOT_TOKEN') ?: null;
    }

    /** سکرت وبهوک: اول DB، بعد fallback به config/env */
    public static function webhookSecret(): ?string
    {
        $db = (string) (self::s()->webhook_secret ?? '');
        if (trim($db) !== '') {
            return $db;
        }
        return config('services.telegram.webhook_secret') ?: env('TELEGRAM_WEBHOOK_SECRET') ?: null;
    }

    /** آیا ارسال تلگرام فعال است؟ (توکن + فلگ enabled) */
    public static function enabled(): bool
    {
        $t = trim((string) self::token());
        return $t !== '' && (bool) self::s()->enabled;
    }

    /** chat_id ادمین‌ها (role=1) */
    public static function adminChatIds(): array
    {
        return \DB::table('panel_users')
            ->where('role', 1)
            ->whereNotNull('telegram_user_id')
            ->pluck('telegram_user_id')
            ->map(fn($v) => (string) $v)
            ->unique()
            ->values()
            ->all();
    }

    public static function notifyOnCardSubmit(): bool
    {
        return (bool) self::s()->notify_on_card_submit;
    }

    public static function allowApproveViaTelegram(): bool
    {
        return (bool) self::s()->allow_approve_via_telegram;
    }

    public static function usePhoto(): bool
    {
        return (bool) self::s()->use_photo;
    }

    /** قالب متن رسید */
    public static function template(): string
    {
        return (string) (self::s()->message_template ??
            "🧾 رسید کارت‌به‌کارت\n".
            "کاربر: {user_code}\n".
            "مبلغ: {amount} تومان\n".
            "زمان: {created_at}\n".
            "شناسه رسید: {id}\n"
        );
    }

    /** URL وبهوک بر اساس APP_URL یا host فعلی */
    public static function webhookUrl(?string $host = null): string
    {
        $base = rtrim(config('app.url') ?: ($host ?? ''), '/');
        return $base . '/api/telegram/webhook';
    }
}
