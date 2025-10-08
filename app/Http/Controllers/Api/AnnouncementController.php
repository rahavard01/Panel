<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\PanelAnnouncement;
use App\Models\PanelAnnouncementRead;

class AnnouncementController extends Controller
{
    // GET /api/announcements
    public function index(Request $request)
    {
        $per = max(1, min(50, (int)$request->get('per_page', 10)));
        $u   = $request->user(); // کاربر لاگین‌شده

        // اعلان‌های منتشرشده + جوین به وضعیت خواندن همان کاربر
        $items = \App\Models\PanelAnnouncement::publishedNow()
            ->from('panel_announcements as a')
            ->leftJoin('panel_announcement_reads as r', function ($j) use ($u) {
                $j->on('r.announcement_id', '=', 'a.id')
                ->where('r.user_id', '=', $u->id);
            })
            ->orderByDesc('a.publish_at')
            ->orderByDesc('a.id')
            ->paginate(
                $per,
                [
                    'a.*',
                    'r.read_at',
                    'r.ack_at',
                    \DB::raw('CASE WHEN r.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read'),
                ]
            );

        return response()->json($items);
    }

    // GET /api/announcements/notifications/pending
    public function pending(Request $request)
    {
        $u = $request->user();

        $items = PanelAnnouncement::publishedNow()
            ->whereDoesntHave('reads', function($q) use ($u) {
                $q->where('user_id', $u->id)->whereNotNull('ack_at');
            })
            ->orderByDesc('publish_at')
            ->orderByDesc('id')
            ->limit(3)
            ->get(['id','title','publish_at']);

        return response()->json($items);
    }

    // POST /api/announcements/notifications/{id}/ack
    public function ack(Request $request, int $id)
    {
        $u = $request->user();
        $a = PanelAnnouncement::publishedNow()->findOrFail($id);

        PanelAnnouncementRead::updateOrCreate(
            ['announcement_id' => $a->id, 'user_id' => $u->id],
            ['ack_at' => now()]
        );

        return response()->json(['ok' => true]);
    }

    // POST /api/announcements/{id}/read
    public function read(Request $request, int $id)
    {
        $u = $request->user();
        $a = PanelAnnouncement::publishedNow()->findOrFail($id);

        PanelAnnouncementRead::updateOrCreate(
            ['announcement_id' => $a->id, 'user_id' => $u->id],
            ['read_at' => now()]
        );

        // [ANN-TG] حذف فوری دکمه در تلگرام اگر پیام اولیه با دکمه قبلاً ارسال و ثبت شده باشد
        try {
            $r = \App\Models\PanelAnnouncementRead::where('announcement_id', $a->id)
                ->where('user_id', $u->id)
                ->first();

            if ($r && $r->tg_chat_id && $r->tg_message_id) {
                $token = \App\Services\TelegramConfig::token();
                if ($token) {
                    $api = "https://api.telegram.org/bot{$token}";
                    \Illuminate\Support\Facades\Http::post("{$api}/editMessageReplyMarkup", [
                        'chat_id'      => (string)$r->tg_chat_id,
                        'message_id'   => (int)$r->tg_message_id,
                        'reply_markup' => json_encode(['inline_keyboard' => []], JSON_UNESCAPED_UNICODE),
                    ]);
                }
            }
        } catch (\Throwable $e) {}

        return response()->json(['ok' => true]);
    }

    public function unreadCount(Request $request)
    {
        $u = $request->user();

        $count = \App\Models\PanelAnnouncement::publishedNow()
            ->from('panel_announcements as a')
            ->leftJoin('panel_announcement_reads as r', function ($j) use ($u) {
                $j->on('r.announcement_id', '=', 'a.id')
                ->where('r.user_id', '=', $u->id);
            })
            // نخوانده یعنی read_at تهی باشد (چه رکورد باشد چه نباشد)
            ->whereNull('r.read_at')
            ->count();

        return response()->json(['count' => (int)$count]);
    }

}
