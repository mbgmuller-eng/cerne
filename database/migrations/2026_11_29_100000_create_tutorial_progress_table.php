<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // O que cada pessoa já viu dos tutoriais: uma linha por usuário e tela. O índice único é o que impede duplicata
        // (a tela pode ser aberta em duas abas e o clique grava duas vezes); apagar o usuário apaga as linhas (cascata).
        Schema::create('tutorial_progress', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('user_id', 36);
            $table->string('tutorial_key', 80);
            $table->string('status', 12);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'tutorial_key'], 'tutorial_progress_user_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutorial_progress');
    }
};
