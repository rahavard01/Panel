<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\PanelAnnouncement;
use Carbon\Carbon;

class AnnouncementAdminController extends Controller
{
    // GET /api/admin/announcements
    public function index(Request $request)
    {
        $status = $request->get('status'); // draft|published|scheduled|expired|null
        $q = PanelAnnouncement::query();

        if ($status === 'draft') {
            $q->where('is_published', false);
        } elseif ($status === 'published') {
            $q->where('is_published', true)
              ->where(function($qq){ $qq->whereNull('publish_at')->orWhere('publish_at','<=',now()); })
              ->where(function($qq){ $qq->whereNull('expires_at')->orWhere('expires_at','>', now()); });
        } elseif ($status === 'scheduled') {
            $q->where('is_published', true)->where('publish_at','>', now());
        } elseif ($status === 'expired') {
            $q->where('is_published', true)->whereNotNull('expires_at')->where('expires_at','<=', now());
        }

        $q->orderByDesc('id');
        $q->withCount([
        'reads as reads_count' => function($q){ $q->whereNotNull('read_at'); }
        ]);
        return response()->json($q->paginate(15));
    }

    // POST /api/admin/announcements
    public function store(Request $request)
    {
        $data = $request->validate([
            'title'       => 'required|string|max:190',
            'body'        => 'nullable|string',
            'audience'    => 'nullable|string|max:32',
            'is_published'=> 'boolean',
            'publish_at'  => 'required|date',
            'expires_at'  => 'nullable|date|after:publish_at',
        ]);

        $data['created_by'] = optional($request->user())->id;

        $a = PanelAnnouncement::create($data);

        // [ANN-TG] اگر اعلان درجا منتشر شده بود، برای کاربران تلگرام بفرست و tg_broadcasted_at را ست کن
        try {
            $isPublishedNow = ($a->is_published === true)
                && (is_null($a->publish_at) || Carbon::parse($a->publish_at)->isPast())
                && (is_null($a->expires_at) || Carbon::parse($a->expires_at)->isFuture())
                && is_null($a->tg_broadcasted_at);

            if ($isPublishedNow) {
                app(\App\Services\TelegramNotifier::class)->broadcastAnnouncement($a->id);
                $a->tg_broadcasted_at = now();
                $a->save();
            }
        } catch (\Throwable $e) {
            // لاگ‌گذاری اختیاری
        }

        return response()->json(['ok'=>true, 'data'=>$a], 201);
    }

    // PATCH /api/admin/announcements/{id}
    public function update(Request $request, int $id)
    {
        $a = PanelAnnouncement::findOrFail($id);

        $data = $request->validate([
            'title'       => 'sometimes|required|string|max:190',
            'body'        => 'nullable|string',
            'audience'    => 'nullable|string|max:32',
            'is_published'=> 'boolean',
            'publish_at'  => 'required|date',
            'expires_at'  => 'nullable|date|after:publish_at',
        ]);

        // === قبل از save(): وضعیت قبلی را بگیر (آیا قبلاً منتشر و موعد گذشته بود؟)
        $wasPublishedBefore = ((bool) $a->getOriginal('is_published'))
            && (is_null($a->getOriginal('publish_at')) || Carbon::parse($a->getOriginal('publish_at'))->isPast())
            && (is_null($a->getOriginal('expires_at')) || Carbon::parse($a->getOriginal('expires_at'))->isFuture());

        // ذخیره تغییرات
        $a->fill($data)->save();

        // === بعد از save(): اگر الآن منتشر و موعد گذشته و هنوز ارسال‌نشده است و قبلاً این‌طور نبود → ارسال کن
        try {
            $isPublishedNow = ((bool) $a->is_published)
                && (is_null($a->publish_at) || Carbon::parse($a->publish_at)->isPast())
                && (is_null($a->expires_at) || Carbon::parse($a->expires_at)->isFuture())
                && is_null($a->tg_broadcasted_at);

            if (!$wasPublishedBefore && $isPublishedNow) {
                app(\App\Services\TelegramNotifier::class)->broadcastAnnouncement($a->id);
                $a->tg_broadcasted_at = now();
                $a->save();
            }
        } catch (\Throwable $e) {
            // لاگ‌گذاری اختیاری
        }

        return response()->json(['ok'=>true, 'data'=>$a]);
    }

    // POST /api/admin/announcements/{id}/toggle
    public function toggle(Request $request, int $id)
    {
        $a = PanelAnnouncement::findOrFail($id);
        $a->is_published = ! $a->is_published;
        $a->save();

        return response()->json(['ok'=>true, 'data'=>$a]);
    }

    // DELETE /api/admin/announcements/{id}
    public function destroy(Request $request, int $id)
    {
        $a = PanelAnnouncement::findOrFail($id);
        $a->delete();

        return response()->json(['ok'=>true]);
    }
}
