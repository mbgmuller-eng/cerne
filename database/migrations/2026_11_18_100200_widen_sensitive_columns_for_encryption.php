<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O texto cifrado ocupa bem mais que o original (base64 de IV + dado + autenticação): as colunas dos campos sensíveis passam a TEXT.
 * O conteúdo continua como está até `cerne:encrypt-sensitive` cifrá-lo (a leitura aceita os dois, ver App\Casts\Sensitive).
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    public const COLUMNS = [
        'users' => ['cpf_cnpj'],
        'billing_details' => ['document'],
        'health_allergies' => ['description'],
        'health_conditions' => ['description'],
        'health_medications' => ['name', 'dose', 'schedule', 'reason', 'prescriber'],
        'health_appointments' => ['title', 'professional_name', 'specialty', 'notes'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $tabela => $colunas) {
            $existentes = collect(Schema::getColumns($tabela))->keyBy('name');

            Schema::table($tabela, function (Blueprint $table) use ($colunas, $existentes) {
                foreach ($colunas as $coluna) {
                    $anulavel = (bool) ($existentes[$coluna]['nullable'] ?? true);
                    $table->text($coluna)->nullable($anulavel)->change();
                }
            });
        }
    }

    public function down(): void
    {
        // Voltar a string curta truncaria o texto cifrado: não se desfaz.
    }
};
