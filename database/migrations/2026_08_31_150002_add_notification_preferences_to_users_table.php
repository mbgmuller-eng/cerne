<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Sino (canal "database") é sempre ligado, não tem coluna. E-mail
            // começa ligado (custo zero pra quem recebe); push começa
            // desligado porque depende de permissão explícita do navegador —
            // ligar a flag sem inscrição não entregaria nada mesmo assim.
            $table->boolean('notify_email_enabled')->default(true)->after('theme');
            $table->boolean('notify_push_enabled')->default(false)->after('notify_email_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['notify_email_enabled', 'notify_push_enabled']);
        });
    }
};
