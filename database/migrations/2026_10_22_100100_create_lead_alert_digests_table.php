<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Resumo diário de acompanhamento de leads já enviado a cada profissional. Existe para sustentar o índice único
     * (profissional, dia): o cron da hospedagem compartilhada pode disparar duas vezes, e a proteção contra aviso em
     * dobro tem de ser do banco, não um "se já existe" que perde a corrida.
     */
    public function up(): void
    {
        Schema::create('lead_alert_digests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('consultant_id')->constrained('users')->cascadeOnDelete();
            $table->date('sent_on');
            $table->unsignedSmallInteger('quotes_expiring')->default(0);
            $table->unsignedSmallInteger('stale_leads')->default(0);
            $table->unsignedSmallInteger('overdue_actions')->default(0);
            $table->timestamps();

            $table->unique(['consultant_id', 'sent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_alert_digests');
    }
};
