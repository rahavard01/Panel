<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\PanelTutorial;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TutorialAdminController extends Controller
{
    // GET /api/admin/tutorials
    public function index(Request $request)
    {
        $q = PanelTutorial::query();

        if ($cat = $request->get('category')) {
            $q->where('category', $cat);
        }

        $perPage = (int) ($request->integer('per_page') ?: 10);

        // جدیدها بالا: بر اساس زمان انتشار و id
        return response()->json(
            $q->orderByDesc('published_at')->orderByDesc('id')->paginate($perPage)
        );
    }

    // POST /api/admin/tutorials
    public function store(Request $request)
    {
        $data = $request->validate([
            'category'     => 'required|in:ios,android,system',
            'app_name'     => 'required|string|max:190',
            'download_url' => 'nullable|url',
            'desc'         => 'nullable|string',
            'icon'         => 'nullable|image|mimes:png,jpg,jpeg,webp,svg|max:1024',         // ≤1MB
            'video'        => 'nullable|mimetypes:video/mp4,video/webm,video/ogg,video/quicktime,video/x-matroska|max:512000', // ≤500MB
        ]);

        $iconPath = null;
        $videoPath = null;

        if ($request->hasFile('icon')) {
            $iconPath = $request->file('icon')->storeAs(
                'tutorials/icons',
                Str::uuid()->toString() . '.' . $request->file('icon')->getClientOriginalExtension(),
                'public'
            );
        }

        if ($request->hasFile('video')) {
            $videoPath = $request->file('video')->storeAs(
                'tutorials/videos',
                Str::uuid()->toString() . '.' . $request->file('video')->getClientOriginalExtension(),
                'public'
            );
        }

        $t = PanelTutorial::create([
            'category'     => $data['category'],
            'app_name'     => $data['app_name'],
            'download_url' => $data['download_url'] ?? null,
            'desc'         => $data['desc'] ?? null,
            'icon_path'    => $iconPath,
            'video_path'   => $videoPath,
            'published_at' => now(), // انتشار فوری
            'created_by'   => optional($request->user())->id,
        ]);

        return response()->json(['ok'=>true, 'data'=>$t]);
    }

    // PATCH /api/admin/tutorials/{id}
    public function update(Request $request, int $id)
    {
        $t = PanelTutorial::findOrFail($id);

        $data = $request->validate([
            'category'     => 'required|in:ios,android,system',
            'app_name'     => 'required|string|max:190',
            'download_url' => 'nullable|url',
            'desc'         => 'nullable|string',
            'icon'         => 'nullable|image|mimes:png,jpg,jpeg,webp,svg|max:1024',
            'video'        => 'nullable|mimetypes:video/mp4,video/webm,video/ogg,video/quicktime,video/x-matroska|max:512000',
        ]);

        // فایل‌های جدید اگر آمدند، قبلی‌ها را پاک کن
        if ($request->hasFile('icon')) {
            if ($t->icon_path) Storage::disk('public')->delete($t->icon_path);
            $t->icon_path = $request->file('icon')->storeAs(
                'tutorials/icons',
                Str::uuid()->toString() . '.' . $request->file('icon')->getClientOriginalExtension(),
                'public'
            );
        }
        if ($request->hasFile('video')) {
            if ($t->video_path) Storage::disk('public')->delete($t->video_path);
            $t->video_path = $request->file('video')->storeAs(
                'tutorials/videos',
                Str::uuid()->toString() . '.' . $request->file('video')->getClientOriginalExtension(),
                'public'
            );
        }

        $t->category     = $data['category'];
        $t->app_name     = $data['app_name'];
        $t->download_url = $data['download_url'] ?? null;
        $t->desc         = $data['desc'] ?? null;
        $t->save();

        return response()->json(['ok'=>true, 'data'=>$t]);
    }

    // DELETE /api/admin/tutorials/{id}
    public function destroy(Request $request, int $id)
    {
        $t = PanelTutorial::findOrFail($id);

        if ($t->icon_path)  Storage::disk('public')->delete($t->icon_path);
        if ($t->video_path) Storage::disk('public')->delete($t->video_path);

        $t->delete();

        return response()->json(['ok'=>true]);
    }
}
