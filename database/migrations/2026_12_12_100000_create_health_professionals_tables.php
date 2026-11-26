<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cerne Saúde: médicos e profissionais guardados para reusar em consultas, pedidos de exame, laudos, programa de atividades e remédios.
 *
 * O profissional não é de uma pessoa só: a ligação (`health_professional_links`) diz quem ele atende, e é ela que decide quem o vê (quem acompanha
 * só uma pessoa vê os dela). Textos cifrados.
 *
 * Os registros continuam guardando o NOME escrito (como foi no dia); `professional_id` só liga o registro à ficha. Apagar a ficha solta a ligação e
 * não muda o registro.
 */
return new class extends Migration
{
    private const LINKED_TABLES = ['health_appointments', 'health_exam_requests', 'health_exam_reports', 'health_followups', 'health_medications'];

    public function up(): void
    {
        Schema::create('health_professionals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
            $table->text('name');
            foreach (['specialty', 'registry', 'place', 'address', 'phone', 'notes'] as $coluna) {
                $table->text($coluna)->nullable();
            }
            $table->boolean('is_favorite')->default(false);
            $this->actors($table);
        });

        Schema::create('health_professional_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
            $table->foreignUuid('professional_id')->constrained('health_professionals')->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained('profile_members')->cascadeOnDelete();
            $this->actors($table);
            $table->unique(['professional_id', 'member_id'], 'health_professional_links_unique');
        });

        foreach (self::LINKED_TABLES as $tabela) {
            Schema::table($tabela, function (Blueprint $table): void {
                $table->foreignUuid('professional_id')->nullable()->constrained('health_professionals')->nullOnDelete();
            });
        }
    }

    private function actors(Blueprint $table): void
    {
        $table->foreignUuid('created_by_member_id')->nullable()->constrained('profile_members')->nullOnDelete();
        $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignUuid('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamps();
    }

    public function down(): void
    {
        foreach (self::LINKED_TABLES as $tabela) {
            Schema::table($tabela, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('professional_id');
            });
        }

        Schema::dropIfExists('health_professional_links');
        Schema::dropIfExists('health_professionals');
    }
};
