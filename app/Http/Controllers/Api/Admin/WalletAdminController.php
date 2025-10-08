<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\PanelWalletReceipt;
use App\Services\TransactionLogger;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WalletAdminController extends Controller
{
    // POST /api/admin/wallet/receipts/{id}/verify
    public function verify(\Illuminate\Http\Request $request, int $id)
    {
        $request->validate([
            'amount' => ['required','integer','min:1000'],
        ]);
        $adminId = (int)($request->user()?->id ?? 0);
        $amount  = (int)$request->input('amount');

        $res = app(\App\Services\WalletReceiptService::class)->approve($id, $adminId, $amount);

        if ($res['ok']) {
            // حذف دکمه‌های تلگرام + پیام نتیجه (متن همان سبک قبلی)
            try {
                app(\App\Services\TelegramNotifier::class)->disableReceiptButtons($id);
                app(\App\Services\TelegramNotifier::class)->broadcastReceiptDecisionText($id, 'ok', null);
            } catch (\Throwable $e) {}
            return response()->json(['ok'=>true, 'status'=>'verified', 'message'=>'verified']);
        }

        // سناریو قبلاً رسیدگی شده
        if (in_array($res['code'], ['ALREADY_VERIFIED','ALREADY_REJECTED','INVALID_STATE'], true)) {
            return response()->json([
                'ok'=>false,
                'code'=>'ALREADY_PROCESSED',
                'status'=>$res['status'],
                'message'=>'این واریزی قبلاً رسیدگی شده است.'
            ], 409);
        }

        return response()->json(['ok'=>false, 'message'=>'failed'], 422);
    }

    public function countPending(\Illuminate\Http\Request $request)
    {
        $count = DB::table('panel_wallet_receipts')->where('status','submitted')->count();
        return response()->json(['count' => $count]);
    }

    public function index(\Illuminate\Http\Request $request)
    {
        $status = strtolower((string)$request->query('status','pending'));
        // نگاشت: pending => uploaded
        $statusMap = ['pending' => 'submitted', 'verified' => 'verified', 'rejected' => 'rejected'];
        $statusVal = $statusMap[$status] ?? null;

        $q = DB::table('panel_wallet_receipts as wr')
            ->leftJoin('panel_users as u', 'u.id', '=', 'wr.user_id')
            ->leftJoin('panel_users as ref', 'ref.id', '=', 'u.referred_by_id')
            ->selectRaw("
                wr.id,
                wr.status,
                wr.amount,
                wr.method,
                wr.created_at,
                wr.path, wr.disk,
                wr.meta,
                u.name as user_name, u.email as user_email, u.code as user_code,
                ref.name as ref_name, ref.email as ref_email, ref.code as ref_code
            ")
            ->orderByDesc('wr.id');

        if ($status !== 'all' && $statusVal) {
            $q->where('wr.status', $statusVal);
        }

        $rows = $q->get()->map(function($r){
            $ref = null;
            if ($r->meta) { try { $obj = json_decode($r->meta, true); $ref = $obj['ref'] ?? null; } catch (\Throwable $e) {} }
            return [
                'id'          => (int)$r->id,
                'status'      => $r->status,
                'amount'      => (int)$r->amount,
                'method'      => $r->method,
                'created_at'  => $r->created_at,
                'path'        => $r->path,
                'disk'        => $r->disk,
                'ref'         => $ref,
                'user_name'   => $r->user_name ?: $r->user_email,
                'user_email'  => $r->user_email,
                'user_code'   => $r->user_code,
                'ref_name'    => $r->ref_name,
                'ref_email'   => $r->ref_email,
                'ref_code'    => $r->ref_code,
            ];
        });

        return response()->json(['data' => $rows]);
    }

    public function show(\Illuminate\Http\Request $request, int $id)
    {
        $r = DB::table('panel_wallet_receipts as wr')
            ->leftJoin('panel_users as u', 'u.id', '=', 'wr.user_id')
            ->selectRaw("wr.*, u.name as user_name, u.email as user_email, u.code as user_code")
            ->where('wr.id', $id)->first();

        if (!$r) return response()->json(['ok'=>false, 'message'=>'not found'], 404);

        $ref = null;
        if ($r->meta) { try { $obj = json_decode($r->meta, true); $ref = $obj['ref'] ?? null; } catch (\Throwable $e) {} }

        return response()->json(['data' => [
            'id'          => (int)$r->id,
            'status'      => $r->status,
            'amount'      => (int)$r->amount,
            'method'      => $r->method,
            'created_at'  => $r->created_at,
            'path'        => $r->path,
            'disk'        => $r->disk,
            'ref'         => $ref,
            'user_name'   => $r->user_name ?: $r->user_email,
            'user_email'  => $r->user_email,
            'user_code'   => $r->user_code,
        ]]);
    }

    public function showFile(\Illuminate\Http\Request $request, int $id): StreamedResponse
    {
        // یافتن رسید
        $wr = \App\Models\PanelWalletReceipt::findOrFail($id);

        // دیسک و مسیر فایل
        $disk = $wr->disk ?: 'public';
        $path = ltrim((string)$wr->path, '/');

        // وجود فایل
        abort_unless(Storage::disk($disk)->exists($path), 404, 'File not found');

        // MIME و نام فایل
        $mime = Storage::disk($disk)->mimeType($path) ?: 'application/octet-stream';
        $name = basename($path);

        // استریم به صورت inline (باز شدن داخل مرورگر، نه دانلود اجباری)
        $stream = Storage::disk($disk)->readStream($path);

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
            if (is_resource($stream)) { fclose($stream); }
        }, 200, [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'inline; filename="'.$name.'"',
            'Cache-Control'       => 'private, max-age=0, no-store, no-cache, must-revalidate',
            'Pragma'              => 'no-cache',
        ]);
    }

    public function reject(\Illuminate\Http\Request $request, int $id)
    {
        $reason  = $request->input('reason') ?: null;
        $adminId = (int)($request->user()?->id ?? 0);

        $res = app(\App\Services\WalletReceiptService::class)->reject($id, $adminId, $reason);

        if ($res['ok']) {
            try {
                app(\App\Services\TelegramNotifier::class)->disableReceiptButtons($id);
                app(\App\Services\TelegramNotifier::class)->broadcastReceiptDecisionText($id, 'reject', $reason);
            } catch (\Throwable $e) {}
            return response()->json(['ok'=>true, 'status'=>'rejected', 'message'=>'rejected']);
        }

        if (in_array($res['code'], ['ALREADY_VERIFIED','ALREADY_REJECTED','INVALID_STATE'], true)) {
            return response()->json([
                'ok'=>false,
                'code'=>'ALREADY_PROCESSED',
                'status'=>$res['status'],
                'message'=>'این واریزی قبلاً رسیدگی شده است.'
            ], 409);
        }

        return response()->json(['ok'=>false, 'message'=>'failed'], 422);
    }

}
