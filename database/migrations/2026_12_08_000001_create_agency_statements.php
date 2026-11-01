<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly statements of what each agency earned (D118): what its clients paid SureHelp that month
 * and the agency's share, until SureHelp marks it paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->unsignedBigInteger('clients_paid_cents');
            $table->decimal('commission_percent', 5, 2);
            $table->unsignedBigInteger('share_cents');
            $table->string('status', 10)->default('due');
            $table->dateTime('paid_at')->nullable();
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference', 120)->nullable();
            $table->timestamps();
            $table->unique(['agency_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_statements');
    }
};
