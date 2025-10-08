<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class TelegramNotifier
{
    protected string $api;
    protected array $admins;

    public function __construct()
    {
        $token        = \App\Services\TelegramConfig::token();
        $this->api    = "https://api.telegram.org/bot{$token}";
        $this->admins = \App\Services\TelegramConfig::adminChatIds();
    }

    public function isEnabled(): bool
    {
        return \App\Services\TelegramConfig::enabled() && !empty($this->admins);
    }

    /** ارسال متن ساده (تست/فالبک) */
    public function sendTextToAdmins(string $text): bool
    {
        if (!$this->isEnabled()) return false;

        foreach ($this->admins as $chatId) {
            Http::asForm()->post("{$this->api}/sendMessage", [
                'chat_id'                  => $chatId,
                'text'                     => $this->rtl($text),
                'disable_web_page_preview' => true,
            ])->throw();
        }
        return true;
    }

    /** ارسال رسید با مدیا (بدون کپشن) + متن جداگانه با دکمه‌ها */
    public function sendReceiptToAdmins(array $payload): bool
    {
        if (!$this->isEnabled()) return false;

        $receiptId = (int)($payload['id'] ?? 0);

        $tpl = \App\Services\TelegramConfig::template() ?:
            "🧾 رسید کارت‌به‌کارت\n".
            "کاربر: {user_code}\n".
            "مبلغ: {amount} تومان\n".
            "زمان: {created_at}\n".
            "شناسه رسید: {id}\n";

        $text = strtr($tpl, [
            '{id}'         => $this->rtl($this->esc((string)($payload['id'] ?? ''))),
            '{email}'      => $this->wrapLtr($this->esc((string)($payload['user_email'] ?? ''))),
            '{user_code}'  => $this->wrapLtr($this->esc((string)($payload['user_code']  ?? ''))),
            '{amount}'     => $this->wrapLtr(number_format((int)($payload['amount'] ?? 0))),
            '{created_at}' => $this->wrapLtr($this->esc((string)($payload['created_at'] ?? ''))),
        ]);

        $kb = null;
        if (\App\Services\TelegramConfig::allowApproveViaTelegram()) {
            $kb = [
                'inline_keyboard' => [[
                    ['text' => '✅ تأیید', 'callback_data' => 'wr:ok:'     . $receiptId],
                    ['text' => '❌ رد',   'callback_data' => 'wr:reject:' . $receiptId],
                ]]
            ];
        }
        $kbJson = $kb ? json_encode($kb, JSON_UNESCAPED_UNICODE) : null;

        $usePhoto  = \App\Services\TelegramConfig::usePhoto();
        $photoUrl  = $payload['photo_url']  ?? null;
        $localPath = $payload['local_path'] ?? null;
        $mime      = strtolower((string)($payload['mime'] ?? ''));
        $isImage   = str_starts_with($mime, 'image/');

        $saved = []; // برای ذخیره chat_id/message_id ها

        foreach ($this->admins as $chatId) {
            try {
                // 1) مدیا (بدون کپشن)
                if ($localPath && @is_file($localPath)) {
                    if ($usePhoto && $isImage) {
                        $resp = \Illuminate\Support\Facades\Http::asMultipart()
                            ->attach('photo', fopen($localPath, 'rb'), basename($localPath))
                            ->post("{$this->api}/sendPhoto", ['chat_id' => $chatId]);
                    } else {
                        $resp = \Illuminate\Support\Facades\Http::asMultipart()
                            ->attach('document', fopen($localPath, 'rb'), basename($localPath))
                            ->post("{$this->api}/sendDocument", ['chat_id' => $chatId]);
                    }
                    $resp->throw();
                } elseif ($photoUrl && preg_match('/^https?:\/\/(?!localhost|127\.0\.0\.1)/i', $photoUrl)) {
                    if ($usePhoto && $isImage) {
                        $resp = \Illuminate\Support\Facades\Http::asForm()->post("{$this->api}/sendPhoto", [
                            'chat_id' => $chatId,
                            'photo'   => $photoUrl,
                        ]);
                    } else {
                        $resp = \Illuminate\Support\Facades\Http::asForm()->post("{$this->api}/sendDocument", [
                            'chat_id'  => $chatId,
                            'document' => $photoUrl,
                        ]);
                    }
                    $resp->throw();
                }

                // 2) متن + دکمه (reply_markup)
                $resp2 = \Illuminate\Support\Facades\Http::asForm()->post("{$this->api}/sendMessage", [
                    'chat_id'      => $chatId,
                    'text'         => $this->rtl($text),
                    'parse_mode'   => 'HTML',
                    'reply_markup' => $kbJson,
                    'disable_web_page_preview' => true,
                ])->throw();

                $json = $resp2->json();
                $mid  = (int) data_get($json, 'result.message_id');
                if ($mid > 0) {
                    $saved[] = ['chat_id' => (string)$chatId, 'message_id' => $mid];
                }
            } catch (\Throwable $e) {
                // خطای ارسال را نادیده بگیر تا روند اصلی نخوابد
            }
        }

        // ذخیره message_idها داخل meta رسید
        if ($receiptId && !empty($saved)) {
            try {
                $wr = \App\Models\PanelWalletReceipt::find($receiptId);
                if ($wr) {
                    $meta = [];
                    if ($wr->meta) { try { $meta = json_decode($wr->meta, true) ?: []; } catch (\Throwable $e) {} }
                    $meta['telegram_messages'] = array_values(array_unique(array_merge($meta['telegram_messages'] ?? [], $saved), SORT_REGULAR));
                    $wr->meta = json_encode($meta, JSON_UNESCAPED_UNICODE);
                    $wr->save();
                }
            } catch (\Throwable $e) {}
        }

        return true;
    }

    /** ——— کمکی‌ها ——— */

    protected function sendKbMessage(string|int $chatId, string $text, ?string $kbJson): void
    {
        Http::asForm()->post("{$this->api}/sendMessage", [
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'HTML',
            'reply_markup' => $kbJson,
        ])->throw();
    }

    /** Escape برای HTML */
    protected function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** اعداد انگلیسی → فارسی (کمک به RTL پایدار) */
//    protected function faDigits(string $s): string
//    {
//        $en = ['0','1','2','3','4','5','6','7','8','9', ','];
//        $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹', '،'];
//        return str_replace($en, $fa, $s);
//    }

    /** ایزوله‌ی LTR برای مقادیر لاتین/رقمی (user_code, id, amount, date) */
    protected function wrapLtr(string $s): string
    {
        $LRI = "\u{2066}";
        $PDI = "\u{2069}";
        return $LRI . $s . $PDI;
    }

    /** بستن کل متن به RTL (پایدارتر برای iOS) */
    protected function rtl(string $s): string
    {
        $RLM = "\u{200F}";
        $RLE = "\u{202B}";
        $PDF = "\u{202C}";
        return $RLM . $RLE . $s . $PDF;
    }
    // تبدیل زمان به شمسی با اعداد لاتین (بدون وابستگی)
    protected function toJalali(string $dt): string
    {
        try {
            $tz   = config('app.timezone', date_default_timezone_get());
            $date = $dt ? new \DateTime($dt, new \DateTimeZone($tz)) : null;
        } catch (\Throwable $e) { $date = null; }

        if (!$date) return '';

        $gy = (int)$date->format('Y');
        $gm = (int)$date->format('n');
        $gd = (int)$date->format('j');

        [$jy, $jm, $jd] = $this->gregorianToJalaliParts($gy, $gm, $gd);
        $hh = $date->format('H');
        $ii = $date->format('i');

        return sprintf('%04d/%02d/%02d %s:%s', $jy, $jm, $jd, $hh, $ii);
    }

    // الگوریتم تبدیل میلادی → شمسی
    protected function gregorianToJalaliParts(int $gy, int $gm, int $gd): array
    {
        $g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
        $gy2 = $gy - 1600;
        $gm2 = $gm - 1;
        $gd2 = $gd - 1;

        $g_day_no = 365*$gy2 + intdiv($gy2+3,4) - intdiv($gy2+99,100) + intdiv($gy2+399,400);
        $g_day_no += $g_d_m[$gm2] + $gd2;
        if ($gm2 > 1 && (($gy%4==0 && $gy%100!=0) || ($gy%400==0))) $g_day_no++;

        $j_day_no = $g_day_no - 79;
        $j_np = intdiv($j_day_no, 12053);
        $j_day_no %= 12053;

        $jy = 979 + 33*$j_np + 4*intdiv($j_day_no,1461);
        $j_day_no %= 1461;

        if ($j_day_no >= 366) {
            $jy += intdiv($j_day_no-366,365);
            $j_day_no = ($j_day_no-366) % 365;
        }

        $j_month_days = [31,31,31,31,31,31,30,30,30,30,30,29];
        $jm = 0;
        while ($jm < 12 && $j_day_no >= $j_month_days[$jm]) {
            $j_day_no -= $j_month_days[$jm];
            $jm++;
        }
        $jd = $j_day_no + 1;

        return [$jy, $jm+1, $jd];
    }

    public function sendTextTo(string $chatId, string $text): bool
    {
        // متن را RTL می‌کنیم تا روی همه دستگاه‌ها درست نمایش داده شود
        $text = $this->rtl($text);

        \Illuminate\Support\Facades\Http::asForm()->post("{$this->api}/sendMessage", [
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
        ])->throw();

        return true;
    }

    public function disableReceiptButtons(int $receiptId): void
    {
        try {
            $wr = \App\Models\PanelWalletReceipt::find($receiptId);
            if (!$wr) return;
            $meta = [];
            if ($wr->meta) { try { $meta = json_decode($wr->meta, true) ?: []; } catch (\Throwable $e) {} }
            $list = $meta['telegram_messages'] ?? [];
            foreach ($list as $row) {
                $chatId = $row['chat_id'] ?? null;
                $msgId  = $row['message_id'] ?? null;
                if ($chatId && $msgId) {
                    try {
                        \Illuminate\Support\Facades\Http::asForm()->post("{$this->api}/editMessageReplyMarkup", [
                            'chat_id'    => $chatId,
                            'message_id' => $msgId,
                            'reply_markup' => json_encode(['inline_keyboard' => []]),
                        ])->throw();
                    } catch (\Throwable $e) {}
                }
            }
        } catch (\Throwable $e) {}
    }

    public function broadcastReceiptDecisionText(int $receiptId, string $action, ?string $reason = null): void
    {
        $action = ($action === 'ok' ? 'ok' : 'reject');
        $txt = $action === 'ok'
            ? "✅ رسید #{$receiptId} تایید شد"
            : "❌ رسید #{$receiptId} رد شد" . ($reason ? " — دلیل: {$reason}" : "");

        $this->sendTextToAdmins($txt);
    }

    // [ANN-TG] ارسال اعلان به یک کاربر (و ذخیره chat_id/message_id برای حذف فوری دکمه)
    public function sendAnnouncementToUser(int $userId, \App\Models\PanelAnnouncement $a): void
    {
        try {
            // فقط کاربر، نه ادمین
            $row = \DB::table('panel_users')->where('id', $userId)->first();
            if (!$row) return;
            if ((int)($row->role ?? 2) === 1) return; // role=1 → ادمین، ارسال نکن
            $chatId = $row->telegram_user_id ?? null;
            if (!$chatId) return;

            $title = trim((string)$a->title);
            $pubAt = $a->publish_at ? \Carbon\Carbon::parse($a->publish_at)->format('H:i d-m-Y') : now()->format('H:i d-m-Y');

            $text  = "📣 شما یک اعلان جدید دارید\n";
            $text .= "📅 تاریخ انتشار: {$pubAt}\n";
            $text .= "💬 {$title}";

            $kb = [
                'inline_keyboard' => [
                    [
                        ['text' => '👁 مشاهده', 'callback_data' => "ann:view:{$a->id}"],
                    ],
                ],
            ];

            $token = \App\Services\TelegramConfig::token();
            if (!$token) return;
            $api = "https://api.telegram.org/bot{$token}";

            $resp = \Illuminate\Support\Facades\Http::post("{$api}/sendMessage", [
                'chat_id'      => (string)$chatId,
                'text'         => $text,
                'reply_markup' => json_encode($kb, JSON_UNESCAPED_UNICODE),
            ])->throw()->json();

            $msgId = (int) data_get($resp, 'result.message_id');

            // نگه‌داری chat_id و msg_id برای حذف فوری دکمه
            \App\Models\PanelAnnouncementRead::updateOrCreate(
                ['announcement_id' => $a->id, 'user_id' => (int)$userId],
                ['tg_chat_id' => (string)$chatId, 'tg_message_id' => $msgId] // ack/read بعداً پر می‌شوند
            );
        } catch (\Throwable $e) {
            // silent
        }
    }

    // [ANN-TG] ارسال اعلان به همه‌ی کاربران تلگرام‌دار (به‌جز ادمین‌ها)
    public function broadcastAnnouncement(int $announcementId): int
    {
        try {
            /** @var \App\Models\PanelAnnouncement|null $a */
            $a = \App\Models\PanelAnnouncement::find($announcementId);
            if (!$a) return 0;

            // فقط وقتی منتشر شده و زمان انتشار رسیده
            $okTime = ($a->is_published === true)
                && (is_null($a->publish_at) || \Carbon\Carbon::parse($a->publish_at)->isPast())
                && (is_null($a->expires_at) || \Carbon\Carbon::parse($a->expires_at)->isFuture());
            if (!$okTime) return 0;

            // فقط کاربران با تلگرام و role != 1
            $users = \DB::table('panel_users')
                ->whereNotNull('telegram_user_id')
                ->where('role', '!=', 1)
                ->pluck('id');

            $cnt = 0;
            foreach ($users as $uid) {
                $this->sendAnnouncementToUser((int)$uid, $a);
                $cnt++;
            }
            return $cnt;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    // --- [TUTORIALS] Helpers & senders ---

    /**
     * برچسب و آیکون دسته‌بندی برای پیام تلگرام
     */
    protected function tutorialCategoryChip(?string $cat): array
    {
        $cat = strtolower((string)$cat);
        switch ($cat) {
            case 'ios':     return ['label' => 'اپل',    'icon' => "📱"];      // یا \uF8FF
            case 'android': return ['label' => 'اندروید','icon' => "🤖"];
            case 'system':  return ['label' => 'سیستم',  'icon' => "🖥️"];
            default:        return ['label' => '—',      'icon' => "•"];
        }
    }

    /**
     * متن پیام «منتشر شد»
     */
    protected function buildTutorialCreatedText(\App\Models\PanelTutorial $t): string
    {
        $chip = $this->tutorialCategoryChip($t->category ?? '');
        $app  = trim((string)$t->app_name);
        $date = ($t->published_at ?? now())->format('H:i d-m-Y'); // سمت کاربر متن CTA داریم، تاریخ هم فرمت ساده
        return "🔰 آموزش برنامه «{$app}» منتشر شد\n"
            ."📅 تاریخ انتشار: {$date}\n"
            ."{$chip['icon']} دسته‌بندی: {$chip['label']}\n\n"
            ."جهت مشاهده به پنل کاربری خود در قسمت «آموزش‌ها» مراجعه کنید.";
    }

    /**
     * متن پیام «به‌روزرسانی شد»
     */
    protected function buildTutorialUpdatedText(\App\Models\PanelTutorial $t): string
    {
        $chip = $this->tutorialCategoryChip($t->category ?? '');
        $app  = trim((string)$t->app_name);
        return "🔰 آموزش برنامه «{$app}» به‌روزرسانی شد\n"
            ."{$chip['icon']} دسته‌بندی: {$chip['label']}\n\n"
            ." جهت مشاهده به پنل کاربری خود در قسمت «آموزش‌ها» مراجعه کنید.";
    }

    /**
     * ارسال پیام «منتشر شد» به یک کاربر
     */
    public function sendTutorialCreatedToUser(int $chatId, \App\Models\PanelTutorial $t): void
    {
        if (!$this->isEnabled()) return;
        $text = $this->buildTutorialCreatedText($t);
        // اگر دکمه هم خواستی اضافه کنی، اینجا می‌تونی inline keyboard بدی
        $this->sendTextTo($chatId, $text);
    }

    /**
     * ارسال پیام «آپدیت‌شد» به یک کاربر
     */
    public function sendTutorialUpdatedToUser(int $chatId, \App\Models\PanelTutorial $t): void
    {
        if (!$this->isEnabled()) return;
        $text = $this->buildTutorialUpdatedText($t);
        $this->sendTextTo($chatId, $text);
    }

    /**
     * Broadcast: فقط برای «کاربران» با تلگرام وصل‌شده
     */
    public function broadcastTutorialCreated(\App\Models\PanelTutorial $t): void
    {
        if (!$this->isEnabled()) return;

        // کاربران با chat_id (فیلد مرسوم در پروژه: users.telegram_user_id)
        $adminIds = \App\Services\TelegramConfig::adminChatIds(); // احتیاطی برای حذف ادمین‌ها

        $chatIds = \DB::table('panel_users')
            ->whereNotNull('telegram_user_id')
            ->where('role', '!=', 1)     // فقط کاربرها (ادمین: role=1)
            ->where('banned', 0)         // اگر ستون banned دارید، نرفتن برای بن‌شده‌ها
            ->pluck('telegram_user_id')
            ->filter()
            ->map(fn($v) => (int)$v)
            ->unique()
            ->reject(fn($id) => in_array((string)$id, $adminIds, true)) // حذف قطعی ادمین‌ها
            ->values();

        foreach ($chatIds as $chatId) {
            try { $this->sendTutorialCreatedToUser($chatId, $t); }
            catch (\Throwable $e) { \Log::warning('TG send tutorial created failed', ['chat'=>$chatId,'err'=>$e->getMessage()]); }
        }
    }

    public function broadcastTutorialUpdated(\App\Models\PanelTutorial $t): void
    {
        if (!$this->isEnabled()) return;

        $adminIds = \App\Services\TelegramConfig::adminChatIds(); // احتیاطی برای حذف ادمین‌ها

        $chatIds = \DB::table('panel_users')
            ->whereNotNull('telegram_user_id')
            ->where('role', '!=', 1)     // فقط کاربرها (ادمین: role=1)
            ->where('banned', 0)         // اگر ستون banned دارید، نرفتن برای بن‌شده‌ها
            ->pluck('telegram_user_id')
            ->filter()
            ->map(fn($v) => (int)$v)
            ->unique()
            ->reject(fn($id) => in_array((string)$id, $adminIds, true)) // حذف قطعی ادمین‌ها
            ->values();

        foreach ($chatIds as $chatId) {
            try { $this->sendTutorialUpdatedToUser($chatId, $t); }
            catch (\Throwable $e) { \Log::warning('TG send tutorial updated failed', ['chat'=>$chatId,'err'=>$e->getMessage()]); }
        }
    }

}
