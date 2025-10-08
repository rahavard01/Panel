<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use App\Services\TransactionLogger;

class PanelPricingService
{
    /**
     * planKey ورودی‌های مجاز: 'test' | '1m' | '1m_noexp' | '3m' | '6m' | '12m'
     * - '1m_noexp' از نظر قیمت با '1m' یکی است.
     * quantity: تعداد (برای حالت گروهی >1، برای حالت تکی = 1)
     *
     * خروجی: ['ok'=>bool, 'unit_price'=>string, 'total_price'=>string, 'reason'=>string|null]
     * اگر ok=true بود، اعتبار از panel_users.credit کم شده است (اتمیک).
     */
    public function ensureBalanceAndCharge(int $panelUserId, string $planKey, int $quantity = 1): array
    {
        $planKey = $this->normalizePlanKey($planKey);
        $quantity = max(1, (int)$quantity);

        return DB::transaction(function () use ($panelUserId, $planKey, $quantity) {
            $panelUser = DB::table('panel_users')->where('id', $panelUserId)->lockForUpdate()->first();
            if (!$panelUser) {
                return ['ok'=>false, 'unit_price'=>'0', 'total_price'=>'0', 'reason'=>'Panel user not found'];
            }

            $unitPrice = $this->resolveUnitPrice($panelUser, $planKey);
            if ($unitPrice === null) {
                return ['ok'=>false, 'unit_price'=>'0', 'total_price'=>'0', 'reason'=>'Price not configured'];
            }

            $total = bcmul($unitPrice, (string)$quantity, 0);
            $currentCredit = (string)($panelUser->credit ?? '0');

            if (bccomp($currentCredit, $total, 0) < 0) {
                return ['ok'=>false, 'unit_price'=>$unitPrice, 'total_price'=>$total, 'reason'=>'INSUFFICIENT_CREDIT'];
            }

            $after = bcsub($currentCredit, $total, 0);

            // کسر اعتبار
            DB::table('panel_users')->where('id', $panelUserId)->update([
                'credit' => $after,
                // updated_at توسط DB یا مکانیزم شما خودش آپدیت می‌شود
            ]);

            // شروع لاگ PENDING
            $logger = new TransactionLogger();
            $trxId = $logger->start([
                'panel_user_id'   => $panelUserId,
                'type'            => 'account_purchase',
                'direction'       => 'debit',
                'amount'          => $total,
                'balance_before'  => $currentCredit,
                'balance_after'   => $after,
                'quantity'        => $quantity,
                'plan_key_after'  => $planKey,
                'meta'            => [
                    'unit_price' => $unitPrice,
                ],
            ]);

            return [
                'ok'           => true,
                'unit_price'   => $unitPrice,
                'total_price'  => $total,
                'before'       => $currentCredit,
                'after'        => $after,
                'transaction_id' => $trxId,
            ];
        });
    }

    private function normalizePlanKey(string $planKey): string
    {
        $k = strtolower(trim($planKey));
        if ($k === '1m_noexp') return '1m'; // یک‌ماهه بدون تاریخ = یک‌ماهه
        return $k;
    }

    /**
     * تعیین قیمت واحد بر اساس personalized یا default
     * خروجی string (decimal) یا null اگر تعریف نشده باشد.
     *
     * ستون‌های personalized در panel_users:
     * - personalized_price_test
     * - personalized_price_1
     * - personalized_price_3
     * - personalized_price_6
     * - personalized_price_12
     */
    private function resolveUnitPrice(object $panelUser, string $planKey): ?string
    {
        $usePersonalized = (int)($panelUser->enable_personalized_price ?? 0) === 1;

        if ($usePersonalized) {
            $map = [
                'test' => 'personalized_price_test',
                '1m'   => 'personalized_price_1',
                '3m'   => 'personalized_price_3',
                '6m'   => 'personalized_price_6',
                '12m'  => 'personalized_price_12',
            ];
            $col = $map[$planKey] ?? null;
            if (!$col) return null;
            $val = $panelUser->{$col} ?? null;
            return $val !== null ? (string)$val : null;
        }

        // حالت دیفالت: خواندن از panel_plan.default_price
        $price = DB::table('panel_plan')
            ->where('plan_key', $planKey)
            ->value('default_price');

        return $price !== null ? (string)$price : null;
    }
    public function quoteUnitPrice(int $panelUserId, string $planKey): ?string
    {
        $planKey = $this->normalizePlanKey($planKey);

        // خواندن panel_user فعلی
        $panelUser = DB::table('panel_users')->where('id', $panelUserId)->first();
        if (!$panelUser) {
            return null;
        }

        // همان منطق internal برای تعیین قیمت: شخصی ← پیش‌فرض
        return $this->resolveUnitPrice($panelUser, $planKey);
    }
}
