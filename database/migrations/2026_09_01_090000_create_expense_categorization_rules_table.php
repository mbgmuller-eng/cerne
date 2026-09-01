<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Regra "quando a descrição contém X, categoria é Y" aplicada na
     * revisão de um extrato/fatura importado. `fixed_bill_id` é opcional:
     * quando preenchido, a importação também tenta casar o item com uma
     * ocorrência pendente daquela conta fixa (ver CategorizationRuleMatcher).
     */
    public function up(): void
    {
        Schema::create('expense_categorization_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();

            $table->string('pattern', 255);
            $table->foreignUuid('category_id')->constrained('expense_categories')->cascadeOnDelete();
            $table->foreignUuid('subcategory_id')->nullable()->constrained('expense_subcategories')->nullOnDelete();
            $table->string('necessity', 20);
            $table->foreignUuid('fixed_bill_id')->nullable()->constrained('fixed_bills')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['profile_id', 'pattern']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categorization_rules');
    }
};
