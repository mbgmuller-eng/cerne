<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Um lead existe ANTES de qualquer perfil financeiro — mesmo raciocínio
     * de consultant_invites (ver TenancyCoverageTest::EXEMPT): não tem
     * profile_id porque ainda não há perfil nenhum, só um contato do
     * consultor. Vira cliente de verdade convertendo pro fluxo que já
     * existe (ClientInviteService::send()), não duplicando a lógica de
     * convite/cadastro aqui.
     */
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('consultant_id')->constrained('users')->cascadeOnDelete();

            $table->string('name', 255);
            $table->string('email', 255)->nullable();
            $table->string('phone', 30)->nullable();

            $table->string('stage', 20)->default('new_contact');
            $table->text('notes')->nullable();
            $table->string('lost_reason', 255)->nullable();

            // Próximo follow-up marcado — a base do "não deixar esfriar".
            $table->timestamp('next_action_at')->nullable();

            $table->timestamps();

            $table->index(['consultant_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
