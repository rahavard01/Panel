<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class TransactionLogger
{

    private function resolveActor(array $data): array
    {
        // اگر از بیرون پاس داده شده باشد، همان را بگیر
        $u    = auth()->user();
        $id   = $data['performed_by_id']   ?? $data['actor_id']   ?? ($u->id   ?? null);
        $role = $data['performed_by_role'] ?? $data['actor_role'] ?? ($u->role ?? null);
        return [$id, $role];
    }
    
    /**
     * مرحله‌ی آغاز: ثبت رکورد PENDING و برگرداندن id
     */
    public function start(array $data): int
    {
        // $data = [
        //   panel_user_id, type, direction, amount, balance_before, balance_after,
        //   currency?, quantity?, plan_key_after?, plan_key_before?, reference_type?, reference_id?, meta?
        // ]

        $payload = [
            'panel_user_id'  => (int)$data['panel_user_id'],
            'type'           => (string)$data['type'],
            'direction'      => (string)$data['direction'],
            'amount'         => (string)$data['amount'],
            'balance_before' => (string)$data['balance_before'],
            'balance_after'  => (string)$data['balance_after'],
            'currency'       => $data['currency'] ?? 'IRT',
            'status'         => 'pending',
            'quantity'       => isset($data['quantity']) ? (int)$data['quantity'] : 1,
            'plan_key_after' => $data['plan_key_after'] ?? null,
            'plan_key_before'=> $data['plan_key_before'] ?? null,
            'reference_type' => $data['reference_type'] ?? null,
            'reference_id'   => isset($data['reference_id']) ? (int)$data['reference_id'] : null,
            'idempotency_key' => $data['idempotency_key'] ?? null,
            'meta'           => isset($data['meta']) && !empty($data['meta'])
                                ? json_encode($data['meta'], JSON_UNESCAPED_UNICODE)
                                : null,
            'created_at'     => now(),
            'updated_at'     => now(),
        ];

        [$actorId, $actorRole] = $this->resolveActor($data);
        $payload['performed_by_id']   = $actorId;  
        $payload['performed_by_role'] = $actorRole; 

        if (!empty($payload['idempotency_key'])) {
            $already = DB::table('panel_transactions')
                ->where('idempotency_key', $payload['idempotency_key'])
                ->value('id');
            if ($already) {
                return (int) $already; // قبلاً ثبت شده
            }
        }

        return (int) DB::table('panel_transactions')->insertGetId($payload);
    }

    /**
     * مرحله‌ی پایان: موفق—آپدیت همان رکورد با reference و user_ids و status=success
     *
     * @param int   $trxId
     * @param array $final  ['reference_type','reference_id','user_ids'=>[], 'extra_meta'=>[]]
     */
    public function finalize(int $trxId, array $final = []): void
    {
        $row = DB::table('panel_transactions')->where('id', $trxId)->first();
        if (!$row) return;

        // meta قبلی را merge می‌کنیم
        $meta = [];
        if (!empty($row->meta)) {
            $decoded = json_decode($row->meta, true);
            if (is_array($decoded)) $meta = $decoded;
        }

        if (!empty($final['user_ids'])) {
            $meta['user_ids'] = array_values(array_unique(array_map('intval', (array)$final['user_ids'])));
        }
        if (!empty($final['extra_meta']) && is_array($final['extra_meta'])) {
            $meta = array_merge($meta, $final['extra_meta']);
        }

        $update = [
            'reference_type' => $final['reference_type'] ?? $row->reference_type,
            'reference_id'   => $final['reference_id']   ?? $row->reference_id,
            'status'         => 'success',
            'meta'           => !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            'updated_at'     => now(),
        ];

        [$actorId, $actorRole]          = $this->resolveActor($final);
        $update['performed_by_id']      = $actorId;
        $update['performed_by_role']    = $actorRole;

        DB::table('panel_transactions')->where('id', $trxId)->update($update);

    }

    /**
     * مرحله‌ی پایان: ناموفق—صرفاً status را failed می‌کنیم و پیام می‌گذاریم
     */
    public function fail(int $trxId, string $reason = null): void
    {
        $row = DB::table('panel_transactions')->where('id', $trxId)->first();
        if (!$row) return;

        $meta = [];
        if (!empty($row->meta)) {
            $decoded = json_decode($row->meta, true);
            if (is_array($decoded)) $meta = $decoded;
        }
        if ($reason) $meta['fail_reason'] = $reason;

        $update = [
            'status'     => 'failed',
            'meta'       => !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            'updated_at' => now(),
        ];

        [$actorId, $actorRole]          = $this->resolveActor([]); // از auth() پر می‌شود
        $update['performed_by_id']      = $actorId;
        $update['performed_by_role']    = $actorRole;

        DB::table('panel_transactions')->where('id', $trxId)->update($update);

    }

    public function walletTopupCardVerified(int $panelUserId, int $amount, int $receiptId, ?int $adminId = null): int
    {
        return DB::transaction(function () use ($panelUserId, $amount, $receiptId, $adminId) {
            // 1) قفل ردیف کاربر و گرفتن موجودی فعلی
            $before = (int) DB::table('panel_users')
                ->where('id', $panelUserId)
                ->lockForUpdate()
                ->value('credit');

            $after = $before + (int) $amount;
            $actorId   = $adminId ?? (auth()->id() ?? null);
            $actorRole = null;

            if ($adminId) {
                $actorRole = DB::table('panel_users')->where('id', $adminId)->value('role');
            } elseif (auth()->user()) {
                $actorRole = auth()->user()->role ?? null;
            }

            $idempotency = "verify_receipt:{$receiptId}";

            $exists = DB::table('panel_transactions')
                ->where('idempotency_key', $idempotency)
                ->value('id');

            if ($exists) {
                return (int) $exists; // همین رسید قبلاً تأیید و شارژ شده
            }

            // 2) درج در panel_transactions با ستون‌های درست
            $txId = DB::table('panel_transactions')->insertGetId([
                'panel_user_id'        => $panelUserId,
                'type'                 => 'wallet_topup_card',
                'direction'            => 'credit', // شارژ = واریز
                'amount'               => (int) $amount,
                'balance_before'       => $before,
                'balance_after'        => $after,
                'status'               => 'success',
                'reference_type'       => 'panel_wallet_receipts',
                'reference_id'         => $receiptId,
                'quantity'             => 1,
                'currency'             => 'IRT',
                'performed_by_id'      => $actorId,
                'performed_by_role'    => $actorRole,
                'idempotency_key'      => $idempotency,
                'meta'                 => json_encode([
                    'method'   => 'card',
                    'by_admin' => $adminId,
                ], JSON_UNESCAPED_UNICODE),
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);

            // 3) آپدیت موجودی کاربر
            DB::table('panel_users')
                ->where('id', $panelUserId)
                ->update([
                    'credit'     => $after,
                    'updated_at' => now(),
                ]);

            // 4) تغییر وضعیت رسید + نال‌کردن notified_at (برای توستِ فرانت)
            DB::table('panel_wallet_receipts')
                ->where('id', $receiptId)
                ->update([
                    'status'      => 'verified',
                    'notified_at' => null,
                    'updated_at'  => now(),
                ]);

            // اگر کاربر chat_id تلگرام داشت، پیام را بعد از COMMIT (یا همین‌جا) بفرست
            $chatId = DB::table('panel_users')->where('id', $panelUserId)->value('telegram_user_id');

            if (!empty($chatId)) {
                $msg = "✅ کیف پول شما با مبلغ " . number_format((int)$amount) . " تومان شارژ شد.\n"
                    . "💰 موجودی فعلی: " . number_format((int)$after) . " تومان\n";

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

            return $txId;
        });
    }
    
    /**
     * واریز کمیسیون به کیف‌پول معرّف + ثبت لاگ تراکنش + نوتیف تلگرام بعد از کامیت.
     *
     * @param int        $referrerId   شناسه‌ی معرّف (panel_users.id)
     * @param int        $amount       مبلغ کمیسیون (تومان، عدد صحیح)
     * @param array      $meta         اطلاعات تکمیلی (source_user_id, source_user_code/email/name, source_amount, commission_rate, source_receipt_id, user_ids[ ] ...)
     * @param int|null   $actorId      اگر ادمین/اکتور مشخصی انجام می‌دهد؛ در غیر این صورت از auth()->user() گرفته می‌شود
     * @return int                     شناسه تراکنش درج‌شده در panel_transactions
     */
    public function walletReferrerCommissionAwarded(int $referrerId, int $amount, array $meta = [], ?int $actorId = null): int
    {
        if ($amount <= 0) return 0;

        return \Illuminate\Support\Facades\DB::transaction(function () use ($referrerId, $amount, $meta, $actorId) {
            // 1) balance قبل/بعد
            $before = (int) \Illuminate\Support\Facades\DB::table('panel_users')
                ->where('id', $referrerId)
                ->lockForUpdate()
                ->value('credit');

            $after = $before + (int) $amount;

            // 2) actor
            [$actorResolvedId, $actorRole] = $this->resolveActor([
                'performed_by_id'   => $actorId,
                'performed_by_role' => $meta['performed_by_role'] ?? null,
            ]);

            if (empty($meta['user_ids'])) {
                if (!empty($meta['source_user_id'])) $meta['user_ids'] = [(int) $meta['source_user_id']];
                else $meta['user_ids'] = [];
            }

            // 3) آپدیت کیف معرّف
            \Illuminate\Support\Facades\DB::table('panel_users')
                ->where('id', $referrerId)
                ->update(['credit' => $after]);

            // 4) درج تراکنش
            $txId = \Illuminate\Support\Facades\DB::table('panel_transactions')->insertGetId([
                'panel_user_id'      => $referrerId,
                'type'               => 'referrer_commission',
                'direction'          => 'credit',
                'amount'             => (int) $amount,
                'balance_before'     => (int) $before,         // ✅ فیکس اصلی
                'balance_after'      => (int) $after,
                'status'             => 'success',
                'performed_by_id'    => $actorResolvedId,
                'performed_by_role'  => 'system', // این تراکنش را سیستم انجام داده 
                'meta'               => json_encode($meta, JSON_UNESCAPED_UNICODE),
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);

            // 5) پیام تلگرام (ساده، بدون helperهای داخلی)
            $session = \Illuminate\Support\Facades\DB::table('panel_bot_sessions')
                ->select('chat_id')
                ->where('panel_user_id', $referrerId)
                ->first();

            if ($session && !empty($session->chat_id)) {
                $chatId    = (string) $session->chat_id;
                $rep       = $meta['source_user_name'] ?? $meta['source_user_code'] ?? $meta['source_user_email'] ?? 'نماینده';
                $fmtAmount = number_format((int)$amount);
                $fmtAfter  = number_format((int)$after);

                $msg  = "✅ کیف‌ پول شما با مبلغ {$fmtAmount} تومان بابت کمیسیون نماینده «{$rep}» شارژ شد.\n";
                $msg .= "💰 موجودی فعلی: {$fmtAfter} تومان";

                \Illuminate\Support\Facades\DB::afterCommit(function () use ($chatId, $msg) {
                    try {
                        app(\App\Services\TelegramNotifier::class)->sendTextTo($chatId, $msg);
                    } catch (\Throwable $e) {

                    }
                });
            }

            return $txId;
        });
    }

    
}
