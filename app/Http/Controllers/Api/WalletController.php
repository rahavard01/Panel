<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use App\Models\PanelCardNumber;
use App\Models\PanelWalletReceipt;
use App\Services\TelegramNotifier;
use App\Services\TelegramConfig;

class WalletController extends Controller
{
    public function cardInfo(Request $request)
    {
        // آخرین کارت ثبت‌شده
        $row = PanelCardNumber::orderByDesc('id')->first();

        if (!$row) {
            return response()->json(['ok' => false, 'message' => 'no_card'], 404);
        }

        $digits = preg_replace('/\D/', '', (string)($row->card ?? ''));
        $digits = substr($digits, 0, 16);
        if ($digits === '') {
            return response()->json(['ok' => false, 'message' => 'empty_card'], 404);
        }

        $formatted = implode('-', str_split($digits, 4));

        return response()->json([
            'ok'             => true,
            'card'           => $digits,
            'card_formatted' => $formatted,
            'name'           => (string)($row->name ?? ''),
        ]);
    }

    public function uploadReceipt(Request $request)
    {
        $request->validate([
            'receipt' => 'required|file|mimes:jpeg,jpg,png,webp,gif,pdf|max:10240',
        ]);

        $file = $request->file('receipt');

        // 1) اول ردیف رسید را بسازیم تا id بگیریم
        $wr = PanelWalletReceipt::create([
            'user_id'       => optional($request->user())->id,
            'method'        => 'card',
            'disk'          => 'public',
            'path'          => '', // بعد از ذخیرهٔ فایل ست می‌کنیم
            'original_name' => $file->getClientOriginalName(),
            'mime'          => $file->getClientMimeType(),
            'size'          => $file->getSize(),
            'status'        => 'uploaded',
            'meta'          => ['ip' => $request->ip()],
        ]);

        // 2) نام فایل = شماره رسید + پسوند
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        if ($ext === 'jpeg') $ext = 'jpg';
        $filename = $wr->id . '.' . $ext;              // مثلا: 12345.jpg

        // 3) ذخیره با نام دلخواه در دیسک public
        $path = $file->storeAs('receipts', $filename, 'public');  // receipts/12345.jpg

        if (!$path) {
            // اگر ذخیره فایل شکست خورد، ردیف DB را پاک می‌کنیم تا orphan نماند
            $wr->delete();
            return response()->json(['ok' => false, 'message' => 'store_failed'], 500);
        }

        // 4) به‌روزرسانی مسیر فایل و خروجی
        $wr->path = $path;
        $wr->save();

        return response()->json([
            'ok'  => true,
            'id'  => $wr->id,
            'url' => Storage::disk('public')->url($path),
        ], 201);
    }

    public function submitCardDeposit(Request $request)
    {
        try {
            $validated = $request->validate([
                'receipt_id' => 'required|integer|exists:panel_wallet_receipts,id',
                'amount'     => 'required|integer|min:1000',
            ]);

            // همه چیز اتمیک انجام شود
            $result = DB::transaction(function () use ($request, $validated) {
                /** @var \App\Models\PanelWalletReceipt $wr */
                $wr = PanelWalletReceipt::where('id', $validated['receipt_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                if (!in_array($wr->status, ['uploaded', 'submitted'], true)) {
                    return ['ok' => false, 'code' => 'INVALID_STATUS'];
                }

                $wr->user_id = optional($request->user())->id;
                $wr->amount  = (int) $validated['amount'];
                $wr->status  = 'submitted';
                $wr->save();

                // ساخت لاگ تراکنش "pending" برای صورتحساب
                $panelUserId = (int) ($wr->user_id ?? 0);
                $before = (int) DB::table('panel_users')
                    ->where('id', $panelUserId)
                    ->value('credit');

                app(\App\Services\TransactionLogger::class)->start([
                    'panel_user_id'   => $panelUserId,
                    'type'            => 'wallet_topup_card',
                    'direction'       => 'credit',
                    'amount'          => (int) $wr->amount,
                    'balance_before'  => (string) $before,
                    'balance_after'   => (string) $before, // خالی نماند
                    'reference_type'  => 'panel_wallet_receipt',
                    'reference_id'    => (int) $wr->id,
                    'idempotency_key' => "pending_receipt:{$wr->id}",
                    'meta'            => ['method' => 'card'],
                ]);

                return ['ok' => true, 'wr' => $wr];
            });

            if (!$result['ok']) {
                return response()->json(['ok' => false, 'message' => 'invalid_status'], 422);
            }

            // نوتیف تلگرام بعد از کمیت (بیرون از تراکنش)
            try {
                if (TelegramConfig::enabled() && TelegramConfig::notifyOnCardSubmit()) {
                    /** @var \App\Models\PanelWalletReceipt $wr */
                    $wr       = $result['wr'];
                    $user     = $request->user();
                    $email    = (string)($user->email ?? '');
                    $codeRaw  = (string)($user->code  ?? '');
                    $userCode = $codeRaw !== ''
                        ? preg_replace(['/@/', '/\.com$/i'], ['', ''], $codeRaw)
                        : ($email ?? '');

                    $photoUrl  = null;
                    $localPath = null;
                    if ($wr->disk && $wr->path) {
                        $photoUrl  = Storage::disk($wr->disk)->url($wr->path);   // اگر public URL داشته باشد
                        $localPath = Storage::disk($wr->disk)->path($wr->path); // برای ارسال باینری لوکال
                    }

                    app(TelegramNotifier::class)->sendReceiptToAdmins([
                        'id'         => $wr->id,
                        'user_email' => $email,
                        'user_code'  => $userCode,
                        'amount'     => (int) $wr->amount,
                        'created_at' => optional($wr->created_at)->format('Y-m-d H:i'),
                        'photo_url'  => $photoUrl,
                        'local_path' => $localPath,
                        'mime'       => (string)($wr->mime ?? ''),
                    ]);
                }
            } catch (\Throwable $te) {

            }

            return response()->json(['ok' => true, 'message' => 'submitted']);
        } catch (\Throwable $e) {

            if (app()->isLocal()) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
            }
            return response()->json(['ok' => false, 'message' => 'server_error'], 500);
        }
    }

