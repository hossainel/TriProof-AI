<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blockchain_anchors', function (Blueprint $table) {
            $table->id();
            $table->date('anchor_date');
            $table->char('batch_merkle_root', 64);
            $table->longText('po_ids_json');
            $table->enum('provider', ['opentimestamps', 'polygon_rpc', 'local_ledger']);
            $table->text('tx_hash_or_receipt');
            $table->enum('status', ['pending', 'anchored', 'verified'])->default('pending');
            $table->timestamps();
            $table->index(['anchor_date', 'status']);
            $table->index('batch_merkle_root');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blockchain_anchors');
    }
};
