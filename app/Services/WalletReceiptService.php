<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class WalletReceiptService
{
    /**
     * نتیجه یکنواخت خروجی:
     * ['ok'=>bool, 'code'=>'OK|ALREADY_VERIFIED|ALREADY_REJECTED|INVALID_STATE', 'status'=>'verified|rejected|pending', 'receipt_id'=>int]
     */

    public function approve(int $receiptId, int $adminId, int $amount): array
    {
        return DB::transaction(function () use ($receiptId, $adminId, $amount) {
            /** @var \App\Models\PanelWalletReceipt|null $wr */
            $wr = \App\Models\PanelWalletReceipt::lockForUpdate()->find($receiptId);
            if (!$wr) {
                return ['ok'=>false, 'code'=>'NOT_FOUND', 'status'=>null, 'receipt_id'=>$receiptId];
            }

            $status = (string)($wr->status ?? '');
            if ($status === 'verified') {
                return ['ok'=>false, 'code'=>'ALREADY_VERIFIED', 'status'=>'verified', 'receipt_id'=>$wr->id];
            }
            if ($status === 'rejected') {
                return ['ok'=>false, 'code'=>'ALREADY_REJECTED', 'status'=>'rejected', 'receipt_id'=>$wr->id];
            }
            if (!in_array($status, ['uploaded','submitted','pending'], true)) {
                return ['ok'=>false, 'code'=>'INVALID_STATE', 'status'=>$status, 'receipt_id'=>$wr->id];
            }

            $panelUserId = (int) $wr->user_id;
            $amount      = (int) $amount;
            $wr->amount  = $amount; // مبلغ نهایی روی خود رسید ذخیره شود

            // 1) همان سطر تراکنش PENDING مربوط به این رسید را پیدا کن
            $pendingTx = DB::table('panel_transactions')
                ->where('reference_type', 'panel_wallet_receipt')
                ->where('reference_id', $wr->id)
                ->where('panel_user_id', $panelUserId)
                ->where('type', 'wallet_topup_card')
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if ($pendingTx) {
                // 2) موجودی را بروزرسانی کن و همان سطر را success کن (بدون ساخت سطر جدید)
                $before = (int) DB::table('panel_users')
                    ->where('id', $panelUserId)
                    ->lockForUpdate()
                    ->value('credit');
                $after  = $before + $amount;

                // آپدیت موجودی
                DB::table('panel_users')->where('id', $panelUserId)->update([
                    'credit'     => $after,
                    'updated_at' => now(),
                ]);

                // نقش ادمین
                $actorRole = DB::table('panel_users')->where('id', $adminId)->value('role');

                // آپدیت همان ردیف تراکنش
                DB::table('panel_transactions')->where('id', $pendingTx->id)->update([
                    'status'            => 'success',
                    
                    'balance_before'    => (string) $before,
                    'balance_after'     => (string) $after,
                    'performed_by_id'   => $adminId,
                    'performed_by_role' => $actorRole,
                    'updated_at'        => now(),
                ]);

                $txId = (int) $pendingTx->id;

                // === ارسال پیام تلگرام به صاحب کیف‌پول در شاخه pendingTx ===
                $chatId = DB::table('panel_users')->where('id', $panelUserId)->value('telegram_user_id');

                if (!empty($chatId)) {
                    $fmtAmount = number_format((int)$amount);
                    $fmtAfter  = number_format((int)$after);
                    $msg  = "✅ کیف پول شما با مبلغ {$fmtAmount} تومان شارژ شد.\n";
                    $msg .= "💰 موجودی فعلی: {$fmtAfter} تومان\n";

                    if (DB::transactionLevel() > 0) {
                        DB::afterCommit(function () use ($chatId, $msg) {
                            try {
                                app(\App\Services\TelegramNotifier::class)->sendTextTo((string)$chatId, $msg);
                            } catch (\Throwable $e) { /* silent */ }
                        });
                    } else {
                        try {
                            app(\App\Services\TelegramNotifier::class)->sendTextTo((string)$chatId, $msg);
                        } catch (\Throwable $e) { /* silent */ }
                    }
                }

            } else {
                // اگر تراکنش PENDING قدیمی نبود (رسیدهای قبل از این تغییرات): مسیر قبلی
                $txId = app(\App\Services\TransactionLogger::class)
                    ->walletTopupCardVerified($panelUserId, $amount, $wr->id, $adminId);
            }

            // 3) محاسبه و واریز کمیسیون معرّف (همان منطق قبلی)
            $payer = DB::table('panel_users')
                ->select('id','email','name','code','referred_by_id','ref_commission_rate')
                ->where('id', $panelUserId)
                ->first();

            if ($payer) {
                $referrerId = (int)($payer->referred_by_id ?? 0);
                $rate       = (float)($payer->ref_commission_rate ?? 0);
                if ($referrerId > 0 && $referrerId !== (int)$payer->id && $rate > 0 && !($wr->commission_paid ?? false)) {
                    $commission = (int) floor(((int)$amount) * $rate / 100);
                    if ($commission > 0) {
                        $meta = [
                            'source_user_id'    => (int)$payer->id,
                            'source_user_email' => (string)($payer->email ?? ''),
                            'source_user_code'  => (string)($payer->code  ?? ''),
                            'source_user_name'  => (string)($payer->name  ?? ''),
                            'source_amount'     => (int)$amount,
                            'commission_rate'   => (float)$rate,
                            'source_receipt_id' => (int)$wr->id,
                            'user_ids'          => [(int)$payer->id],
                        ];
                        $commissionTxId = app(\App\Services\TransactionLogger::class)
                            ->walletReferrerCommissionAwarded($referrerId, $commission, $meta, $adminId);

                        $wr->commission_paid  = true;
                        $wr->commission_tx_id = $commissionTxId;
                    }
                }
            }

            // 4) رسید → verified و ریست توست‌ها
            $wr->status      = 'verified';
            $wr->notified_at = null;
            $wr->save();

            return ['ok'=>true, 'code'=>'OK', 'status'=>'verified', 'receipt_id'=>$wr->id];
        });
    }

    public function reject(int $receiptId, int $adminId, ?string $reason = null): array
    {
        return DB::transaction(function () use ($receiptId, $adminId, $reason) {
            /** @var \App\Models\PanelWalletReceipt|null $wr */
            $wr = \App\Models\PanelWalletReceipt::lockForUpdate()->find($receiptId);
            if (!$wr) {
                return ['ok'=>false, 'code'=>'NOT_FOUND', 'status'=>null, 'receipt_id'=>$receiptId];
            }

            $status = (string)($wr->status ?? '');
            if ($status === 'rejected') {
                return ['ok'=>false, 'code'=>'ALREADY_REJECTED', 'status'=>'rejected', 'receipt_id'=>$wr->id];
            }
            if ($status === 'verified') {
                return ['ok'=>false, 'code'=>'ALREADY_VERIFIED', 'status'=>'verified', 'receipt_id'=>$wr->id];
            }
            if (!in_array($status, ['uploaded','submitted','pending'], true)) {
                return ['ok'=>false, 'code'=>'INVALID_STATE', 'status'=>$status, 'receipt_id'=>$wr->id];
            }

            // ضمیمه دلیل در meta روی خود رسید
            $meta = [];
            if ($wr->meta) { try { $meta = json_decode($wr->meta, true) ?: []; } catch (\Throwable $e) {} }
            if ($reason) { $meta['reject_reason'] = $reason; }
            $wr->meta = json_encode($meta, JSON_UNESCAPED_UNICODE);

            // === اینجــا اضافه شده: همان سطر تراکنش PENDING را fail کن ===
            $pendingTx = DB::table('panel_transactions')
                ->where('reference_type', 'panel_wallet_receipt')
                ->where('reference_id', $wr->id)
                ->where('panel_user_id', (int)$wr->user_id)
                ->where('type', 'wallet_topup_card')
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if ($pendingTx) {
                $actorRole = DB::table('panel_users')->where('id', $adminId)->value('role');

                $txMeta = [];
                if (!empty($pendingTx->meta)) {
                    try { $txMeta = json_decode($pendingTx->meta, true) ?: []; } catch (\Throwable $e) {}
                }
                if ($reason) { $txMeta['reject_reason'] = $reason; }

                DB::table('panel_transactions')->where('id', $pendingTx->id)->update([
                    'status'            => 'failed',
                    'performed_by_id'   => $adminId,
                    'performed_by_role' => $actorRole,
                    'meta'              => !empty($txMeta) ? json_encode($txMeta, JSON_UNESCAPED_UNICODE) : $pendingTx->meta,
                    'updated_at'        => now(),
                ]);
            }
            // === تا اینجا بلاکِ جدید بود ===

            // در نهایت، رسید را رد کن و توست‌ها را ریست کن
            $wr->status      = 'rejected';
            $wr->notified_at = null; // اجازه نمایش توست رد به کاربر
            $wr->save();

            return ['ok'=>true, 'code'=>'OK', 'status'=>'rejected', 'receipt_id'=>$wr->id];
        });
    }

}
