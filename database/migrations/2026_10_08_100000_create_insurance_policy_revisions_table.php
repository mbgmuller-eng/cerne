<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Uma linha por VERSÃO da apólice (o que valia a partir de uma data), para mostrar desde quando
        // começou e como foram os reajustes. A apólice em si continua guardando só o estado de hoje.
        Schema::create('insurance_policy_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
            $table->foreignUuid('insurance_policy_id')->constrained('insurance_policies')->cascadeOnDelete();
            // A partir de quando estes valores valem (vem do PDF, não do dia do envio).
            $table->date('effective_on');
            $table->string('source', 20);

            $table->decimal('monthly_premium', 15, 2);
            $table->decimal('annual_premium', 15, 2)->nullable();
            $table->string('payment_frequency', 20);
            $table->decimal('coverage_amount', 15, 2)->nullable();
            $table->json('coverages')->nullable();
            $table->date('expiry_date')->nullable();

            $table->foreignUuid('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignUuid('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Uma versão por data e por apólice: reenviar o mesmo PDF ou editar duas vezes no dia atualiza
            // a linha em vez de duplicá-la, e a corrida entre dois envios cai no índice, não num "se existe".
            $table->unique(['insurance_policy_id', 'effective_on'], 'policy_revisions_policy_date_unique');
            $table->index('profile_id', 'policy_revisions_profile_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_policy_revisions');
    }
};
