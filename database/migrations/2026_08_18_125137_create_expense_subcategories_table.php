<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Regra de "sem Outros": nenhuma categoria tem subcategoria fallback
     * travada. Quando falta uma, o usuário cria na hora — o registro nasce
     * com is_customizada = true e vinculado ao profile_id.
     *
     * A especificação lista `is_default` E `is_customizada`, que são o
     * inverso um do outro. Aqui fica só `is_customizada`; o padrão é
     * simplesmente a ausência dela.
     */
    public function up(): void
    {
        Schema::create('expense_subcategories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('category_id')->constrained('expense_categories')->cascadeOnDelete();
            $table->foreignUuid('profile_id')->nullable()->constrained('financial_profiles')->cascadeOnDelete();
            $table->string('name', 100);
            $table->boolean('is_customizada')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['category_id', 'is_active']);
            $table->index('profile_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_subcategories');
    }
};
