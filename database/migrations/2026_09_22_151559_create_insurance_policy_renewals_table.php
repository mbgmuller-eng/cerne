<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurance_policy_renewals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('insurance_policy_id')->constrained('insurance_policies')->cascadeOnDelete();
            $table->foreignUuid('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->date('renewed_at');
            $table->decimal('previous_monthly_premium', 15, 2);
            $table->decimal('new_monthly_premium', 15, 2);
            $table->decimal('previous_coverage_amount', 15, 2)->nullable();
            $table->decimal('new_coverage_amount', 15, 2)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['insurance_policy_id', 'renewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_policy_renewals');
    }
};
