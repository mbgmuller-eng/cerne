<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uma apólice, no máximo um corretor — nulo é o padrão (ninguém além
     * de dono/cônjuge/consultor vê). Quem tem acesso de verdade é decidido
     * aqui, linha a linha, não por uma categoria genérica no vínculo
     * consultor↔cliente: cada apólice escolhe se aparece pra um corretor
     * específico, e pode mudar de ideia depois (ver InsuranceIndex).
     */
    public function up(): void
    {
        Schema::table('insurance_policies', function (Blueprint $table) {
            $table->foreignUuid('broker_id')->nullable()->after('member_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('insurance_policies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('broker_id');
        });
    }
};
