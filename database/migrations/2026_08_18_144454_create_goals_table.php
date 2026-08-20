<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();

            // Nulo = objetivo do casal.
            $table->foreignUuid('member_id')->nullable()->constrained('profile_members')->nullOnDelete();

            $table->string('name', 255);

            // 1 = mais prioritário. É por aqui que a tela ordena.
            $table->unsignedSmallInteger('priority')->default(1);

            $table->decimal('estimated_value', 15, 2);
            $table->date('target_date')->nullable();
            $table->string('funding_method', 30);
            $table->decimal('installment_amount', 15, 2)->nullable();
            $table->decimal('current_amount', 15, 2)->default(0);

            // Quando vinculado, o progresso acompanha o investimento.
            $table->foreignUuid('linked_investment_id')->nullable()->constrained('investment_records')->nullOnDelete();

            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by_user_id')->constrained('users');
            $table->timestamps();

            $table->index(['profile_id', 'status', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goals');
    }
};
