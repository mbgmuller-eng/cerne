<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Renovação por vontade do profissional: a apólice pode renovar sozinha (auto_renews) e cada profissional escolhe
     * quais tipos de seguro geram lead de renovação e com quantos dias de antecedência. Nulo nas duas colunas do
     * usuário = padrão do Cerne (todos os tipos menos vida, 45 dias).
     */
    public function up(): void
    {
        Schema::table('insurance_policies', function (Blueprint $table) {
            $table->boolean('auto_renews')->default(false)->after('expiry_date');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('renewal_lead_types')->nullable();
            $table->unsignedSmallInteger('renewal_lead_days')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['renewal_lead_types', 'renewal_lead_days']);
        });

        Schema::table('insurance_policies', function (Blueprint $table) {
            $table->dropColumn('auto_renews');
        });
    }
};