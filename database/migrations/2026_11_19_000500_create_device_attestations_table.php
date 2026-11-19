<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_attestations', function (Blueprint $table) {
            $table->string('device_token', 128)->primary();
            $table->string('device_public_key_b64', 88);
            $table->string('play_integrity_verdict', 64)->nullable();
            $table->unsignedBigInteger('last_scan_seq')->default(0);
            $table->timestamps();
            $table->index('last_scan_seq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_attestations');
    }
};
