<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cerne Saúde › Ficha de Saúde — fase A do recurso de saúde familiar
 * (ver memória do projeto): tipo sanguíneo, alergia, doença/comorbidade
 * e remédio (com histórico de alteração), visível entre os dois do
 * casal. Nunca pro consultor/corretor — ver CoupleHealthScope.
 *
 * Toda tabela carrega profile_id + member_id (de quem é o fato), igual
 * ao padrão da Academia — o escopo daqui não precisa de join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_cards', function (Blueprint $table) {
            $this->owner($table);
            $table->string('blood_type', 3)->nullable();
            $table->foreignUuid('updated_by_member_id')->nullable()->constrained('profile_members')->nullOnDelete();
            $table->timestamps();

            // Uma ficha por pessoa.
            $table->unique('member_id');
        });

        Schema::create('health_allergies', function (Blueprint $table) {
            $this->owner($table);
            $table->string('description', 160);
            $table->foreignUuid('created_by_member_id')->nullable()->constrained('profile_members')->nullOnDelete();
            $table->timestamps();

            $table->index(['member_id']);
        });

        Schema::create('health_conditions', function (Blueprint $table) {
            $this->owner($table);
            $table->string('description', 160);
            $table->foreignUuid('created_by_member_id')->nullable()->constrained('profile_members')->nullOnDelete();
            $table->timestamps();

            $table->index(['member_id']);
        });

        Schema::create('health_medications', function (Blueprint $table) {
            $this->owner($table);
            $table->string('name', 120);
            $table->string('dose', 80)->nullable();
            // "Horário" da especificação original — texto livre ("8h e 20h",
            // "1x ao dia em jejum"): a variedade de esquemas de posologia não
            // cabe numa estrutura rígida sem virar uma tela de prescrição.
            $table->string('schedule', 120)->nullable();
            $table->string('reason', 160)->nullable();
            $table->string('prescriber', 120)->nullable();
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by_member_id')->nullable()->constrained('profile_members')->nullOnDelete();
            $table->timestamps();

            $table->index(['member_id', 'is_active']);
        });

        Schema::create('health_medication_changes', function (Blueprint $table) {
            $this->owner($table);
            $table->foreignUuid('medication_id')->constrained('health_medications')->cascadeOnDelete();
            $table->string('change_type', 20);
            $table->string('old_value', 160)->nullable();
            $table->string('new_value', 160)->nullable();
            $table->text('note')->nullable();
            $table->foreignUuid('changed_by_member_id')->nullable()->constrained('profile_members')->nullOnDelete();
            $table->timestamps();

            $table->index(['medication_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_medication_changes');
        Schema::dropIfExists('health_medications');
        Schema::dropIfExists('health_conditions');
        Schema::dropIfExists('health_allergies');
        Schema::dropIfExists('health_cards');
    }

    private function owner(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
        $table->foreignUuid('member_id')->constrained('profile_members')->cascadeOnDelete();
    }
};
