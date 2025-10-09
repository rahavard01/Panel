<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('panel_bot_settings')) return;

        Schema::table('panel_bot_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('panel_bot_settings', 'bot_token')) {
                $table->text('bot_token')->nullable()->after('use_photo');
            }
            if (!Schema::hasColumn('panel_bot_settings', 'webhook_secret')) {
                $table->text('webhook_secret')->nullable()->after('bot_token');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('panel_bot_settings')) return;

        Schema::table('panel_bot_settings', function (Blueprint $table) {
            if (Schema::hasColumn('panel_bot_settings', 'bot_token')) {
                $table->dropColumn('bot_token');
            }
            if (Schema::hasColumn('panel_bot_settings', 'webhook_secret')) {
                $table->dropColumn('webhook_secret');
            }
        });
    }
};
