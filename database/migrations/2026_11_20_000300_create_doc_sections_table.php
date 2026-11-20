<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doc_sections', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->enum('category', ['pitch_deck', 'technical', 'operations']);
            $table->string('section_key', 120);
            $table->string('title_en');
            $table->string('title_bn')->nullable();
            $table->string('subtitle_en')->nullable();
            $table->string('subtitle_bn')->nullable();
            $table->longText('content_markdown');
            $table->longText('draft_markdown')->nullable();
            $table->text('diagram_mermaid')->nullable();
            $table->integer('sort_order')->default(0)->index();
            $table->boolean('is_visible')->default(true);
            $table->enum('status', ['published', 'draft'])->default('published');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_sections');
    }
};
