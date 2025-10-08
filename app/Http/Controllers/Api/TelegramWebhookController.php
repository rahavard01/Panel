<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;

use App\Models\User;
use App\Models\PanelWalletReceipt;
use App\Models\PanelBotSession;
use App\Services\TransactionLogger;

class TelegramWebhookController extends Controller
{
    // === Conversation States for in-bot login ===
    const STATE_IDLE            = 'idle';
    const STATE_AWAIT_USERNAME  = 'awaiting_username';
    const STATE_AWAIT_PASSWORD  = 'awaiting_password';
    const STATE_LOGGED_IN       = 'logged_in';

    public function handle(Request $request)
    {
        $update = $request->all();

        // Secret header must match the one used when setting webhook
        $secret = \App\Services\TelegramConfig::webhookSecret();
        if ($secret) {
            $hdr = $request->header('X-Telegram-Bot-Api-Secret-Token');
            if ($hdr !== $secret) {
                // Return 200 so Telegram doesn't retry forever
                return response('OK', 200);
            }
        }

        // --- Handle inline keyboard callbacks first (admin receipt approval + logout) ---
        if ($cb = data_get($update, 'callback_query')) {

            $cbId      = (string) data_get($cb, 'id');
            $fromId    = (string) data_get($cb, 'from.id');
            $data      = (string) data_get($cb, 'data');
            $chatId    = (string) data_get($cb, 'message.chat.id');
            $messageId = (int)    data_get($cb, 'message.message_id');

            // 0) Universal "logout" button (available to any logged-in user)
            if ($data === 'logout') {
                try { $this->answerCb($cbId, 'خروج انجام شد'); } catch (\Throwable $e) {}
                $fromIdInt = (int) $fromId;
                $s = PanelBotSession::firstOrCreate(['chat_id' => $fromIdInt], ['state' => self::STATE_IDLE]);
                if ($s->state === self::STATE_LOGGED_IN && $s->panel_user_id) {
                    if ($u = User::find($s->panel_user_id)) {
                        $u->telegram_user_id = null;
                        $u->save();
                    }
                }
                $s->state = self::STATE_IDLE;
                $s->panel_user_id = null;
                $s->temp_username = null;
                $s->last_activity = now();
                $s->save();

                $this->sendMsgKb($fromId, "شما با موفقیت از ربات خارج شدید. برای ورود دوباره، روی «🔐 ورود» بزنید.", $this->buildMainMenu(false));
                return response()->noContent();
            }

            if (isset($update['callback_query'])) {
                $cb      = $update['callback_query'];
                $cbId    = (string) data_get($cb, 'id');
                $data    = (string) data_get($cb, 'data');
                $chatId  = (string) data_get($cb, 'message.chat.id');
                $msgId   = (int)    data_get($cb, 'message.message_id');

                // [ANN-TG] مشاهده اعلان توسط کاربر
                if (preg_match('/^ann:view:(\d+)$/', $data, $m)) {
                    $annId = (int) $m[1];

                    // کاربر لاگین‌شده
                    $user = \App\Models\User::where('telegram_user_id', $chatId)->first();
                    if (!$user) { try { $this->answerCb($cbId, 'لطفاً ابتدا در ربات لاگین کنید.'); } catch (\Throwable $e) {} return response('OK', 200); }

                    $a = \App\Models\PanelAnnouncement::publishedNow()->find($annId);
                    if (!$a) { try { $this->answerCb($cbId, 'این اعلان در دسترس نیست.'); } catch (\Throwable $e) {} return response('OK', 200); }

                    // ثبت ack/read + ذخیره chat/message برای حذف‌های بعدی
                    \App\Models\PanelAnnouncementRead::updateOrCreate(
                        ['announcement_id' => $a->id, 'user_id' => $user->id],
                        ['ack_at' => now(), 'read_at' => now(), 'tg_chat_id' => (string)$chatId, 'tg_message_id' => $msgId]
                    );

                    // حذف دکمه
                    try {
                        $token = \App\Services\TelegramConfig::token();
                        if ($token) {
                            $api = "https://api.telegram.org/bot{$token}";
                            \Illuminate\Support\Facades\Http::post("{$api}/editMessageReplyMarkup", [
                                'chat_id'      => $chatId,
                                'message_id'   => $msgId,
                                'reply_markup' => json_encode(['inline_keyboard' => []], JSON_UNESCAPED_UNICODE),
                            ]);
                        }
                    } catch (\Throwable $e) {}

                    // ارسال عنوان + متن
                    $title = trim((string)$a->title);
                    $body  = trim((string)$a->body);
                    $txt   = "*{$title}*\n\n{$body}";
                    try {
                        $token = \App\Services\TelegramConfig::token();
                        if ($token) {
                            $api = "https://api.telegram.org/bot{$token}";
                            \Illuminate\Support\Facades\Http::post("{$api}/sendMessage", [
                                'chat_id'    => $chatId,
                                'text'       => $txt,
                                'parse_mode' => 'Markdown',
                            ]);
                        }
                    } catch (\Throwable $e) {}

                    try { $this->answerCb($cbId, 'متن اعلان ارسال شد'); } catch (\Throwable $e) {}
                    return response('OK', 200);
                }

            }

            // 1) Admin-only callbacks (approve/reject receipts)
            $admin = User::where('role', 1)->where('telegram_user_id', $fromId)->first();
            if (!$admin) {
                // not admin → deny
                try { $this->answerCb($cbId, 'اجازه ندارید'); } catch (\Throwable $e) {}
                return response('OK', 200);
            }

            if (preg_match('/^wr:(ok|reject):(\d+)$/', $data, $m)) {
                $act = $m[1]; // ok | reject
                $rid = (int) $m[2];

                if ($act === 'ok') {
                    $amount = \DB::table('panel_wallet_receipts')->where('id', $rid)->value('amount') ?: 0;
                    $res = app(\App\Services\WalletReceiptService::class)->approve($rid, (int)$admin->id, (int)$amount);
                    $txt = $res['ok']
                        ? "✅ رسید #{$rid} تایید شد"
                        : "⛔ عملیات نامعتبر بود";
                } else {
                    $res = app(\App\Services\WalletReceiptService::class)->reject($rid, (int)$admin->id, null);
                    $txt = $res['ok']
                        ? "❌ رسید #{$rid} رد شد"
                        : "⛔ عملیات نامعتبر بود";
                }

                // Ack
                try { $this->answerCb($cbId, 'ثبت شد'); } catch (\Throwable $e) {}

                // خاموش‌کردن دکمه‌های همه پیام‌های مرتبط با این رسید (برای سایر ادمین‌ها هم)
                try { app(\App\Services\TelegramNotifier::class)->disableReceiptButtons($rid); } catch (\Throwable $e) {}

                // حذف کیبورد همین پیام فعلی هم (الگوی قبلی‌ات اگر خواستی حفظ شود)
                try { $this->editKb($chatId, $messageId, ['inline_keyboard' => []]); } catch (\Throwable $e) {}

                // پیام نتیجه برای همین ادمین
                try { $this->sendMsg($chatId, $txt); } catch (\Throwable $e) {}

                return response()->noContent();
            }
        }

        // --- Handle plain messages (/start, username, password) ---
        if ($msg = data_get($update, 'message')) {
            $this->handleLoginMessage($msg);
            return response()->noContent();
        }

        // Other update types are ignored
        return response()->noContent();
    }

