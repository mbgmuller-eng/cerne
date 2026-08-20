<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('income_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Nulo = categoria padrão do sistema, compartilhada por todos os
            // perfis. É por isso que esta tabela NÃO usa o escopo de tenancy:
            // a query precisa enxergar as padrão e as do perfil ativo.
            $table->foreignUuid('profile_id')->nullable()->constrained('financial_profiles')->cascadeOnDelete();

            $table->string('name', 100);
            $table->string('icon', 50)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['profile_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('income_categories');
    }
};
