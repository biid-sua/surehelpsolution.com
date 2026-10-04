<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-day counter used to hand out call IDs (CL-YYYYMMDD-0001) without duplicates.
     */
    public function up(): void
    {
        Schema::create('call_id_sequences', function (Blueprint $table) {
            $table->date('date')->primary();
            $table->unsignedInteger('last_sequence')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_id_sequences');
    }
};
