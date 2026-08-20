<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vínculo consultor <-> cliente. É esta tabela que concede ao consultor
     * acesso irrestrito ao perfil do cliente, acima das configurações de
     * privacidade do casal (seção 14 da especificação).
     */
    public function up(): void
    {
        Schema::create('consultant_clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('consultant_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20);
            $table->timestamp('invited_at');
            $table->timestamp('accepted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['consultant_id', 'client_id']);

            // Caminho quente da autorização: "este consultor pode ver este cliente?"
            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultant_clients');
    }
};
