<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Note o que NÃO existe aqui: `necessity_type`.
     *
     * A necessidade (essencial / supérfluo / investimento) vive no
     * LANÇAMENTO, não na categoria — o mesmo jantar pode ser essencial ou
     * supérfluo dependendo do contexto (seção 6 da especificação).
     */
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->nullable()->constrained('financial_profiles')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('icon', 50)->nullable();
            $table->char('color_hex', 7)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['profile_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
