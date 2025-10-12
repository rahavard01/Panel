<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\LoginController;
use App\Http\Controllers\Api\PanelController;
use App\Http\Middleware\CheckTokenExpiration;
use App\Http\Controllers\Api\V2UserController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Middleware\CheckUserBanned;
use App\Http\Controllers\Api\TariffsController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\Admin\WalletAdminController;
use App\Http\Controllers\Api\TelegramWebhookController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\PanelRepsController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\Admin\AnnouncementAdminController;
use App\Http\Controllers\Api\Admin\TutorialAdminController;
use App\Http\Controllers\Api\TutorialController;
use App\Http\Controllers\Api\Admin\PartnersAdminController;
use App\Http\Controllers\Api\Admin\TariffsAdminController;
use App\Http\Controllers\Api\Admin\CardAdminController;
use App\Http\Controllers\Api\Admin\CommissionAdminController;
use App\Http\Controllers\Api\Admin\BotAdminController;
use App\Http\Controllers\Api\Admin\UserAdminController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\Admin\DashboardAdminController;
use App\Http\Controllers\Api\DashboardPanelController;

/*
|--------------------------------------------------------------------------
| Public (no auth)
|--------------------------------------------------------------------------
*/
Route::post('/login', [LoginController::class, 'login']);
Route::get('/users/by-token', [PanelController::class, 'findByToken']);
Route::get('/settings/link-parts', [SettingController::class, 'linkParts']);
Route::get('/panel/plans', [V2UserController::class, 'plans']);
Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle']);
Route::get('/ping', fn () => response('pong', 200));

