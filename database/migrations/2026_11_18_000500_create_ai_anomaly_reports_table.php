<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_anomaly_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('po_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->string('batch_id', 32)->nullable()->index();
            $table->enum('anomaly_type', [
                'clone_cluster',
                'ghost_production_attempt',
                'rework_gap',
                'defect_cluster',
            ])->index();
            $table->text('explanation_en');
            $table->text('explanation_bn');
            $table->json('affected_units_json')->nullable();
            $table->string('status', 24)->default('open')->index();
            $table->timestamps();

            $table->index(['po_id', 'batch_id', 'anomaly_type', 'status'], 'ai_reports_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_anomaly_reports');
    }
};
