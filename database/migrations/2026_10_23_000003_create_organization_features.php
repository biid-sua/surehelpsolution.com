<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff switch a paid feature on or off for one business, whatever its plan (FND-18, D46).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 60);
            $table->boolean('enabled');
            $table->string('note', 255)->nullable();
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'feature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_features');
    }
};
