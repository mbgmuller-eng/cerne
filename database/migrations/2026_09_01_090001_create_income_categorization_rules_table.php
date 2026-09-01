<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Espelho de expense_categorization_rules do lado da receita — sem
     * subcategoria/necessidade porque IncomeCategory/IncomeRecord não têm
     * esses campos (só a despesa tem essa camada extra na taxonomia).
     */
    public function up(): void
    {
        Schema::create('income_categorization_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();

            $table->string('pattern', 255);
            $table->foreignUuid('category_id')->constrained('income_categories')->cascadeOnDelete();
            $table->foreignUuid('recurring_income_id')->nullable()->constrained('recurring_incomes')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['profile_id', 'pattern']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('income_categorization_rules');
    }
};
