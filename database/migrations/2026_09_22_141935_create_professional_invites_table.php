<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('professional_invites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 255);
            $table->string('email', 255);

            // Consultant ou Broker — nunca Admin/Client (checado no service,
            // não no banco: reaproveitar UserRole evita um enum próprio
            // pra só dois valores).
            $table->string('role', 20);

            // Token do link do convite. Guardado com hash: quem ler o banco
            // não consegue aceitar convites alheios.
            $table->string('token', 64)->unique();

            $table->timestamp('expires_at');
            $table->string('status', 20)->default('pending');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index('email');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('professional_invites');
    }
};