    // === LOGIN CONVERSATION ===
    protected function handleLoginMessage(array $message): void
    {
        if (!isset($message['chat']['id'])) return;

        $chatIdStr = (string) ($message['chat']['id'] ?? '');
        if ($chatIdStr === '') return;
        $chatId = (int) $chatIdStr;
        $text   = trim((string) ($message['text'] ?? ''));
        if ($text === '') {
            $this->sendMsgKb(
                $chatIdStr,
                "سلام! برای شروع روی «🔐 ورود» بزنید یا /start ارسال کنید.",
                $this->buildMainMenu(false)
            );
            return;
        }
        $s = $this->getOrCreateSession($chatId);
        $s->last_activity = now();
        $s->save();

        // 1) خروج با منو/کامند — باید قبل از هر چیز هندل شود
        if ($text === '🚪 خروج' || $text === '/logout') {
            if ($s->state === self::STATE_LOGGED_IN && $s->panel_user_id) {
                if ($u = \App\Models\User::find($s->panel_user_id)) {
                    $u->telegram_user_id = null;
                    $u->save();
                }
            }
            $this->resetSession($s);
            $this->sendMsgKb($chatIdStr, "از اکانت خود خارج شدید، برای ورود مجدد، روی «🔐 ورود» بزنید", $this->buildMainMenu(false));
            return;
        }

        // 2) /start
            if ($text !== '' && preg_match('/^\/start(\s|$)/', $text)) {
            if ($s->state === self::STATE_LOGGED_IN) {
                $this->sendMsgKb(
                    $chatIdStr,
                    "شما قبلاً وارد شده‌اید، برای خروج روی دکمه زیر بزنید",
                    $this->buildMainMenu(true)
                );
                return;
            }
            $s->update([
                'state'         => self::STATE_AWAIT_USERNAME,
                'temp_username' => null,
            ]);
            $this->sendMsgKb($chatIdStr, "💬 لطفاً <b>ایمیل</b> خود را وارد کنید", $this->buildMainMenu(false));
            return;
        }

        // 3) شروع ورود با منو/کامند
        if ($text === '🔐 ورود' || $text === '/login') {
            $s->update([
                'state'         => self::STATE_AWAIT_USERNAME,
                'temp_username' => null,
            ]);
            $this->sendMsgKb($chatIdStr, "💬 لطفاً <b>ایمیل</b> خود را وارد کنید ", $this->buildMainMenu(false));
            return;
        }

        // 4) اگر هنوز لاگین است و چیز دیگری نوشت، فقط منو و پیام بده
        if ($s->state === self::STATE_LOGGED_IN) {
            $this->sendMsgKb(
                $chatIdStr,
                "شما قبلاً وارد شده‌اید، برای خروج روی دکمه زیر بزنید",
                $this->buildMainMenu(true)
            );
            return;
        }

        // 5) اگر هنوز /start نزده
        if ($s->state === self::STATE_IDLE) {
            $this->sendMsgKb(
                $chatIdStr,
                "سلام! برای شروع روی «🔐 ورود» بزنید یا /start ارسال کنید.",
                $this->buildMainMenu(false)
            );
            return;
        }

        // 6) دریافت ایمیل
        if ($s->state === self::STATE_AWAIT_USERNAME) {
            if (!filter_var($text, FILTER_VALIDATE_EMAIL)) {
                $this->sendMsg($chatIdStr, "ایمیل معتبر وارد کنید (مثلاً example@gmail.com)");
                return;
            }
            $s->update([
                'state'         => self::STATE_AWAIT_PASSWORD,
                'temp_username' => $text, // اینجا ایمیل را نگه می‌داریم
            ]);
            $this->sendMsg($chatIdStr, "💬 <b>رمز عبور</b> خود را وارد کنید ");
            return;
        }

        // 7) دریافت پسورد و لاگین
        if ($s->state === self::STATE_AWAIT_PASSWORD) {
            $email    = $s->temp_username;
            $password = $text;

            if (!$email) {
                $s->update(['state' => self::STATE_AWAIT_USERNAME, 'temp_username' => null]);
                $this->sendMsg($chatIdStr, "💬 ابتدا <b>ایمیل</b> خود را وارد کنید ");
                return;
            }

            $user = $this->findPanelUserByUsername($email);

            // اکانت به چت دیگری لینک شده؟
            if ($user && $user->telegram_user_id && (string)$user->telegram_user_id !== $chatIdStr) {
                $this->sendMsg($chatIdStr, " ⚠️  این حساب کاربری قبلاً به یک چت دیگر متصل شده است، ابتدا از آنجا خارج شوید");
                $s->update(['state' => self::STATE_AWAIT_USERNAME, 'temp_username' => null]);
                return;
            }

            if (!$user || !$this->checkPasswordFlexible($password, $user->password ?? '')) {
                $s->update(['state' => self::STATE_AWAIT_USERNAME, 'temp_username' => null]);
                $this->sendMsg($chatIdStr, "⛔️ ایمیل یا رمز عبور اشتباه است،  مجدد <b>ایمیل</b> تلاش کنید");
                return;
            }

            // موفق
            $user->telegram_user_id = $chatIdStr;
            $user->save();

            $s->update([
                'state'         => self::STATE_LOGGED_IN,
                'panel_user_id' => $user->id,
                'temp_username' => null,
            ]);

            $name = $user->name ?: ($user->username ?? $user->email);
            $this->sendMsgKb(
                $chatIdStr,
                "{$name} عزیز خوش آمدید ✨\n\nتمامی امکانات ربات برای شما فعال گردید",
                $this->buildMainMenu(true)
            );
            return;
        }
    }

