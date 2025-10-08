<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TutorialController extends Controller
{

    // === Pending & Ack (toasts) — v2 logic ===
    public function pendingToasts(Request $request)
    {
        $u = $request->user();
        if (!$u) return response()->json([]);

        // Published first
        $pub = DB::table('panel_tutorials as t')
            ->leftJoin('panel_tutorial_toasts as a', function($j) use ($u) {
                $j->on('a.tutorial_id','=','t.id')
                  ->where('a.user_id','=',$u->id)
                  ->where('a.kind','=','published');
            })
            ->whereNotNull('t.published_at')
            ->where('t.published_at','<=',now())
            ->whereNull('a.id')
            ->orderByDesc('t.published_at')
            ->orderByDesc('t.id')
            ->select('t.id','t.app_name','t.published_at')
            ->first();

        if ($pub) {
            return response()->json([
                [
                    'id'       => (int)$pub->id,
                    'app_name' => (string)($pub->app_name ?? ''),
                    'kind'     => 'published',
                    'at'       => optional(\Illuminate\Support\Carbon::parse($pub->published_at))->toIso8601String(),
                ]
            ]);
        }

        // Updated next
        $upd = DB::table('panel_tutorials as t')
            ->leftJoin('panel_tutorial_toasts as a', function($j) use ($u) {
                $j->on('a.tutorial_id','=','t.id')
                  ->where('a.user_id','=',$u->id)
                  ->where('a.kind','=','updated');
            })
            ->whereNotNull('t.published_at')
            ->where('t.published_at','<=', now())
            ->whereNotNull('t.last_important_updated_at')
            ->where(function($q){
                $q->whereColumn('t.last_important_updated_at','>','t.published_at')
                  ->orWhereNull('t.published_at');
            })
            ->where(function($q){
                $q->whereNull('a.id')
                  ->orWhereColumn('t.last_important_updated_at','>','a.acked_at');
            })
            ->orderByDesc('t.last_important_updated_at')
            ->orderByDesc('t.id')
            ->select('t.id','t.app_name','t.last_important_updated_at')
            ->first();

        if ($upd) {
            return response()->json([
                [
                    'id'       => (int)$upd->id,
                    'app_name' => (string)($upd->app_name ?? ''),
                    'kind'     => 'updated',
                    'at'       => optional(\Illuminate\Support\Carbon::parse($upd->last_important_updated_at))->toIso8601String(),
                ]
            ]);
        }

        return response()->json([]);
    }

    public function ackToast(Request $request, int $id)
    {
        $u = $request->user();
        if (!$u) return response()->json(['ok' => false], 401);

        $kind = (string)$request->input('kind', 'published');
        if (!in_array($kind, ['published','updated'], true)) {
            return response()->json(['ok' => false, 'message' => 'invalid_kind'], 422);
        }

        if ($kind === 'published') {
            $exists = DB::table('panel_tutorial_toasts')
                ->where('user_id', $u->id)
                ->where('tutorial_id', $id)
                ->where('kind', 'published')
                ->first();

            if ($exists) {
                if (!$exists->acked_at) {
                    DB::table('panel_tutorial_toasts')->where('id', $exists->id)
                      ->update(['acked_at' => now(), 'updated_at' => now()]);
                }
            } else {
                DB::table('panel_tutorial_toasts')->insert([
                    'user_id'     => $u->id,
                    'tutorial_id' => $id,
                    'kind'        => 'published',
                    'acked_at'    => now(),
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }
            return response()->json(['ok' => true]);
        }

        $row = DB::table('panel_tutorials')->where('id', $id)->first();
        if (!$row) return response()->json(['ok' => false, 'message' => 'not_found'], 404);
        $lastImportant = $row->last_important_updated_at ?? null;
        if (!$lastImportant) return response()->json(['ok' => true]);

        $exists = DB::table('panel_tutorial_toasts')
            ->where('user_id', $u->id)
            ->where('tutorial_id', $id)
            ->where('kind', 'updated')
            ->first();

        if ($exists) {
            if (is_null($exists->acked_at) || $lastImportant > $exists->acked_at) {
                DB::table('panel_tutorial_toasts')->where('id', $exists->id)
                  ->update(['acked_at' => $lastImportant, 'updated_at' => now()]);
            }
        } else {
            DB::table('panel_tutorial_toasts')->insert([
                'user_id'     => $u->id,
                'tutorial_id' => $id,
                'kind'        => 'updated',
                'acked_at'    => $lastImportant,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        return response()->json(['ok' => true]);
    }


    // GET /api/tutorials  (user list)
    public function index(Request $request)
    {
        $cat = $request->query('category');
        $page = max(1, (int)$request->query('page', 1));
        $per  = max(1, min(30, (int)$request->query('per_page', 10)));
        $q = \App\Models\PanelTutorial::query()
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());

        if ($cat && in_array($cat, ['ios','android','system'], true)) {
            $q->where('category', $cat);
        }

        $rows = $q->orderByDesc('published_at')->orderByDesc('id')
            ->skip(($page-1)*$per)->take($per)->get();

        $items = $rows->map(function($t){
            return [
                'id'            => (int)$t->id,
                'category'      => (string)($t->category ?? ''),
                'app_name'      => (string)($t->app_name ?? ''),
                'icon_url'      => $t->icon_url,
                'published_at'  => optional($t->published_at)->toIso8601String(),
            ];
        });

        $hasMore = ($rows->count() === $per) && (\App\Models\PanelTutorial::query()
            ->whereNotNull('published_at')->where('published_at','<=',now())
            ->when($cat && in_array($cat, ['ios','android','system'], true), function($qq) use ($cat){ $qq->where('category',$cat); })
            ->skip($page*$per)->take(1)->exists());

        return response()->json(['items' => $items, 'has_more' => $hasMore, 'page' => $page]);
    }


    // GET /api/tutorials/{id} (user detail)
    public function show(Request $request, int $id)
    {
        $t = \App\Models\PanelTutorial::query()
            ->where('id', $id)
            ->whereNotNull('published_at')->where('published_at', '<=', now())
            ->first();

        if (!$t) return response()->json(['message'=>'not_found'], 404);

        return response()->json([
            'id'           => (int)$t->id,
            'category'     => (string)($t->category ?? ''),
            'app_name'     => (string)($t->app_name ?? ''),
            'icon_url'     => $t->icon_url,
            'download_url' => $t->download_url,
            'video_url'    => $t->video_url,
            'desc'         => $t->desc,
            'published_at' => optional($t->published_at)->toIso8601String(),
        ]);
    }

}