/*
|--------------------------------------------------------------------------
| Password Reset (PUBLIC, throttled)  ← اینجا عمومی است
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->middleware('throttle:10,1')->group(function () {
    Route::post('/forgot-password', [PasswordResetController::class, 'forgot']);
    Route::get('/reset-token/verify', [PasswordResetController::class, 'verifyToken']);
    Route::post('/reset-password', [PasswordResetController::class, 'reset']);
});

/*
|--------------------------------------------------------------------------
| Protected (auth + token not expired + not banned)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', CheckTokenExpiration::class, CheckUserBanned::class])->group(function () {
    // --- Panel / Users ---
    Route::get('/user/panel', [PanelController::class, 'getProfile']);
    Route::get('/user/wallet', [PanelController::class, 'getWallet']);
    Route::get('/users', [PanelController::class, 'listUsers']);
    Route::post('/panel/users/create', [V2UserController::class, 'create']);
    Route::get('/users/by-email', [PanelController::class, 'findByEmail']);
    Route::post('/users/ban', [PanelController::class, 'updateBan']);
    Route::post('/users/extend', [V2UserController::class, 'extend']);
    Route::post('/users/topup', [V2UserController::class, 'topup']);
    Route::post('/users/change-password', [PanelController::class, 'changePassword']);
    Route::post('/logout', [LoginController::class, 'logout']);
    Route::get('/panel/dashboard/cards', [DashboardPanelController::class, 'cards']);

    // لیست نمایندگان من (approved | pending | rejected | all)
    Route::get('/panel/my-reps', [PanelRepsController::class, 'index']);

    // --- Tariffs ---
    Route::get('/tariffs', [TariffsController::class, 'show']);

    // --- Wallet ---
    Route::get('/wallet/card', [WalletController::class, 'cardInfo']);
    Route::post('/wallet/card/receipt',  [WalletController::class, 'uploadReceipt']);
    Route::post('/wallet/card/submit',   [WalletController::class, 'submitCardDeposit']);
    Route::get ('/wallet/receipt/notifications/pending', [WalletController::class, 'pendingReceiptToasts']);
    Route::post('/wallet/receipt/notifications/{id}/ack', [WalletController::class, 'ackReceiptToast'])->whereNumber('id');
    Route::get('/user/transactions', [BillingController::class, 'self']);
    Route::get ('/wallet/adjust/notifications/pending', [WalletController::class, 'pendingAdjustToasts']);
    Route::post('/wallet/adjust/notifications/{id}/ack', [WalletController::class, 'ackAdjustToast'])->whereNumber('id');
    // Commission toasts for referrer (panel notifications)
    Route::get ('/wallet/commission/notifications/pending', [WalletController::class, 'pendingCommissionToasts']);
    Route::post('/wallet/commission/notifications/{id}/ack', [WalletController::class, 'ackCommissionToast'])->whereNumber('id');

    // --- Referral (user-side) ---
    Route::get('/validate/email-available',      [ReferralController::class, 'emailAvailable']);
    Route::get('/validate/panel-code-available', [ReferralController::class, 'panelCodeAvailable']);
    Route::post('/referrals',                    [ReferralController::class, 'store']);
    Route::get ('/referrals/notifications/pending', [ReferralController::class, 'refToastPending']);
    Route::post('/referrals/notifications/{id}/ack', [ReferralController::class, 'refToastAck'])->whereNumber('id');

    // === Announcements (User) ===
    Route::get ('/announcements', [AnnouncementController::class, 'index']);
    Route::get ('/announcements/notifications/pending', [AnnouncementController::class, 'pending']);
    Route::post('/announcements/notifications/{id}/ack', [AnnouncementController::class, 'ack'])->whereNumber('id');
    Route::post('/announcements/{id}/read', [AnnouncementController::class, 'read'])->whereNumber('id');
    Route::get('/announcements/unread/count', [AnnouncementController::class, 'unreadCount']);

    // === Tutorials (User list & detail) ===
    Route::get ('/tutorials',      [TutorialController::class, 'index']);   // لیست منتشرشده‌ها با فیلتر
    Route::get ('/tutorials/{id}', [TutorialController::class, 'show'])->whereNumber('id'); // جزئیات
    Route::get ('/tutorials/notifications/pending', [TutorialController::class, 'pendingToasts']);
    Route::post('/tutorials/notifications/{id}/ack', [TutorialController::class, 'ackToast'])->whereNumber('id');

    /*
    |--------------------------------------------------------------------------
    | Admin (همون URIهایی که فرانت الان مصرف می‌کنه)
    |--------------------------------------------------------------------------
    */

    // شمارنده بج
    Route::get('/admin/referrals/count', [ReferralController::class, 'adminCount']);

    // لیست
    Route::get('/admin/referrals', [ReferralController::class, 'adminIndex']);

    // ترتیب مهم: ثابت‌ها قبل از پارامتری‌ها
    Route::get ('/admin/referrals/notifications/pending', [ReferralController::class, 'adminToastPending']);
    Route::post('/admin/referrals/notifications/{id}/ack', [ReferralController::class, 'adminToastAck'])->whereNumber('id');

    // جزئیات آیتم
    Route::get('/admin/referrals/{id}', [ReferralController::class, 'adminShow'])->whereNumber('id');

    // اکشن‌ها
    Route::post('/admin/referrals/{id}/approve', [ReferralController::class, 'adminApprove'])->whereNumber('id');
    Route::post('/admin/referrals/{id}/reject',  [ReferralController::class, 'adminReject'])->whereNumber('id');

    // --- Tariffs Admin ---
    Route::get   ('/admin/tariffs',                    [TariffsAdminController::class, 'index']);
    Route::put   ('/admin/tariffs',                    [TariffsAdminController::class, 'update']);
    Route::patch ('/admin/tariffs/{plan_key}/enable',  [TariffsAdminController::class, 'patchEnable'])
        ->where('plan_key', '^(gig|test|1m|3m|6m|12m)$');
    Route::post  ('/admin/tariffs/percent-adjust',     [TariffsAdminController::class, 'percentAdjust']);

    // --- Card Settings Admin ---
    Route::get('/admin/card-settings', [CardAdminController::class, 'index']);
    Route::put('/admin/card-settings', [CardAdminController::class, 'update']);

    // --- Commission Settings Admin ---
    Route::get('/admin/commission-settings', [CommissionAdminController::class, 'index']);
    Route::put('/admin/commission-settings', [CommissionAdminController::class, 'update']);

    Route::get('/admin/users',           [UserAdminController::class, 'index']);
    Route::post('/admin/users/create',   [UserAdminController::class, 'create']);
    Route::post('/admin/users/extend',   [UserAdminController::class, 'extend']);
    Route::post('/admin/users/topup',    [UserAdminController::class, 'topup']);

    /*
    |--------------------------------------------------------------------------
    | Wallet Admin (با can:admin)
    |--------------------------------------------------------------------------
    */
    Route::middleware(['can:admin'])->prefix('admin')->group(function () {
        // شمارش واریزی‌های در انتظار (برای بج منو/همبرگر)
        Route::get('/wallet/receipts/count', [WalletAdminController::class, 'countPending']);

        // لیست واریزی‌ها (status: pending|verified|rejected|all)
        Route::get('/wallet/receipts', [WalletAdminController::class, 'index']);

        Route::get('/wallet/receipts/{id}/file', [WalletAdminController::class, 'showFile'])->whereNumber('id');

        // جزئیات یک رسید
        Route::get('/wallet/receipts/{id}', [WalletAdminController::class, 'show'])->whereNumber('id');

        // تأیید رسید
        Route::post('/wallet/receipts/{id}/verify', [WalletAdminController::class, 'verify'])->whereNumber('id');

        // رد رسید
        Route::post('/wallet/receipts/{id}/reject',  [WalletAdminController::class, 'reject'])->whereNumber('id');

        // === Announcements (Admin) ===
        Route::get   ('/announcements',             [AnnouncementAdminController::class, 'index']);
        Route::post  ('/announcements',             [AnnouncementAdminController::class, 'store']);
        Route::patch ('/announcements/{id}',        [AnnouncementAdminController::class, 'update'])->whereNumber('id');
        Route::post  ('/announcements/{id}/toggle', [AnnouncementAdminController::class, 'toggle'])->whereNumber('id');
        Route::delete('/announcements/{id}',        [AnnouncementAdminController::class, 'destroy'])->whereNumber('id');

        // === Tutorials (Admin) ===
        Route::get   ('/tutorials',      [TutorialAdminController::class, 'index']);
        Route::post  ('/tutorials',      [TutorialAdminController::class, 'store']);
        Route::patch ('/tutorials/{id}', [TutorialAdminController::class, 'update'])->whereNumber('id');
        Route::delete('/tutorials/{id}', [TutorialAdminController::class, 'destroy'])->whereNumber('id');

        // === Partners (Admin) ===
        Route::get   ('/partners',                 [PartnersAdminController::class, 'index']);
        Route::post  ('/partners',                 [PartnersAdminController::class, 'store']); 
        Route::delete('/partners/{id}',            [PartnersAdminController::class, 'destroy']);
        Route::patch ('/partners/{id}/profile',    [PartnersAdminController::class, 'updateProfile']);
        Route::get   ('/partners/{id}/pricing-preview', [PartnersAdminController::class, 'pricingPreview'])->whereNumber('id');
        Route::patch ('/partners/{id}/pricing',    [PartnersAdminController::class, 'updatePricing'])->whereNumber('id');
        Route::get   ('/partners/{id}/status-flags', [PartnersAdminController::class, 'statusFlags'])->whereNumber('id');
        Route::patch ('/partners/{id}/ban',        [PartnersAdminController::class, 'banPartner'])->whereNumber('id');
        Route::patch ('/partners/{id}/ban-users',  [PartnersAdminController::class, 'banPartnerUsers'])->whereNumber('id');
        Route::post  ('/partners/{id}/wallet-adjust', [PartnersAdminController::class, 'walletAdjust'])->whereNumber('id');

        Route::post('/bot/set-webhook',    [BotAdminController::class, 'setWebhook']);
        Route::post('/bot/remove-webhook', [BotAdminController::class, 'removeWebhook']);
        Route::get('/bot/status', [BotAdminController::class, 'status']);
		
        // === Billing (Admin) — فهرست همه‌ی صورتحساب‌ها برای پنل ادمین ===
        Route::get('/transactions', [BillingController::class, 'all']);
        // === Dashboard (Admin) 
        Route::get('/dashboard/cards', [DashboardAdminController::class, 'cards']);

    });
});
