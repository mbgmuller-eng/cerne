<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_reserves', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained('profile_members')->cascadeOnDelete();

            $table->string('reserve_type', 20);
            $table->decimal('target_amount', 15, 2)->default(0);
            $table->decimal('current_amount', 15, 2)->default(0);

            // Quando vinculada, o valor atual acompanha o investimento.
            $table->foreignUuid('linked_investment_id')->nullable()->constrained('investment_records')->nullOnDelete();

            $table->timestamps();

            $table->unique(['profile_id', 'member_id', 'reserve_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_reserves');
    }
};
