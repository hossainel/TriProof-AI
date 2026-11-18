<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_logs', function (Blueprint $table) {
            $table->id();
            $table->string('sync_uuid', 64)->unique();
            $table->string('outer_serial', 128)->index();
            $table->string('inner_serial', 255);
            $table->string('device_token', 128)->index();
            $table->enum('user_role', ['global_buyer', 'factory_qa', 'admin']);
            $table->enum('scan_mode', ['offline_synced', 'online_live'])->default('offline_synced');
            $table->string('offline_verdict', 32)->nullable();
            $table->enum('cloud_cross_check_verdict', [
                'verified_clean',
                'owner_repeat_ok',
                'store_grace_ok',
                'clone_detected',
                'recalled_batch',
                'expired',
                'forged_signature',
            ])->index();
            $table->decimal('gps_lat', 10, 7)->nullable();
            $table->decimal('gps_lng', 10, 7)->nullable();
            $table->dateTime('scanned_at')->index();
            $table->dateTime('synced_at');
            $table->timestamps();

            $table->index(['outer_serial', 'scanned_at']);
            $table->index(['device_token', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_logs');
    }
};
