<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            // Quantas pessoas acompanhadas já estão no valor que a Asaas cobra desta assinatura. A rotina diária compara com a contagem de
            // hoje e corrige a diferença, então uma falha ao avisar a Asaas na hora de cadastrar ou apagar a pessoa se corrige sozinha.
            $table->unsignedSmallInteger('care_seats_billed')->default(0)->after('client_cap');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('care_seats_billed');
        });
    }
};
