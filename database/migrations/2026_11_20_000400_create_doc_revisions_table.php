<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doc_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doc_section_id')->nullable()->constrained('doc_sections')->nullOnDelete();
            $table->string('version_label', 64);
            $table->longText('snapshot_json');
            $table->string('change_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['doc_section_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_revisions');
    }
};
