<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->json('dpp_metadata')->nullable()->after('merkle_root_hash');
            $table->char('dpp_digest', 8)->nullable()->after('dpp_metadata');
            $table->index('dpp_digest');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropIndex(['dpp_digest']);
            $table->dropColumn(['dpp_metadata', 'dpp_digest']);
        });
    }
};
