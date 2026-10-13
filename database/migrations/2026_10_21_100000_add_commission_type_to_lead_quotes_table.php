<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A comissão da cotação pode ser um percentual do prêmio anual (o que já existia) ou um valor fixo em reais, que é
     * como muita seguradora informa. Cotações antigas ficam como percentual.
     */
    public function up(): void
    {
        Schema::table('lead_quotes', function (Blueprint $table) {
            $table->string('commission_type', 10)->default('percent')->after('commission_percent');
            $table->decimal('commission_amount', 15, 2)->nullable()->after('commission_type');
        });
    }

    public function down(): void
    {
        Schema::table('lead_quotes', function (Blueprint $table) {
            $table->dropColumn(['commission_type', 'commission_amount']);
        });
    }
};
