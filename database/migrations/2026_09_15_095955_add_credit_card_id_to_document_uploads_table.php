<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_uploads', function (Blueprint $table) {
            // Sem isto, importar "Fatura de cartão" não tinha como saber
            // qual cartão vincular — os lançamentos criados ficavam soltos,
            // sem atualizar o total de nenhuma fatura real.
            $table->foreignUuid('credit_card_id')->nullable()->after('bank_account_id')
                ->constrained('credit_cards')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_uploads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('credit_card_id');
        });
    }
};
