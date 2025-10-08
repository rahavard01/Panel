<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::create('panel_users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 255);
            $table->string('email', 255);
            $table->string('password', 255);
            $table->unsignedBigInteger('telegram_user_id')->nullable();
            $table->unsignedBigInteger('referred_by_id')->nullable();
            $table->decimal('ref_commission_rate', 5, 2)->nullable();
            $table->integer('role')->default(2);
            $table->boolean('banned')->default(0);
            $table->integer('traffic_price')->nullable();
            $table->boolean('enable_personalized_price')->default(0);
            $table->integer('personalized_price_test')->nullable();
            $table->integer('personalized_price_1')->nullable();
            $table->integer('personalized_price_3')->nullable();
            $table->integer('personalized_price_6')->nullable();
            $table->integer('personalized_price_12')->nullable();
            $table->integer('credit')->nullable();
            $table->text('code')->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['email'], 'users_email_unique');
            $table->unique(['telegram_user_id'], 'panel_users_telegram_user_id_unique');
            $table->index(['referred_by_id'], 'panel_users_referred_by_id_index');
        });

        Schema::create('panel_announcements', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('title', 190);
            $table->longText('body')->nullable();
            $table->string('audience', 32)->default('all');
            $table->boolean('is_published')->default(0);
            $table->dateTime('publish_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('tg_broadcasted_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->index(['is_published', 'publish_at'], 'panel_announcements_is_published_publish_at_index');
            $table->index(['expires_at'], 'panel_announcements_expires_at_index');
            $table->index(['created_by'], 'panel_announcements_created_by_foreign');
            $table->index(['tg_broadcasted_at'], 'pa_tg_broadcasted_idx');
        });

        Schema::create('panel_announcement_reads', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('announcement_id');
            $table->unsignedBigInteger('user_id');
            $table->string('tg_chat_id', 255)->nullable();
            $table->unsignedBigInteger('tg_message_id')->nullable();
            $table->dateTime('ack_at')->nullable();
            $table->dateTime('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['announcement_id', 'user_id'], 'panel_announcement_reads_announcement_id_user_id_unique');
            $table->unique(['announcement_id', 'user_id'], 'ann_user_unique');
            $table->index(['user_id'], 'panel_announcement_reads_user_id_foreign');
            $table->index(['tg_chat_id'], 'panel_announcement_reads_tg_chat_id_index');
            $table->index(['tg_message_id'], 'panel_announcement_reads_tg_message_id_index');
        });

        Schema::create('panel_bot_sessions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('chat_id');
            $table->enum('state', ['idle','awaiting_username','awaiting_password','logged_in'])->default('idle');
            $table->unsignedBigInteger('panel_user_id')->nullable();
            $table->string('temp_username', 255)->nullable();
            $table->timestamp('last_activity')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['chat_id'], 'panel_bot_sessions_chat_id_unique');
            $table->index(['panel_user_id'], 'panel_bot_sessions_panel_user_id_index');
        });

        Schema::create('panel_bot_settings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->boolean('enabled')->default(0);
            $table->boolean('notify_on_card_submit')->default(1);
            $table->boolean('allow_approve_via_telegram')->default(1);
            $table->boolean('use_photo')->default(1);
            $table->text('message_template')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });

        Schema::create('panel_card_number', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->text('card')->nullable();
            $table->text('name')->nullable();
        });

        Schema::create('panel_partner_user_bulk_bans', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('partner_id');
            $table->unsignedBigInteger('v2_user_id');
            $table->boolean('active')->default(1);
            $table->timestamp('cleared_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['partner_id', 'v2_user_id', 'active'], 'uniq_partner_user_active');
            $table->index(['partner_id', 'active'], 'panel_partner_user_bulk_bans_partner_id_active_index');
        });

        Schema::create('panel_plan', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 255);
            $table->string('plan_key', 16)->nullable();
            $table->boolean('enable')->default(1);
            $table->text('default_price')->nullable();
            $table->integer('v2_plan_id')->nullable();
            $table->text('details');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['plan_key'], 'plan_key');
        });

        Schema::create('panel_referrals', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('referrer_id');
            $table->unsignedBigInteger('referee_id')->nullable();
            $table->string('referee_name', 255)->nullable();
            $table->string('referee_email', 255)->nullable();
            $table->string('referee_code', 64)->nullable();
            $table->text('note')->nullable();
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('decided_by_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decide_reason', 255)->nullable();
            $table->string('pricing_strategy', 32)->nullable();
            $table->json('pricing_payload')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('admin_notified_at')->nullable();
            $table->timestamp('referrer_notified_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['referee_code', 'status'], 'pr_referee_code_status_unique');
            $table->index(['referee_id'], 'panel_referrals_referee_id_foreign');
            $table->index(['decided_by_id'], 'panel_referrals_decided_by_id_foreign');
            $table->index(['referrer_id', 'status'], 'panel_referrals_referrer_id_status_index');
            $table->index(['referee_email'], 'panel_referrals_referee_email_index');
            $table->index(['referee_code'], 'pr_referee_code_idx');
            $table->index(['status', 'created_at'], 'idx_referrals_status_created_at');
            $table->index(['referee_email'], 'idx_referrals_referee_email');
            $table->index(['referee_code'], 'idx_referrals_referee_code');
        });

        Schema::create('panel_settings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('key', 255);
            $table->string('value', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['key'], 'panel_settings_key_unique');
        });

        Schema::create('panel_transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('panel_user_id');
            $table->string('type', 32);
            $table->string('direction', 10);
            $table->decimal('amount', 18, 0);
            $table->decimal('balance_before', 18, 0);
            $table->decimal('balance_after', 18, 0);
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('plan_key_before', 20)->nullable();
            $table->string('plan_key_after', 20)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('performed_by_id')->nullable();
            $table->string('performed_by_role', 16)->nullable();
            $table->string('currency')->default('IRT');
            $table->string('status', 16)->default('success');
            $table->string('idempotency_key', 100)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['idempotency_key'], 'panel_transactions_idempotency_key_unique');
            $table->index(['panel_user_id'], 'panel_transactions_panel_user_id_index');
            $table->index(['type'], 'panel_transactions_type_index');
            $table->index(['reference_type'], 'panel_transactions_reference_type_index');
            $table->index(['reference_id'], 'panel_transactions_reference_id_index');
            $table->index(['performed_by_id'], 'panel_transactions_performed_by_id_index');
        });

        Schema::create('panel_tutorials', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->enum('category', ['ios','android','system']);
            $table->string('app_name', 190);
            $table->string('icon_path', 255)->nullable();
            $table->string('download_url', 255)->nullable();
            $table->string('video_path', 255)->nullable();
            $table->text('desc')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('last_important_updated_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->index(['category'], 'panel_tutorials_category_index');
            $table->index(['published_at'], 'panel_tutorials_published_at_index');
        });

        Schema::create('panel_tutorial_toasts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('tutorial_id');
            $table->enum('kind', ['published','updated']);
            $table->timestamp('acked_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['user_id', 'tutorial_id', 'kind'], 'ptt_user_tutorial_kind_unique');
            $table->index(['user_id'], 'panel_tutorial_toasts_user_id_index');
            $table->index(['tutorial_id'], 'panel_tutorial_toasts_tutorial_id_index');
        });

        Schema::create('panel_wallet_receipts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('amount')->nullable();
            $table->string('method', 24)->default('card');
            $table->string('disk', 32)->default('public');
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime', 64)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('status', 24)->default('uploaded');
            $table->boolean('commission_paid')->default(0);
            $table->unsignedBigInteger('commission_tx_id')->nullable();
            $table->timestamp('commission_notified_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->index(['user_id', 'status'], 'wallet_receipts_user_id_status_index');
            $table->index(['commission_tx_id'], 'panel_wallet_receipts_commission_tx_id_foreign');
        });

        DB::table('panel_bot_settings')->insert(['enabled' => 1, 'notify_on_card_submit' => 1, 'allow_approve_via_telegram' => 1, 'use_photo' => 0, 'message_template' => '🧾 کارت‌به‌کارت\nشماره رسید: {id}\nکاربر: {user_code}\nمبلغ: {amount} تومان\nزمان: {created_at}']);
        DB::table('panel_plan')->insert(['name' => 'اکانت تست', 'plan_key' => 'test', 'enable' => 1, 'default_price' => '', 'v2_plan_id' => null, 'details' => '']);
        DB::table('panel_plan')->insert(['name' => 'اکانت یک‌ ماهه', 'plan_key' => '1m', 'enable' => 1, 'default_price' => '', 'v2_plan_id' => null, 'details' => '']);
        DB::table('panel_plan')->insert(['name' => 'اکانت سه‌ ماهه', 'plan_key' => '3m', 'enable' => 1, 'default_price' => '', 'v2_plan_id' => null, 'details' => '']);
        DB::table('panel_plan')->insert(['name' => 'اکانت شش‌ ماهه', 'plan_key' => '6m', 'enable' => 1, 'default_price' => '', 'v2_plan_id' => null, 'details' => '']);
        DB::table('panel_plan')->insert(['name' => 'اکانت یک‌ ساله', 'plan_key' => '12m', 'enable' => 1, 'default_price' => '', 'v2_plan_id' => null, 'details' => '']);
        DB::table('panel_plan')->insert(['name' => 'هر گیگ ترافیک', 'plan_key' => 'gig', 'enable' => 1, 'default_price' => '', 'v2_plan_id' => null, 'details' => '']);
        DB::table('panel_settings')->insert(['key' => 'referral_default_commission_rate', 'value' => '']);
        Schema::table('panel_announcements', function (Blueprint $table) {
            $table->foreign('created_by')->references('id')->on('panel_users')->onDelete('set null on update cascade');
        });
        Schema::table('panel_announcement_reads', function (Blueprint $table) {
            $table->foreign('announcement_id')->references('id')->on('panel_announcements')->onDelete('cascade on update cascade');
        });
        Schema::table('panel_announcement_reads', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('panel_users')->onDelete('cascade on update cascade');
        });
        Schema::table('panel_bot_sessions', function (Blueprint $table) {
            $table->foreign('panel_user_id')->references('id')->on('panel_users')->onDelete('set null');
        });
        Schema::table('panel_referrals', function (Blueprint $table) {
            $table->foreign('decided_by_id')->references('id')->on('panel_users')->onDelete('set null');
        });
        Schema::table('panel_referrals', function (Blueprint $table) {
            $table->foreign('referee_id')->references('id')->on('panel_users')->onDelete('set null');
        });
        Schema::table('panel_referrals', function (Blueprint $table) {
            $table->foreign('referrer_id')->references('id')->on('panel_users')->onDelete('cascade');
        });
        Schema::table('panel_wallet_receipts', function (Blueprint $table) {
            $table->foreign('commission_tx_id')->references('id')->on('panel_transactions')->onDelete('set null');
        });
        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('panel_wallet_receipts');
        Schema::dropIfExists('panel_tutorial_toasts');
        Schema::dropIfExists('panel_tutorials');
        Schema::dropIfExists('panel_transactions');
        Schema::dropIfExists('panel_settings');
        Schema::dropIfExists('panel_referrals');
        Schema::dropIfExists('panel_plan');
        Schema::dropIfExists('panel_partner_user_bulk_bans');
        Schema::dropIfExists('panel_card_number');
        Schema::dropIfExists('panel_bot_settings');
        Schema::dropIfExists('panel_bot_sessions');
        Schema::dropIfExists('panel_announcement_reads');
        Schema::dropIfExists('panel_announcements');
        Schema::dropIfExists('panel_users');
        Schema::enableForeignKeyConstraints();
    }
};