    // === Receipt approval ===
    protected function applyDecision(string $act, int $receiptId, int $adminId): bool
    {
        return \DB::transaction(function () use ($act, $receiptId, $adminId) {
            /** @var \App\Models\PanelWalletReceipt|null $wr */
            $wr = \App\Models\PanelWalletReceipt::lockForUpdate()->find($receiptId);
            if (!$wr) return false;

            // فقط این دو عمل مجازند
            if (!in_array($act, ['ok', 'reject'], true)) return false;

            // وضعیت‌های معتبر طبق جریان فعلی پروژه: uploaded → submitted → (verified/rejected)
            $status = (string) ($wr->status ?? '');
            $allowed = ['uploaded', 'submitted']; // اگر حالت rechecking هم داری، به لیست اضافه کن

            if (!in_array($status, $allowed, true)) {
                // مثلاً اگر قبلاً verified یا rejected شده باشد، عملیات نامعتبر است
                return false;
            }

            if ($act === 'ok') {
                // ✅ شارژ خودِ کاربر + ست‌کردن status رسید به verified (متد موجود)
                app(\App\Services\TransactionLogger::class)->walletTopupCardVerified(
                    (int) $wr->user_id,
                    (int) $wr->amount,
                    (int) $wr->id,
                    (int) $adminId
                );

                // ✅ همان منطق مرحله ۳: پرداخت کمیسیون به معرّف
                $payer = \Illuminate\Support\Facades\DB::table('panel_users')
                    ->select('id','email','name','code','referred_by_id','ref_commission_rate')
                    ->where('id', $wr->user_id)
                    ->first();

                if ($payer) {
                    $referrerId = (int)($payer->referred_by_id ?? 0);
                    $rate       = (float)($payer->ref_commission_rate ?? 0);

                    if ($referrerId > 0 && $referrerId !== (int)$payer->id && $rate > 0 && !($wr->commission_paid ?? false)) {
                        $commission = (int) floor(((int)$wr->amount) * $rate / 100);

                        if ($commission > 0) {
                            $meta = [
                                'source_user_id'    => (int)$payer->id,
                                'source_user_email' => (string)($payer->email ?? ''),
                                'source_user_code'  => (string)($payer->code  ?? ''),
                                'source_user_name'  => (string)($payer->name  ?? ''),
                                'source_amount'     => (int)$wr->amount,
                                'commission_rate'   => (float)$rate,
                                'source_receipt_id' => (int)$wr->id,
                                'user_ids'          => [(int)$payer->id],
                            ];

                            $commissionTxId = app(\App\Services\TransactionLogger::class)
                                ->walletReferrerCommissionAwarded($referrerId, $commission, $meta, $adminId);

                            $wr->commission_paid  = true;
                            $wr->commission_tx_id = $commissionTxId;
                            $wr->save();
                        }
                    }
                }

                return true;
            }

            // ❌ رد: فقط وضعیت رسید را عوض کن (ستون‌های اضافه مثل approved_by در جدول شما نیستند)
            $wr->status       = 'rejected';
            $wr->notified_at  = null;
            $wr->save();

            return true;
        });
    }

