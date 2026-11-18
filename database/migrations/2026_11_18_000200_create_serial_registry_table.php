<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serial_registry', function (Blueprint $table) {
            $table->id();
            $table->foreignId('po_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->string('outer_serial', 128)->unique();
            $table->string('inner_serial', 255);
            $table->char('unit_hash', 64)->unique();
            $table->string('factory_id', 32);
            $table->string('machine_id', 32);
            $table->string('batch_id', 32);
            $table->unsignedInteger('unit_number');
            $table->date('prod_date');
            $table->date('exp_date')->index();
            $table->enum('status', [
                'generated',
                'packed',
                'in_transit',
                'claimed_genuine',
                'cloned_flagged',
                'recalled',
            ])->default('generated')->index();
            $table->string('claimed_device_token', 128)->nullable()->index();
            $table->dateTime('claimed_at')->nullable();
            $table->string('claimed_gps', 64)->nullable();
            $table->string('rework_parent_serial', 128)->nullable()->index();
            $table->timestamps();

            $table->index(['factory_id', 'machine_id', 'batch_id']);
            $table->index(['factory_id', 'machine_id', 'batch_id', 'unit_number'], 'serial_registry_batch_unit_idx');
            $table->index(['po_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serial_registry');
    }
};
