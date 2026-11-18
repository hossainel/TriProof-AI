<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recall_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('po_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->string('factory_id', 32);
            $table->string('machine_id', 32);
            $table->string('batch_id', 32);
            $table->unsignedInteger('affected_count')->default(0);
            $table->text('reason')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['factory_id', 'machine_id', 'batch_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recall_events');
    }
};
