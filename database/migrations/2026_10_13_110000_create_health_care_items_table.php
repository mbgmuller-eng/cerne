<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cerne Saúde › Cuidados e itens: aparelho auditivo (filtro a cada 15 dias),
 * palmilha (a cada 6 meses), óculos, próteses, meias de compressão... Item,
 * frequência, última vez, próxima vez, lembrete (sugestão de quem testa o app).
 *
 * Mesmo escopo de CoupleHealthScope da Ficha de Saúde: o casal vê, o
 * consultor/corretor nunca. `member_id` é DE QUEM é o item (a palmilha da avó),
 * não quem está cadastrando.
 *
 * `next_due_on` é gravado (não calculado na leitura) para o cron achar quem vence
 * hoje ou amanhã com um índice, sem varrer todos os itens do app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_care_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained('profile_members')->cascadeOnDelete();
            $table->string('category', 30);
            // O que se troca: "Filtro", "Palmilha ortopédica".
            $table->string('name', 120);
            // O aparelho a que o item pertence, quando existe: "Aparelho auditivo Phonak".
            $table->string('device_name', 120)->nullable();
            $table->unsignedSmallInteger('interval_value');
            $table->string('interval_unit', 10);
            $table->date('last_done_on')->nullable();
            $table->date('next_due_on')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by_member_id')->nullable()->constrained('profile_members')->nullOnDelete();
            $table->timestamps();

            $table->index(['profile_id', 'next_due_on']);
            $table->index(['is_active', 'next_due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_care_items');
    }
};
