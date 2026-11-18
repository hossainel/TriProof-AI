<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->string('device_token', 128)->index();
            $table->string('outer_serial', 128)->nullable()->index();
            $table->enum('severity', ['info', 'warning', 'critical_clone', 'recall_alert'])->index();
            $table->string('title_en');
            $table->text('message_en');
            $table->string('title_bn');
            $table->text('message_bn');
            $table->boolean('is_read')->default(false);
            $table->timestamps();

            $table->index(['device_token', 'is_read', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
