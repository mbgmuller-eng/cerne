<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Privacidade granular do casal (seção 3 da especificação).
     *
     * Cada domínio tem visibilidade própria: o casal pode compartilhar
     * despesas e esconder investimentos, por exemplo. O consultor vinculado
     * ignora tudo isto — ver ProfilePolicy.
     */
    public function up(): void
    {
        Schema::create('profile_access_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Uma configuração por perfil.
            $table->foreignUuid('profile_id')->unique()->constrained('financial_profiles')->cascadeOnDelete();

            foreach ([
                'expense_visibility',
                'income_visibility',
                'investment_visibility',
                'bank_account_visibility',
                'credit_card_visibility',
                'insurance_visibility',
            ] as $column) {
                $table->string($column, 20)->default('all_members');
            }

            $table->boolean('can_edit_partner_records')->default(true);

            $table->foreignUuid('updated_by_user_id')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_access_settings');
    }
};
