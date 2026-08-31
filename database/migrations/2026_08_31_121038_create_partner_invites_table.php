<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Convite de cônjuge — quem já tem conta (titular OU consultor vinculado)
 * convida a segunda pessoa do casal. Mesma forma de ConsultantInvite
 * (token com hash, expira, aceite vira usuário), mas parte de um perfil
 * que já existe, em vez de criar um novo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_invites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();

            // Quem mandou o convite — titular ou consultor vinculado, os
            // dois podem (ver FinancialProfilePolicy::invitePartner).
            $table->foreignUuid('invited_by_user_id')->constrained('users');

            $table->string('partner_name', 255);
            $table->string('partner_email', 255);

            $table->string('token', 64)->unique();

            $table->timestamp('expires_at');
            $table->string('status', 20)->default('pending');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'status']);
            $table->index('partner_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_invites');
    }
};
