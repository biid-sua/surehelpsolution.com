<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demo data an administrator can load and remove (D94). Demo businesses and accounts are flagged, so
 * nothing about them reaches the outside world; every row the load created is recorded, so removal
 * deletes exactly those rows (and what was later added inside the demo businesses), never real data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->index();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->index();
        });

        // Rows the load created, per table: primary keys as ranges ([[from, to], ...]) or a key list.
        Schema::create('demo_data_rows', function (Blueprint $table) {
            $table->id();
            $table->string('table_name', 64)->unique();
            $table->json('ranges')->nullable();
            $table->json('keys')->nullable();
            $table->timestamps();
        });

        // Rows that existed before the load and that it changed or deleted: put back on removal.
        Schema::create('demo_data_changes', function (Blueprint $table) {
            $table->id();
            $table->string('table_name', 64);
            $table->string('record_key', 191);
            $table->string('type', 10);              // updated | deleted
            $table->json('before');
            $table->json('after')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_data_changes');
        Schema::dropIfExists('demo_data_rows');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_demo'));
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn('is_demo'));
    }
};
