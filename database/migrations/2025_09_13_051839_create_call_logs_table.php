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
        Schema::create('call_logs', function (Blueprint $table) {
            $table->id();
            $table->string('call_id')->unique();
            $table->string('client_id')->nullable();
            $table->date('call_date');
            $table->time('call_time');
            $table->string('caller_name')->nullable();
            $table->string('caller_phone')->nullable();
            $table->string('caller_email')->nullable();
            $table->string('reason_for_call');
            $table->string('call_outcome');
            $table->string('agent_name');
            $table->enum('status', ['new', 'service-requested', 'information-provided', 'cancelled', 'spam']);
            $table->boolean('service_request')->default(false);
            $table->date('service_date')->nullable();
            $table->string('service_window')->nullable();
            $table->text('service_location')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_logs');
    }
};
