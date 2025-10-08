<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->id();

            // به panel_users متصل می‌شویم (جدول اصلی کاربران پروژه تو)
            $table->unsignedBigInteger('user_id');

            // توکن به صورت هش (sha256) ذخیره می‌شود
            $table->string('token_hash', 64)->index();

            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();

            $table->timestamps();

            // FK صحیح به panel_users
            $table->foreign('user_id')
                  ->references('id')
                  ->on('panel_users')
                  ->onDelete('cascade');

            // ایندکس کمکی
            $table->index(['user_id', 'expires_at'], 'prt_user_exp_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