    // === Telegram helpers from your original controller ===
    protected function sendMsg(string $chatId, string $text): void
    {
        $api = 'https://api.telegram.org/bot' . \App\Services\TelegramConfig::token() . '/sendMessage';
        Http::asForm()->post($api, [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ])->throw();
    }

    protected function editKb(string $chatId, int $messageId, array $kb): void
    {
        $api = 'https://api.telegram.org/bot' . \App\Services\TelegramConfig::token() . '/editMessageReplyMarkup';
        Http::asForm()->post($api, [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'reply_markup' => json_encode($kb, JSON_UNESCAPED_UNICODE),
        ])->throw();
    }

    protected function answerCb(string $cbId, string $text): void
    {
        $api = 'https://api.telegram.org/bot' . \App\Services\TelegramConfig::token() . '/answerCallbackQuery';
        Http::asForm()->post($api, [
            'callback_query_id' => $cbId,
            'text'              => $text,
            'show_alert'        => false,
        ])->throw();
    }

    // === Helpers for login conversation ===
    protected function buildLogoutKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [ ['text' => '🚪 خروج', 'callback_data' => 'logout'] ]
            ]
        ];
    }

    protected function getOrCreateSession(int $chatId): PanelBotSession
    {
        return PanelBotSession::firstOrCreate(
            ['chat_id' => $chatId],
            ['state' => self::STATE_IDLE]
        );
    }

    protected function resetSession(PanelBotSession $s): void
    {
        $s->update([
            'state'          => self::STATE_IDLE,
            'panel_user_id'  => null,
            'temp_username'  => null,
            'last_activity'  => now(),
        ]);
    }

    protected function findPanelUserByUsername(string $u): ?User
    {
        return User::query()
            ->where('email', $u)
            ->first();
    }

    protected function checkPasswordFlexible(string $plain, string $stored): bool
    {
        if (Hash::check($plain, $stored)) return true;
        return hash_equals($stored, $plain);
    }

    protected function sendMsgKb(string $chatId, string $text, array $kb): void
    {
        $api = 'https://api.telegram.org/bot' . \App\Services\TelegramConfig::token() . '/sendMessage';
        Http::asForm()->post($api, [
            'chat_id'      => $chatId,
            'text'         => $text,              // متن خالی نباشد
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode($kb, JSON_UNESCAPED_UNICODE),
        ])->throw();
    }

    // منوی اصلی چسبیده (Reply Keyboard) — وقتی لاگین است فقط «خروج»، وقتی نیست فقط «ورود»
    protected function buildMainMenu(bool $loggedIn): array
    {
        if ($loggedIn) {
            return [
                'keyboard' => [
                    [ ['text' => '🚪 خروج'] ],
                ],
                'resize_keyboard'   => true,
                'one_time_keyboard' => false,
                'is_persistent'     => true,
            ];
        }
        return [
            'keyboard' => [
                [ ['text' => '🔐 ورود'] ],
            ],
            'resize_keyboard'   => true,
            'one_time_keyboard' => false,
            'is_persistent'     => true,
        ];
    }
}
