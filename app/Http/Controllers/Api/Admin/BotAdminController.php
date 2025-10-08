<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use App\Models\PanelBotSetting;
use App\Services\TelegramConfig;

class BotAdminController extends Controller
{
    /**
     * GET /api/admin/bot/status
     * وضعیت فعلی: پریویوی امن توکن/سکرت + وضعیت وبهوک
     */
    public function status(Request $request)
    {
        $token  = (string) (TelegramConfig::token() ?? '');
        $secret = (string) (TelegramConfig::webhookSecret() ?? '');

        $mask = function (?string $val, int $keepStart = 5, int $keepEnd = 4) {
            $s = (string) ($val ?? '');
            $len = mb_strlen($s);
            if ($len === 0) return '';
            if ($len <= $keepStart + $keepEnd) return $s;
            return mb_substr($s, 0, $keepStart) . '…' . mb_substr($s, -$keepEnd);
        };

        $webhookUrl = TelegramConfig::webhookUrl($request->getSchemeAndHttpHost());

        $webhookSet  = false;
        $webhookInfo = null;
        if (trim($token) !== '') {
            try {
                $api  = "https://api.telegram.org/bot{$token}";
                $resp = Http::timeout(8)->get("{$api}/getWebhookInfo");
                if ($resp->ok()) {
                    $j = $resp->json();
                    $webhookInfo = $j;
                    $webhookSet  = (bool) data_get($j, 'result.url');
                }
            } catch (\Throwable $e) {
                $webhookInfo = ['error' => $e->getMessage()];
            }
        }

        return response()->json([
            'ok'             => true,
            'has_token'      => trim($token) !== '',
            'token_preview'  => trim($token) ? $mask($token) : '',
            'secret_preview' => trim($secret) ? $mask($secret, 4, 3) : '',
            'webhook_url'    => $webhookUrl,
            'webhook_set'    => $webhookSet,
            'webhook_info'   => $webhookInfo,
        ]);
    }

    /**
     * POST /api/admin/bot/set-webhook
     * Body: { "token"?: "xxxxx:yyyyy" }
     *
     * اگر token در بدنه نیاید، از مقدار فعلی DB/fallback استفاده می‌شود.
     * secret اگر خالی باشد، ساخته و در DB ذخیره می‌شود.
     */
    public function setWebhook(Request $request)
    {
        $token = trim((string) $request->input('token', TelegramConfig::token() ?? ''));
        if ($token === '') {
            return response()->json(['ok' => false, 'message' => 'توکن ربات را وارد کنید'], 422);
        }

        $secret = trim((string) (TelegramConfig::webhookSecret() ?? ''));
        if ($secret === '') {
            $secret = Str::random(48);
        }

        // ذخیرهٔ امن در DB (encrypted casts در مدل PanelBotSetting)
        $s = PanelBotSetting::getSingleton();
        $s->bot_token      = $token;
        $s->webhook_secret = $secret;
        $s->enabled        = true;
        $s->save();

        $url = TelegramConfig::webhookUrl($request->getSchemeAndHttpHost());

        try {
            $api = "https://api.telegram.org/bot{$token}";
            Http::asForm()->post("{$api}/setWebhook", [
                'url'                  => $url,
                'secret_token'         => $secret, // تلگرام این مقدار را در هدر X-Telegram-Bot-Api-Secret-Token می‌فرستد
                'allowed_updates'      => json_encode(['message','callback_query'], JSON_UNESCAPED_UNICODE),
                'drop_pending_updates' => true,
            ])->throw();
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'خطا در تنظیم وبهوک: ' . $e->getMessage()], 422);
        }

        return response()->json([
            'ok'      => true,
            'message' => 'وبهوک با موفقیت تنظیم شد',
            'webhook' => $url,
        ]);
    }

    /**
     * POST /api/admin/bot/remove-webhook
     */
    public function removeWebhook()
    {
        $token = (string) (TelegramConfig::token() ?? '');

        if (trim($token) !== '') {
            try {
                $api = "https://api.telegram.org/bot{$token}";
                Http::asForm()->post("{$api}/deleteWebhook", [
                    'drop_pending_updates' => true,
                ])->throw();
            } catch (\Throwable $e) {
                // اگر حذف از تلگرام خطا داد، ادامه می‌دهیم تا مقادیر DB پاک شوند
            }
        }

        // پاکسازی امن از DB
        $s = PanelBotSetting::getSingleton();
        $s->bot_token      = null;
        $s->webhook_secret = null;
        $s->enabled        = false;
        $s->save();

        return response()->json(['ok' => true, 'message' => 'وبهوک حذف شد']);
    }
}