    // GET /api/wallet/receipt/notifications/pending
    public function pendingReceiptToasts(Request $request)
    {
        $u = $request->user();
        if (!$u) return response()->json([]);

        $r = PanelWalletReceipt::where('user_id', $u->id)
            ->where('status', 'verified')
            ->whereNull('notified_at')
            ->latest('id')
            ->first();

        if (!$r) return response()->json([]);

        return response()->json([[
            'id'     => $r->id,
            'amount' => (int)($r->amount ?? 0),
            'at'     => optional($r->updated_at)->toIso8601String(),
        ]]);
    }

    // POST /api/wallet/receipt/notifications/{id}/ack
    public function ackReceiptToast(Request $request, int $id)
    {
        $u = $request->user();
        if (!$u) return response()->json(['ok' => false], 401);

        $r = PanelWalletReceipt::where('id', $id)
            ->where('user_id', $u->id)
            ->firstOrFail();

        if (!$r->notified_at) {
            $r->notified_at = now();
            $r->save();
        }
        return response()->json(['ok' => true]);
    }

    // GET /api/wallet/commission/notifications/pending
    public function pendingCommissionToasts(Request $request)
    {
        $u = $request->user();
        if (!$u) return response()->json([]);

        // آخرین رسیدی که کمیسیونش پرداخت شده اما برای معرّف هنوز نوتیف پنلی ACK نشده
        $row = DB::table('panel_wallet_receipts as wr')
            ->join('panel_users as payer', 'payer.id', '=', 'wr.user_id')
            ->leftJoin('panel_transactions as t', 't.id', '=', 'wr.commission_tx_id')
            ->where('wr.status', 'verified')
            ->where('wr.commission_paid', true)
            ->whereNotNull('wr.commission_tx_id')
            ->whereNull('wr.commission_notified_at')
            ->where('payer.referred_by_id', $u->id)   // این رسید مربوط به کاربری است که معرّفش، همین کاربر لاگین‌شده است
            ->select([
                'wr.id',
                'wr.updated_at',
                DB::raw('COALESCE(t.amount, 0) as commission_amount'),
                DB::raw('COALESCE(t.balance_after, 0) as referrer_balance_after'),
                DB::raw('COALESCE(payer.name, payer.email) as payer_name'),
                'payer.code as payer_code',
            ])
            ->orderByDesc('wr.id')
            ->first();

        if (!$row) return response()->json([]);

        return response()->json([[
            'id'            => (int)$row->id,                               // receipt id
            'amount'        => (int)$row->commission_amount,                // مبلغ کمیسیون
            'balance_after' => (int)$row->referrer_balance_after,           // موجودی فعلی معرّف بعد از واریز
            'payer_name'    => (string)($row->payer_name ?? ''),
            'payer_code'    => (string)($row->payer_code ?? ''),
            'at'            => optional($row->updated_at)->toIso8601String(),
        ]]);
    }

    // POST /api/wallet/commission/notifications/{id}/ack
    public function ackCommissionToast(Request $request, int $id)
    {
        $u = $request->user();
        if (!$u) return response()->json(['ok' => false], 401);

        // فقط اگر این رسید واقعاً متعلق به کاربری‌ست که معرّفش همین کاربر است
        $wr = DB::table('panel_wallet_receipts as wr')
            ->join('panel_users as payer', 'payer.id', '=', 'wr.user_id')
            ->where('wr.id', $id)
            ->where('wr.status', 'verified')
            ->where('wr.commission_paid', true)
            ->whereNotNull('wr.commission_tx_id')
            ->where('payer.referred_by_id', $u->id)
            ->select('wr.id', 'wr.commission_notified_at')
            ->first();

        if (!$wr) return response()->json(['ok' => false], 404);

        // اولین بار تیک بخورد
        DB::table('panel_wallet_receipts')
            ->where('id', $id)
            ->whereNull('commission_notified_at')
            ->update(['commission_notified_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function pendingAdjustToasts(Request $request)
    {
        $u = $request->user();
        if (!$u) return response()->json([]);

        $rows = \App\Models\PanelWalletReceipt::query()
            ->where('user_id', $u->id)
            ->where('status', 'verified')
            ->where('method', 'adjust') // فقط اصلاحیه‌ها
            ->whereNull('notified_at')
            ->orderByDesc('id')
            ->limit(3)
            ->get(['id','amount','updated_at']);

        $out = $rows->map(function($r){
            return [
                'id'     => (int)$r->id,
                'amount' => (int)$r->amount,
                'at'     => optional($r->updated_at)->toIso8601String(),
            ];
        });

        return response()->json($out);
    }

    public function ackAdjustToast(Request $request, int $id)
    {
        $u = $request->user();
        if (!$u) return response()->json(['ok'=>false], 403);

        $r = \App\Models\PanelWalletReceipt::where('id', $id)
            ->where('user_id', $u->id)
            ->where('method', 'adjust')
            ->first();

        if (!$r) return response()->json(['ok'=>false], 404);

        if (!$r->notified_at) {
            $r->notified_at = now();
            $r->save();
        }
        return response()->json(['ok'=>true]);
    }

}
