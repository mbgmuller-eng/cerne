<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Convite para a PRÓPRIA pessoa acompanhada passar a ter login e usar o espaço dela (a mãe que decide usar o app depois de os filhos
 * terem começado). O dado já está no perfil de cuidado; aceitar liga o login dela ao membro que já existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_person_invites', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained('profile_members')->cascadeOnDelete();
            $table->foreignUuid('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email', 190);
            // Hash do token do link (o token em claro só existe no e-mail).
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->string('status', 10)->default('pending');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_person_invites');
    }
};
