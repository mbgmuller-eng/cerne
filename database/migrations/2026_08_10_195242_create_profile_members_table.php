<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();

            // Obrigatório quando o membro tem login próprio. Em perfis do tipo
            // `couple` a regra de negócio exige preenchido (ver ProfileType).
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name', 100);
            $table->string('role', 20);
            $table->char('color_hex', 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['profile_id', 'is_active']);

            // Um usuário ocupa no máximo uma cadeira por perfil.
            $table->unique(['profile_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_members');
    }
};
