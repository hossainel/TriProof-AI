<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number', 64)->unique();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->string('factory_id', 32)->index();
            $table->unsignedInteger('authorized_quota');
            $table->unsignedInteger('generated_count')->default(0);
            $table->unsignedSmallInteger('factory_expiry_days')->default(45);
            $table->unsignedSmallInteger('retail_expiry_days')->default(365);
            $table->char('merkle_root_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['buyer_id', 'created_at']);
            $table->index(['factory_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
