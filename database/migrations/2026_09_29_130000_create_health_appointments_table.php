<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cerne Saúde › Ficha de Saúde — agenda de consultas e exames (fase B,
 * versão simples: sem cadastro próprio de médico, sem vínculo com
 * histórico de remédio — é só data, lugar e "o que aconteceu"). Mesmo
 * escopo de CoupleHealthScope da ficha: visível ao casal, nunca ao
 * consultor/corretor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained('profile_members')->cascadeOnDelete();
            $table->string('kind', 20);
            // "Cardiologista", "Hemograma completo" — nome do profissional/
            // especialidade ou do exame, texto livre (mesma escolha do
            // "prescriber" do remédio: cadastro próprio de médico é fase C).
            $table->string('title', 120);
            $table->string('location', 160)->nullable();
            $table->dateTime('scheduled_at');
            // Preenchido DEPOIS que acontece — "o que mudou/o que saiu".
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by_member_id')->nullable()->constrained('profile_members')->nullOnDelete();
            $table->timestamps();

            $table->index(['member_id', 'scheduled_at']);
            $table->index(['profile_id', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_appointments');
    }
};
