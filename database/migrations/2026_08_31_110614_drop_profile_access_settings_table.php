<?php

use App\Enums\Visibility;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A privacidade deixou de ser "uma configuração por casal" — ver
 * 2026_08_31_110613_add_is_private_to_privacy_governed_tables. Sem tela
 * geral nem model consumindo esta tabela, ela vira código morto se
 * continuar existindo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('profile_access_settings');
    }

    public function down(): void
    {
        Schema::create('profile_access_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->unique()->constrained('financial_profiles')->cascadeOnDelete();

            foreach ([
                'expense_visibility',
                'income_visibility',
                'investment_visibility',
                'bank_account_visibility',
                'credit_card_visibility',
                'insurance_visibility',
            ] as $column) {
                $table->string($column, 20)->default(Visibility::AllMembers->value);
            }

            $table->boolean('can_edit_partner_records')->default(true);

            $table->foreignUuid('updated_by_user_id')->constrained('users');
            $table->timestamps();
        });
    }
};
