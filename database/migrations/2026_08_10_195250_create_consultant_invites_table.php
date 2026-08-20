<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultant_invites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('consultant_id')->constrained('users')->cascadeOnDelete();
            $table->string('client_name', 255);
            $table->string('client_email', 255);

            // Token do link do convite. Guardado com hash: quem ler o banco
            // não consegue aceitar convites alheios.
            $table->string('token', 64)->unique();

            $table->timestamp('expires_at');
            $table->string('status', 20)->default('pending');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['consultant_id', 'status']);
            $table->index('client_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultant_invites');
    }
};
