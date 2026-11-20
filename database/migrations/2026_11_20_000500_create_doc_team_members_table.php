<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doc_team_members', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('role_title');
            $table->string('email');
            $table->string('bio_short')->nullable();
            $table->string('photo_path')->nullable();
            $table->integer('sort_order')->default(0)->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_team_members');
    }
};
