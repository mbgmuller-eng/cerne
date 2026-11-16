<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Confiar neste navegador": quem tem verificação em dois passos pode dispensar o CÓDIGO (nunca a senha) naquele navegador por um prazo curto.
 * O navegador guarda um token aleatório num cookie; aqui fica só o HASH dele (vazar o banco não entrega um cookie válido). O prazo é absoluto
 * (`expires_at`, 30 dias; 14 para quem vê dado de outras pessoas), o token troca a cada uso e a pessoa revoga um a um em Minha conta.
 *
 * É da conta, não do perfil: sem `profile_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('label', 120);
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_devices');
    }
};
