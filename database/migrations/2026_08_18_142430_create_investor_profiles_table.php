<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investor_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained('profile_members')->cascadeOnDelete();

            $table->string('investor_type', 30);

            // Base do cálculo da reserva de emergência:
            // meta = custo mensal médio x meses de reserva.
            $table->decimal('monthly_cost_average', 15, 2)->nullable();
            $table->unsignedTinyInteger('months_reserve_target')->nullable();

            $table->timestamps();

            // Um perfil de investidor por membro.
            $table->unique(['profile_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_profiles');
    }
};
