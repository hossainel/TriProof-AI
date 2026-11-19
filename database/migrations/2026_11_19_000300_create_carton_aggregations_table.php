<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carton_aggregations', function (Blueprint $table) {
            $table->id();
            $table->string('carton_serial', 64)->unique();
            $table->foreignId('po_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->string('factory_id', 32);
            $table->string('batch_id', 32);
            $table->unsignedInteger('unit_count');
            $table->char('carton_merkle_root', 64);
            $table->string('carton_signature', 255);
            $table->enum('status', ['sealed', 'in_transit', 'customs_cleared', 'opened'])->default('sealed');
            $table->string('sealed_by_device', 128);
            $table->timestamps();
            $table->index(['po_id', 'batch_id']);
            $table->index('status');
        });

        Schema::create('carton_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carton_id')->constrained('carton_aggregations')->cascadeOnDelete();
            $table->foreignId('serial_registry_id')->unique()->constrained('serial_registry')->cascadeOnDelete();
            $table->unsignedInteger('leaf_index');
            $table->timestamps();
            $table->unique(['carton_id', 'leaf_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carton_units');
        Schema::dropIfExists('carton_aggregations');
    }
};
