<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * O "manter conectado" passa de 400 dias (padrão do Laravel) para 30. O cookie que já está nos navegadores foi emitido com validade de 400 dias e o
 * servidor não consegue encurtá-la (o cookie não carrega a data), então todos os tokens de lembrança são zerados uma vez: cada cookie antigo deixa
 * de valer, e quem estava com a sessão aberta segue até ela expirar, quando entra de novo e ganha um cookie de 30 dias.
 *
 * Não mexe em senha, sessão nem segundo passo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNotNull('remember_token')->update(['remember_token' => null]);
    }

    public function down(): void
    {
        // Não há como restaurar tokens zerados, e não faria sentido: seriam os de 400 dias.
    }
};
