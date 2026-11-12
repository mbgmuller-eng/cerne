<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Segunda leva de campos cifrados (revisão de segurança 2026-11): telefone, dados de conta bancária, notas e textos do acompanhamento,
 * e a lista de lançamentos que a leitura por IA extrai (JSON). O texto cifrado ocupa bem mais que o original, então as colunas passam a
 * TEXT; o JSON vira LONGTEXT. O conteúdo continua como está até `cerne:encrypt-sensitive` cifrá-lo (a leitura aceita os dois).
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    public const TEXT = [
        'users' => ['phone'],
        'health_care_items' => ['name', 'device_name', 'notes'],
        'health_appointments' => ['location', 'address', 'phone', 'booked_by_name', 'booked_with_name'],
        'bank_accounts' => ['agency', 'account_number', 'notes'],
        'debts' => ['creditor_document', 'notes'],
        'insurance_policies' => ['insured_person_name', 'notes'],
        'engagement_notes' => ['body'],
        'engagement_meetings' => ['location', 'agenda', 'prep_notes', 'summary'],
        'engagement_tasks' => ['description'],
        'engagement_requests' => ['body'],
        'engagement_request_comments' => ['body'],
        'consultant_clients' => ['notes'],
        'leads' => ['phone', 'notes'],
    ];

    /** @var array<string, list<string>> colunas JSON que viram texto longo */
    public const JSON = [
        'document_uploads' => ['extraction_summary'],
        'lead_attachments' => ['extraction_summary'],
    ];

    public function up(): void
    {
        foreach (self::TEXT as $tabela => $colunas) {
            $this->alterar($tabela, $colunas, longo: false);
        }

        foreach (self::JSON as $tabela => $colunas) {
            $this->alterar($tabela, $colunas, longo: true);
        }
    }

    public function down(): void
    {
        // Voltar a string curta ou a JSON truncaria o texto cifrado: não se desfaz.
    }

    /** @param  list<string>  $colunas */
    private function alterar(string $tabela, array $colunas, bool $longo): void
    {
        $existentes = collect(Schema::getColumns($tabela))->keyBy('name');

        Schema::table($tabela, function (Blueprint $table) use ($colunas, $existentes, $longo): void {
            foreach ($colunas as $coluna) {
                if (! isset($existentes[$coluna])) {
                    continue;
                }

                $anulavel = (bool) ($existentes[$coluna]['nullable'] ?? true);
                $longo ? $table->longText($coluna)->nullable($anulavel)->change() : $table->text($coluna)->nullable($anulavel)->change();
            }
        });
    }
};
