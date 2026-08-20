<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('profile_name', 100);
            $table->string('profile_type', 20);
            $table->char('base_currency', 3)->default('BRL');

            // Mês de referência do orçamento (1–12) — permite perfis cujo
            // ciclo financeiro não começa em janeiro.
            $table->unsignedTinyInteger('reference_month')->default(1);

            $table->timestamps();

            $table->index('owner_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_profiles');
    }
};
