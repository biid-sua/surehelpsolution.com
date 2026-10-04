<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('agent_duty_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_id'); // Foreign key to users table
            $table->string('title'); // Shift title (e.g., "Morning Shift", "Afternoon Shift")
            $table->datetime('start_datetime'); // Shift start date and time
            $table->datetime('end_datetime'); // Shift end date and time
            $table->string('shift_type'); // morning, afternoon, evening, night, off
            $table->text('description')->nullable(); // Optional description
            $table->boolean('is_active')->default(true); // Whether this schedule is currently active
            $table->timestamps();

            // Foreign key constraint
            $table->foreign('agent_id')->references('id')->on('users')->onDelete('cascade');

            // Indexes for better performance
            $table->index(['agent_id', 'is_active']);
            $table->index(['start_datetime', 'end_datetime']);
            $table->index(['agent_id', 'start_datetime', 'end_datetime']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_duty_schedules');
    }
};
