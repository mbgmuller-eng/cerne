<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cotação (simulação) feita para um lead de seguros. Um lead tem várias: uma por seguradora ou por alternativa
     * de cobertura. Como o lead, não tem profile_id: o contato ainda não é cliente de ninguém.
     */
    public function up(): void
    {
        Schema::create('lead_quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('lead_id')->constrained('leads')->cascadeOnDelete();

            $table->string('insurance_type', 20);
            $table->string('insurer_name', 255);
            $table->string('product', 255)->nullable();
            // Número da simulação na seguradora, para achar de novo no portal dela.
            $table->string('reference', 100)->nullable();

            // Valor de CADA pagamento e de quanto em quanto tempo (mesma ideia da apólice).
            $table->decimal('premium', 15, 2)->nullable();
            $table->string('payment_frequency', 15)->default('monthly');

            // Capital principal (morte, no seguro de vida) e a lista de proteções {name, value, deductible}.
            $table->decimal('coverage_amount', 15, 2)->nullable();
            $table->json('coverages')->nullable();

            // Comissão prevista, em % do prêmio anual. Opcional: só o profissional vê.
            $table->decimal('commission_percent', 5, 2)->nullable();

            $table->date('valid_until')->nullable();
            $table->string('status', 15)->default('draft');
            $table->text('notes')->nullable();

            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['lead_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_quotes');
    }
};
